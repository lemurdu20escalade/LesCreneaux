<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Étiquette « sans référent·e » : le flag se persiste via /label/ajouter, et
// un créneau qui la porte n'affiche ni le chip rouge ni l'alerte « la salle
// n'ouvre pas » — il montre un message neutre. Le témoin (créneau sans
// référent·e mais sans le flag) garde l'alerte.

function runSansReferent(): void
{
    resetEtat();
    $pdo = dbConnect();

    $cookieCsrf = bin2hex(random_bytes(16));

    // 1) Création du label avec le flag via la route publique (admin ouvert
    //    en mode test) → persistance en DB.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/label/ajouter',
        array_merge(['nom' => 'TestAG', 'couleur' => '#7e57c2', 'sans_referent' => '1'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'POST /label/ajouter avec sans_referent → 303');

    $idLabel = (int)$pdo->query("SELECT id FROM labels WHERE nom = 'TestAG'")->fetchColumn();
    $flag    = (int)$pdo->query("SELECT sans_referent FROM labels WHERE nom = 'TestAG'")->fetchColumn();
    ok($idLabel > 0 && $flag === 1, 'Label TestAG persisté avec sans_referent = 1');

    // 2) Créneau portant le label, sans aucun·e référent·e.
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite, note)"
        . " VALUES ('2026-07-09', '19:30', '22:30', 50, 'Note AG')"
    );
    $idAG = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO jour_label (jour_id, label_id) VALUES (?, ?)')
        ->execute([$idAG, $idLabel]);

    $r = http('GET', "/jour/$idAG");
    ok($r['code'] === 200, "GET /jour/$idAG → 200");
    ok(
        str_contains($r['body'], 'Pas de référent·e requis·e'),
        'Drawer AG — message neutre « Pas de référent·e requis·e »'
    );
    ok(
        !str_contains($r['body'], 'Sans référent·e'),
        'Drawer AG — pas de chip ni alerte « Sans référent·e »'
    );

    // 3) Témoin : créneau sans référent·e ET sans le flag → alerte conservée.
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-07-10', '18:00', '22:30', 15)"
    );
    $idTemoin = (int)$pdo->lastInsertId();

    $r = http('GET', "/jour/$idTemoin");
    ok(
        str_contains($r['body'], 'Sans référent·e'),
        'Drawer témoin — alerte « Sans référent·e » conservée'
    );

    // Nettoyage. La connexion du harnais n'active pas PRAGMA foreign_keys
    // (contrairement à Database::connect), donc le ON DELETE CASCADE ne joue
    // pas : on purge explicitement jour_label avant les jours et le label,
    // sinon des liaisons orphelines subsistent dans la base de test.
    unset($pdo);
    $clean = dbConnect();
    $clean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $clean->prepare('DELETE FROM jour_label WHERE jour_id IN (?, ?)')->execute([$idAG, $idTemoin]);
    $clean->prepare('DELETE FROM jours WHERE id IN (?, ?)')->execute([$idAG, $idTemoin]);
    $clean->prepare('DELETE FROM labels WHERE id = ?')->execute([$idLabel]);
}
