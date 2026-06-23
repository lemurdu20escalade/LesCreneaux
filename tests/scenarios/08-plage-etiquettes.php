<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Étiquettes par plage : POST /plage/etiquettes ajoute / retire des labels
// sur les créneaux d'une période, avec filtre jour-semaine. L'admin est
// ouvert en test (pas de login). Vérification directe via la table jour_label.

function runPlageEtiquettes(): void
{
    resetEtat();
    $pdo = dbConnect();

    // Lundi, mercredi, vendredi de la même semaine de septembre 2026.
    $dates = ['2026-09-07', '2026-09-09', '2026-09-11']; // lun, mer, ven
    $ids   = [];
    foreach ($dates as $d) {
        $pdo->prepare('DELETE FROM jours WHERE date = ?')->execute([$d]);
        $pdo->prepare(
            "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
            . " VALUES (?, '10:00', '12:00', 15)"
        )->execute([$d]);
        $ids[$d] = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO labels (nom, couleur) VALUES ('TestPlage', '#123456')")->execute();
    $labelId = (int)$pdo->lastInsertId();

    $cookieCsrf = bin2hex(random_bytes(16));
    $tokens     = csrfTokens($cookieCsrf);

    // 1. Ajouter le label, filtré sur lundi (1) et mercredi (3).
    $r = http('POST', '/plage/etiquettes', array_merge([
        'debut'         => '2026-09-07',
        'fin'           => '2026-09-13',
        'jours_semaine' => ['1', '3'],
        'action'        => [(string)$labelId => 'ajouter'],
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 303, 'POST /plage/etiquettes (ajouter) → 303');
    ok(aLabelPlage($pdo, $ids['2026-09-07'], $labelId), 'Lundi a le label');
    ok(aLabelPlage($pdo, $ids['2026-09-09'], $labelId), 'Mercredi a le label');
    ok(!aLabelPlage($pdo, $ids['2026-09-11'], $labelId), 'Vendredi (hors filtre) n’a pas le label');

    $opRow = $pdo->query(
        'SELECT debut, fin, nb_creneaux, labels_ajoutes FROM plage_operations ORDER BY id DESC LIMIT 1'
    )->fetch();
    ok(
        $opRow && $opRow['debut'] === '2026-09-07' && (int)$opRow['nb_creneaux'] === 2,
        'Opération enregistrée dans l’historique (2 créneaux)'
    );
    ok($opRow && $opRow['labels_ajoutes'] === (string)$labelId, 'Historique : label ajouté tracé');

    // 2. Idempotence de l'ajout : rejouer ne casse rien.
    $r = http('POST', '/plage/etiquettes', array_merge([
        'debut'         => '2026-09-07',
        'fin'           => '2026-09-13',
        'jours_semaine' => ['1', '3'],
        'action'        => [(string)$labelId => 'ajouter'],
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 303 && aLabelPlage($pdo, $ids['2026-09-07'], $labelId), 'Ré-ajout idempotent');

    // 3. Footgun : zéro jour coché ne doit RIEN toucher (et surtout pas tout).
    $r = http('POST', '/plage/etiquettes', array_merge([
        'debut'  => '2026-09-07',
        'fin'    => '2026-09-13',
        // pas de jours_semaine[] → aucun jour sélectionné
        'action' => [(string)$labelId => 'retirer'],
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 303, 'Zéro jour coché → 303 (no-op)');
    ok(aLabelPlage($pdo, $ids['2026-09-07'], $labelId), 'Lundi garde le label (rien retiré sans jour coché)');

    // 4. Retirer le label sur toute la plage (tous les jours cochés).
    $r = http('POST', '/plage/etiquettes', array_merge([
        'debut'         => '2026-09-07',
        'fin'           => '2026-09-13',
        'jours_semaine' => ['1', '2', '3', '4', '5', '6', '7'],
        'action'        => [(string)$labelId => 'retirer'],
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 303, 'POST /plage/etiquettes (retirer) → 303');
    ok(!aLabelPlage($pdo, $ids['2026-09-07'], $labelId), 'Lundi n’a plus le label après retrait');
    ok(!aLabelPlage($pdo, $ids['2026-09-09'], $labelId), 'Mercredi n’a plus le label après retrait');

    // 5. Plage absurde → refus 400.
    $r = http('POST', '/plage/etiquettes', array_merge([
        'debut'  => '2026-01-01',
        'fin'    => '2027-12-31',
        'action' => [(string)$labelId => 'ajouter'],
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 400, 'Plage > 366 jours → 400');

    // 6. Étiquette bloquante posée → /inscrire doit refuser côté serveur
    //    (le masquage UI ne suffit pas contre un POST direct).
    $clean = dbConnect();
    $clean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $clean->exec("INSERT INTO labels (nom, couleur, bloque_inscriptions) VALUES ('TestBloque', '#000000', 1)");
    $bloqueId = (int)$clean->lastInsertId();
    $clean->prepare('DELETE FROM jours WHERE date = ?')->execute(['2026-09-20']);
    $clean->exec("INSERT INTO jours (date, heure_debut, heure_fin, capacite) VALUES ('2026-09-20', '14:00', '18:00', 15)");
    $jourBloqueId = (int)$clean->lastInsertId();
    $clean->prepare('INSERT INTO jour_label (jour_id, label_id) VALUES (?, ?)')->execute([$jourBloqueId, $bloqueId]);

    $r = http('POST', "/jour/$jourBloqueId/inscrire", array_merge([
        'nom' => 'TestBloqueInscrit',
    ], $tokens), ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 403, 'Inscription sur créneau bloqué → 403');
    $nbIns = (int)$clean->query("SELECT COUNT(*) FROM inscriptions WHERE jour_id = $jourBloqueId")->fetchColumn();
    ok($nbIns === 0, 'Aucune inscription créée sur le créneau bloqué');

    foreach ($ids as $id) {
        $clean->prepare('DELETE FROM jours WHERE id = ?')->execute([$id]);
    }
    $clean->prepare('DELETE FROM jours WHERE id = ?')->execute([$jourBloqueId]);
    $clean->prepare('DELETE FROM labels WHERE id IN (?, ?)')->execute([$labelId, $bloqueId]);
    $clean->exec("DELETE FROM plage_operations WHERE debut = '2026-09-07'");
}

function aLabelPlage(PDO $pdo, int $jourId, int $labelId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM jour_label WHERE jour_id = ? AND label_id = ?');
    $stmt->execute([$jourId, $labelId]);
    return (bool)$stmt->fetchColumn();
}
