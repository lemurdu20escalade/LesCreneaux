<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Tests unitaires purs (sans serveur ni réseau) de la logique de validation et
// de cache de MiseAJour. appliquerReponse() exerce versionDepuis()/urlDepuis()
// — regex semver + rejet de toute URL non github.com (le rempart contre un
// href javascript:) — et le backoff checked_at sur 304/404/403. Ces chemins
// sont inatteignables via le harnais HTTP : il tourne sous php -S, où
// rafraichirSiNecessaire() court-circuite avant d'appeler le réseau.

require_once dirname(__DIR__, 2) . '/app/src/Version.php';
require_once dirname(__DIR__, 2) . '/app/src/MiseAJour.php';

function runMiseAJourUnit(): void
{
    $base = ['checked_at' => 0, 'version' => '0.0.0', 'url' => '', 'etag' => '"vieux"'];

    // 200 + tag semver valide → version/url/etag mis à jour, checked_at repoussé.
    $r = MiseAJour::appliquerReponse($base, 200,
        '{"tag_name":"v1.2.3","html_url":"https://github.com/lemurdu20escalade/LesCreneaux/releases/tag/v1.2.3"}',
        '"neuf"');
    ok($r['version'] === '1.2.3',                'unit: tag v1.2.3 → version 1.2.3');
    ok($r['url'] === 'https://github.com/lemurdu20escalade/LesCreneaux/releases/tag/v1.2.3',
                                                 'unit: html_url github conservée');
    ok($r['etag'] === '"neuf"',                  'unit: etag mémorisé');
    ok($r['checked_at'] > 0,                     'unit: checked_at repoussé');

    // 200 + tag NON semver → version inchangée, mais etag tout de même mémorisé
    // (fix : permet un 304 au check suivant même si le tag est ignoré).
    $r = MiseAJour::appliquerReponse($base, 200, '{"tag_name":"nightly"}', '"e2"');
    ok($r['version'] === '0.0.0',                'unit: tag non-semver → version inchangée');
    ok($r['etag'] === '"e2"',                    'unit: etag mémorisé même si tag ignoré');

    // 200 + html_url javascript: → repli sur /releases (rempart anti-XSS).
    $r = MiseAJour::appliquerReponse($base, 200,
        '{"tag_name":"9.9.9","html_url":"javascript:alert(1)"}', '');
    ok($r['version'] === '9.9.9',                'unit: version prise');
    ok($r['url'] === 'https://github.com/lemurdu20escalade/LesCreneaux/releases',
                                                 'unit: html_url javascript: rejetée → repli');

    // 200 + html_url sur un autre domaine → repli aussi.
    $r = MiseAJour::appliquerReponse($base, 200,
        '{"tag_name":"9.9.9","html_url":"https://evil.tld/x"}', '');
    ok($r['url'] === 'https://github.com/lemurdu20escalade/LesCreneaux/releases',
                                                 'unit: html_url hors github → repli');

    // 304 / 404 / 403 → checked_at repoussé, version/url inchangées.
    foreach ([304, 404, 403] as $code) {
        $seed = ['checked_at' => 0, 'version' => '0.5.0', 'url' => 'https://github.com/x', 'etag' => '"e"'];
        $r = MiseAJour::appliquerReponse($seed, $code, '', '');
        ok($r['checked_at'] > 0 && $r['version'] === '0.5.0',
                                                 "unit: $code → checked_at repoussé, version gardée");
    }

    // Version::APP doit refléter la dernière version publiée du CHANGELOG.
    $changelog = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
    ok(preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m) === 1,
                                                 'unit: CHANGELOG a une version publiée');
    ok(($m[1] ?? '') === Version::APP,           'unit: Version::APP == dernière version du CHANGELOG');
}
