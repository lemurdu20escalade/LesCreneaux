<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Inscription / désinscription : validation des champs, ownership cross-jour
// (B3). Le test cross-jour vérifie qu'une inscription_id valide sur le jour 2
// ne peut pas être supprimée via l'URL du jour 1.

function runInscriptions(): void
{
    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-05-15', '18:00', '22:30', 100)"
    );
    $id1 = (int)$pdo->lastInsertId();

    // Cookie CSRF unique pour tout le scénario.
    $cookieCsrf = bin2hex(random_bytes(16));

    // Sans champs CSRF → 400.
    $r = http('POST', "/jour/$id1/inscrire", ['nom' => 'TestAlice'], ['csrf_session' => $cookieCsrf]);
    ok($r['code'] === 400, "POST /jour/$id1/inscrire sans CSRF → 400");

    // Inscription nominale Alice → 303.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id1/inscrire",
        array_merge(['nom' => 'TestAlice'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, "POST /jour/$id1/inscrire Alice → 303");

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscriptions WHERE nom = ? AND jour_id = ?');
    $stmt->execute(['TestAlice', $id1]);
    ok((int)$stmt->fetchColumn() === 1, "TestAlice en DB après inscription");

    // Nom vide → 400.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id1/inscrire",
        array_merge(['nom' => ''], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, "POST /jour/$id1/inscrire nom vide → 400");

    // Nom de 41 chars → 400.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id1/inscrire",
        array_merge(['nom' => str_repeat('x', 41)], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, "POST /jour/$id1/inscrire nom 41 chars → 400");

    // Note de 81 chars → 400.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id1/inscrire",
        array_merge(['nom' => 'TestNote', 'note' => str_repeat('n', 81)], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, "POST /jour/$id1/inscrire note 81 chars → 400");

    // Jour inexistant → 404.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/jour/99999999/inscrire',
        array_merge(['nom' => 'TestX'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 404, 'POST /jour/99999999/inscrire → 404');

    // --- Test cross-jour (B3) ---

    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-05-16', '18:00', '22:30', 100)"
    );
    $id2 = (int)$pdo->lastInsertId();

    // Inscrire Bob sur le jour 2.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id2/inscrire",
        array_merge(['nom' => 'TestBob'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, "POST /jour/$id2/inscrire Bob → 303");

    $stmt = $pdo->prepare('SELECT id FROM inscriptions WHERE nom = ? AND jour_id = ?');
    $stmt->execute(['TestBob', $id2]);
    $idBob = (int)$stmt->fetchColumn();
    ok($idBob > 0, 'inscription_id Bob récupéré');

    // Désinscrire Bob via URL du jour 1 (cross-jour) → 404.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id1/desinscrire",
        array_merge(['inscription_id' => $idBob], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 404, "POST /jour/$id1/desinscrire avec id Bob (cross-jour) → 404");

    // Bob toujours en DB.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscriptions WHERE nom = ? AND jour_id = ?');
    $stmt->execute(['TestBob', $id2]);
    ok((int)$stmt->fetchColumn() === 1, 'Bob toujours présent après tentative cross-jour');

    // Désinscription correcte Bob sur le jour 2 → 303.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id2/desinscrire",
        array_merge(['inscription_id' => $idBob], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, "POST /jour/$id2/desinscrire Bob → 303");

    // Bob supprimé.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inscriptions WHERE nom = ? AND jour_id = ?');
    $stmt->execute(['TestBob', $id2]);
    ok((int)$stmt->fetchColumn() === 0, 'Bob absent après désinscription correcte');

    resetEtat();

    // Fermer la connexion de travail avant le DELETE pour libérer tout lock
    // local, puis rouvrir avec busy_timeout pour attendre que le serveur
    // builtin relâche son propre verrou SQLite.
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id IN (?, ?)')
        ->execute([$id1, $id2]);
}
