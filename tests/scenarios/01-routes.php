<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Routes publiques : racine = mois courant, page mois, bannière mois
// passé, 404 stylé, headers de sécurité, ETag/304. Aucun effet de bord
// sur la DB.

function runRoutesPubliques(): void
{
    $mois = '/mois/2026-05';

    // La racine rend le mois courant sans redirect : l'URL partagée
    // reste `/` et ne gèle pas un mois dans le lien.
    $moisCourant = (new DateTimeImmutable('now'))->format('Y-m');
    $r = http('GET', '/');
    ok($r['code'] === 200,                              'GET / → 200 (pas de redirect)');
    ok(str_contains($r['body'], '<!doctype html'),      'GET / → body contient <!doctype html');
    ok(str_contains($r['body'], $moisCourant),          'GET / → affiche le mois courant');
    ok(!str_contains($r['body'], 'bandeau-mois-passe'), 'GET / → pas de bannière mois passé');

    $r = http('GET', $mois);
    ok($r['code'] === 200,                              "GET $mois → 200");
    ok(str_contains($r['body'], '<!doctype html'),      "GET $mois → body contient <!doctype html");

    // Un mois antérieur au mois courant affiche la bannière de retour.
    $moisPasse = (new DateTimeImmutable('first day of this month'))
        ->modify('-1 month')->format('Y-m');
    $r = http('GET', '/mois/' . $moisPasse);
    ok(str_contains($r['body'], 'bandeau-mois-passe'),  "GET /mois/$moisPasse → bannière mois passé");

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
