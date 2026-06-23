<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Inscription sur un créneau « Salle fermée » : le masquage du formulaire est
// côté UI uniquement. Un POST direct/forgé doit être bloqué à la frontière
// serveur (403) et ne rien écrire. Un créneau sans étiquette bloquante reste
// inscriptible (non-régression : on ne touche pas au flux normal).

function runInscriptionBloquee(): void
{
    resetEtat();
    $pdo = dbConnect();

    $pdo->exec("INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-07-10', '18:00', '22:30', 100)");
    $jourFerme = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-07-11', '18:00', '22:30', 100)");
    $jourOuvert = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO labels (nom, couleur, bloque_inscriptions, ouvre_voisines)"
        . " VALUES ('TestSalleFermee', '#b71c1c', 1, 0)");
    $labelBloquant = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO jour_label (jour_id, label_id) VALUES (?, ?)')
        ->execute([$jourFerme, $labelBloquant]);

    $cookieCsrf = bin2hex(random_bytes(16));

    // POST forgé sur le créneau fermé → 403, aucune inscription.
    $tokens = csrfTokens($cookieCsrf);
    $r = http('POST', "/jour/$jourFerme/inscrire",
        array_merge(['nom' => 'TestForce'], $tokens),
        ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 403, "POST /jour/$jourFerme/inscrire (Salle fermée) → 403");

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscriptions WHERE jour_id = ?');
    $stmt->execute([$jourFerme]);
    ok((int)$stmt->fetchColumn() === 0, 'aucune inscription créée sur le créneau fermé');

    // Non-régression : un créneau sans étiquette bloquante reste inscriptible.
    $tokens = csrfTokens($cookieCsrf);
    $r = http('POST', "/jour/$jourOuvert/inscrire",
        array_merge(['nom' => 'TestOuvert'], $tokens),
        ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 303, "POST /jour/$jourOuvert/inscrire (créneau ouvert) → 303");

    resetEtat();
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jour_label WHERE label_id = ?')->execute([$labelBloquant]);
    $pdoClean->prepare('DELETE FROM labels WHERE id = ?')->execute([$labelBloquant]);
    $pdoClean->prepare('DELETE FROM jours WHERE id IN (?, ?)')->execute([$jourFerme, $jourOuvert]);
}
