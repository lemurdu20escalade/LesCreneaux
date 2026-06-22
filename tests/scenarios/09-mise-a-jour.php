<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Notification de mise à jour : le bandeau s'affiche sur /reglages quand le
// cache (maj-state.json) annonce une version > Version::APP, jamais sinon ni
// sur les pages publiques ni sur les 404 préfixées /reglages. On seed le cache
// directement — aucun appel réseau (checked_at = maintenant → pas de re-fetch,
// et rafraichirSiNecessaire() est de toute façon inhibé sous le serveur builtin).

require_once dirname(__DIR__, 2) . '/app/src/Version.php';

function runMiseAJour(): void
{
    global $tmpDir;
    $etat   = $tmpDir . '/maj-state.json';
    $repli  = 'https://github.com/lemurdu20escalade/LesCreneaux/releases';
    $tagUrl = $repli . '/tag/v99.0.0';

    seedMajState($etat, '99.0.0', $tagUrl);
    $r = http('GET', '/reglages');
    ok($r['code'] === 200,                                    'GET /reglages → 200');
    ok(str_contains($r['body'], 'Version 99.0.0 disponible'), 'cache en avance → bandeau MAJ affiché');
    ok(str_contains($r['body'], $tagUrl),                     'bandeau → lien fourni par le cache');

    // url vide → le bandeau retombe sur la page releases construite localement.
    seedMajState($etat, '99.0.0', '');
    $r = http('GET', '/reglages');
    ok(str_contains($r['body'], 'href="' . $repli . '"'),     'url vide → lien de repli vers /releases');

    // Version distante == locale : garde-fou contre un >= transformé en >.
    // Seed dérivé de Version::APP pour rester exact après chaque bump.
    seedMajState($etat, Version::APP, $tagUrl);
    $r = http('GET', '/reglages');
    ok(!str_contains($r['body'], 'flash--info'),              'version égale à la locale → pas de bandeau');

    seedMajState($etat, '0.0.1', $tagUrl);
    $r = http('GET', '/reglages');
    ok(!str_contains($r['body'], 'flash--info'),              'version plus ancienne → pas de bandeau');

    seedMajState($etat, '99.0.0', $tagUrl);
    $r = http('GET', '/mois/2026-05');
    ok(!str_contains($r['body'], 'flash--info'),              'page publique → jamais de bandeau MAJ');

    // 404 dont le path commence par /reglages : rendue SANS auth par erreur().
    // Le match exact du path doit empêcher toute fuite de version à un anonyme.
    seedMajState($etat, '99.0.0', $tagUrl);
    $r = http('GET', '/reglages-inexistant');
    ok($r['code'] === 404,                                    'GET /reglages-inexistant → 404');
    ok(!str_contains($r['body'], 'Version 99.0.0 disponible'),'404 préfixée /reglages → pas de fuite de version');

    @unlink($etat);
}

function seedMajState(string $path, string $version, string $url): void
{
    file_put_contents($path, json_encode([
        'checked_at' => time(),
        'version'    => $version,
        'url'        => $url,
        'etag'       => '"seed"',
    ]));
}
