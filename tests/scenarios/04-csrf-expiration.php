<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Expiration du timestamp CSRF : vérifie les bornes haute (MAX_AGE_S = 7200s)
// et basse (2s, anti-bot), plus le rejet des ts futurs.

function runCsrfExpiration(): void
{
    global $secretCsrf;

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-07-01', '18:00', '22:30', 100)"
    );
    $idJour = (int)$pdo->lastInsertId();
    unset($pdo);

    $cookieCsrf = bin2hex(random_bytes(16));
    $csrf       = hash_hmac('sha256', $cookieCsrf, $secretCsrf);

    $fabriquerTokens = function (int $ts) use ($csrf, $secretCsrf): array {
        return [
            '_csrf'    => $csrf,
            '_ts'      => $ts,
            '_ts_sig'  => hash_hmac('sha256', (string)$ts, $secretCsrf),
            'website'  => '',
        ];
    };

    // Cas 1 : ts age 1s — sous la borne basse (2s) → 400.
    $tokens = $fabriquerTokens(time() - 1);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp1'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, 'CSRF expiration — ts age 1s (trop frais) → 400');

    // Cas 2 : ts age 3s — dans la fenêtre → 303.
    $tokens = $fabriquerTokens(time() - 3);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp2'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'CSRF expiration — ts age 3s (valide) → 303');
    resetEtat();

    // Cas 3 : ts age 1h59 (7140s) — dans la fenêtre → 303.
    $tokens = $fabriquerTokens(time() - 7140);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp3'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'CSRF expiration — ts age 1h59 (valide) → 303');
    resetEtat();

    // Cas 4 : ts age 2h01 (7260s) — dépasse MAX_AGE_S (7200) → 400.
    $tokens = $fabriquerTokens(time() - 7260);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp4'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, 'CSRF expiration — ts age 2h01 (expiré) → 400');

    // Cas 5 : ts age 1 jour (86400s) — largement expiré → 400.
    $tokens = $fabriquerTokens(time() - 86400);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp5'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, 'CSRF expiration — ts age 1 jour (expiré) → 400');

    // Cas 6 : ts dans le futur (+60s) — age négatif → 400.
    $tokens = $fabriquerTokens(time() + 60);
    $r = http(
        'POST',
        "/jour/$idJour/inscrire",
        array_merge(['nom' => 'TestExp6'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, 'CSRF expiration — ts futur +60s (invalide) → 400');

    resetEtat();
    unset($r, $tokens);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour]);
    unset($pdoClean);
}
