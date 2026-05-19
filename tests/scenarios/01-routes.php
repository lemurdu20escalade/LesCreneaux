<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Routes publiques : redirect racine, page mois, 404 stylé, headers de
// sécurité, ETag/304. Aucun effet de bord sur la DB.

function runRoutesPubliques(): void
{
    $mois = '/mois/2026-05';

    $r = http('GET', '/');
    ok($r['code'] === 303,                              'GET / → 303');
    ok(str_contains($r['headers']['location'] ?? '', '/mois/'), 'GET / → location contient /mois/');

    $r = http('GET', $mois);
    ok($r['code'] === 200,                              "GET $mois → 200");
    ok(str_contains($r['body'], '<!doctype html'),      "GET $mois → body contient <!doctype html");

    $r = http('GET', '/mois/abc');
    ok($r['code'] === 404,                              'GET /mois/abc → 404');

    $r = http('GET', '/jour/1');
    ok($r['code'] === 404,                              'GET /jour/1 → 404');

    $r = http('GET', '/licence');
    ok($r['code'] === 200,                              'GET /licence → 200');
    ok(str_contains($r['body'], 'AGPL'),                'GET /licence → body contient AGPL');

    $r = http('GET', '/pas-existe');
    ok($r['code'] === 404,                              'GET /pas-existe → 404');
    ok(str_contains($r['body'], '<!doctype html'),      'GET /pas-existe → 404 avec layout HTML');

    $r = http('GET', $mois);
    ok(
        strtolower($r['headers']['x-frame-options'] ?? '') === 'deny',
        "GET $mois → x-frame-options: DENY"
    );
    ok(
        strtolower($r['headers']['x-content-type-options'] ?? '') === 'nosniff',
        "GET $mois → x-content-type-options: nosniff"
    );
    ok(
        str_contains($r['headers']['content-security-policy'] ?? '', 'default-src'),
        "GET $mois → CSP contient default-src"
    );

    $r1   = http('GET', $mois);
    $etag = $r1['headers']['etag'] ?? '';
    ok($etag !== '', "GET $mois → etag présent");

    $r2 = http('GET', $mois, [], [], ['If-None-Match' => $etag]);
    ok($r2['code'] === 304, "GET $mois avec If-None-Match → 304");
}
