<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Authentification admin : mode compat (ADMIN_PASSWORD_HASH vide), mode actif,
// open-redirect bouché, rate-limit /admin/login, révocation par rotation du
// hash. Nécessite redemarrerServeur() pour basculer la config.

function runAdminAuth(): void
{
    // --- Cas 1 : mode compat (ADMIN_PASSWORD_HASH = '') ---

    resetEtat();

    $r = http('GET', '/reglages');
    ok($r['code'] === 200, 'Auth compat — GET /reglages → 200');

    $r = http('GET', '/admin/login');
    ok($r['code'] === 404, 'Auth compat — GET /admin/login → 404');

    $r = http('GET', '/reglages');
    ok(
        str_contains($r['body'], "Aucun mot de passe admin n'est configuré."),
        "Auth compat — /reglages contient le bandeau avertissement"
    );

    // --- Cas 2 : mode actif ---

    $hash = password_hash('lemur', PASSWORD_DEFAULT);
    redemarrerServeur($hash);

    $r = http('GET', '/reglages');
    ok($r['code'] === 303, 'Auth actif — GET /reglages sans cookie → 303');
    ok(
        str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth actif — location commence par /admin/login'
    );

    $r = http('GET', '/admin/login');
    ok($r['code'] === 200, 'Auth actif — GET /admin/login → 200');
    ok(
        str_contains($r['body'], 'Connexion admin'),
        'Auth actif — body contient "Connexion admin"'
    );

    $cookieCsrf = bin2hex(random_bytes(16));

    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'mauvais'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 200, 'Auth actif — POST login mauvais password → 200');
    ok(
        str_contains($r['body'], 'Mot de passe incorrect.'),
        'Auth actif — body contient "Mot de passe incorrect."'
    );

    // Open-redirect bouché — /\evil.com
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur', 'retour' => '/\\evil.com'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST login retour=/\\evil.com → 303');
    ok(
        ($r['headers']['location'] ?? '') === '/reglages',
        'Auth actif — open-redirect /\\evil.com bouché → location=/reglages'
    );

    // Open-redirect bouché — //evil.com
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur', 'retour' => '//evil.com'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST login retour=//evil.com → 303');
    ok(
        ($r['headers']['location'] ?? '') === '/reglages',
        'Auth actif — open-redirect //evil.com bouché → location=/reglages'
    );

    // Login OK avec retour=/reglages
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur', 'retour' => '/reglages'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST login OK → 303');
    ok(
        ($r['headers']['location'] ?? '') === '/reglages',
        'Auth actif — POST login OK → location=/reglages'
    );
    $cookieAdminSession = $r['setCookies']['admin_session'] ?? '';
    ok($cookieAdminSession !== '', 'Auth actif — cookie admin_session présent après login OK');

    $r = http('GET', '/reglages', [], ['admin_session' => $cookieAdminSession]);
    ok($r['code'] === 200, 'Auth actif — GET /reglages avec cookie valide → 200');

    $r = http('GET', '/admin/logout', [], ['admin_session' => $cookieAdminSession]);
    ok($r['code'] === 303, 'Auth actif — GET /admin/logout → 303');
    ok(($r['headers']['location'] ?? '') === '/', 'Auth actif — logout redirect → /');

    $r = http('GET', '/reglages');
    ok($r['code'] === 303, 'Auth actif — GET /reglages SANS cookie après logout → 303');

    // --- Cas 3 : rate-limit /admin/login (10 échecs + 11ème bloqué) ---

    resetEtat();

    $cookieCsrfRl = bin2hex(random_bytes(16));
    for ($i = 1; $i <= 10; $i++) {
        $tokens = csrfTokens($cookieCsrfRl);
        $r = http(
            'POST',
            '/admin/login',
            array_merge(['password' => 'mauvais'], $tokens),
            ['csrf_session' => $cookieCsrfRl]
        );
        ok($r['code'] === 200, "Auth rate-limit — POST login KO $i/10 → 200");
    }

    $tokens = csrfTokens($cookieCsrfRl);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'mauvais'], $tokens),
        ['csrf_session' => $cookieCsrfRl]
    );
    ok($r['code'] === 429, 'Auth rate-limit — POST login KO 11 → 429');

    resetEtat();

    // --- Cas 4 : révocation par rotation du hash ---

    // Login avec 'lemur' (hash actuel).
    $cookieCsrfRev = bin2hex(random_bytes(16));
    $tokens = csrfTokens($cookieCsrfRev);
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur', 'retour' => '/reglages'], $tokens),
        ['csrf_session' => $cookieCsrfRev]
    );
    $cookieAncien = $r['setCookies']['admin_session'] ?? '';
    ok($cookieAncien !== '', 'Auth révocation — login OK, cookie ancien récupéré');

    $r = http('GET', '/reglages', [], ['admin_session' => $cookieAncien]);
    ok($r['code'] === 200, 'Auth révocation — GET /reglages avec cookie ancien → 200');

    $hashNouveau = password_hash('NOUVEAU', PASSWORD_DEFAULT);
    redemarrerServeur($hashNouveau);

    $r = http('GET', '/reglages', [], ['admin_session' => $cookieAncien]);
    ok(
        $r['code'] === 303,
        'Auth révocation — GET /reglages avec ancien cookie après rotation hash → 303'
    );
    ok(
        str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth révocation — redirect vers /admin/login après rotation hash'
    );

    restaurerConfigInitiale();
}
