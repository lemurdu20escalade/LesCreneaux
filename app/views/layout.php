<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// flashLire() pose un setcookie — doit être appelé avant tout output.
$flash   = flashLire();
$assoNom = setting(SettingsRepo::CLE_ASSO_NOM,      ASSO_NOM_DEFAUT);
$logoUrl = setting(SettingsRepo::CLE_ASSO_LOGO_URL, ASSO_LOGO_URL_DEFAUT);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light only">
    <meta name="theme-color" content="#1976d2">
    <title><?= e($titre ?? ('Créneaux ' . $assoNom)) ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css"><!-- Pico retiré, Material Design custom -->
    <?php if (!empty($needsHtmx)): /* chargé uniquement là où on en a besoin */ ?>
        <script src="/assets/htmx.min.js" defer></script>
    <?php endif; ?>
</head>
<body>
    <?= iconSprite() /* bank de <symbol> partagés par tous les <use href="#i-*"> */ ?>
    <header class="site-header">
        <div class="container">
            <h1 class="site-titre">
                <a href="/">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= e($logoUrl) ?>" alt="" class="site-logo">
                    <?php endif; ?>
                    <span><?= e($assoNom) ?></span>
                </a>
            </h1>
            <nav class="site-nav">
                <a href="/reglages"><?= icon('settings', 18) ?><span>Réglages</span></a>
                <?php if (AdminAuth::estActive() && AdminAuth::connecte()): ?>
                    <a href="/admin/logout"><?= icon('logout', 18) ?><span>Déconnexion</span></a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($flash !== null): ?>
            <div class="flash flash--<?= e($flash['type']) ?>" role="status" aria-live="polite">
                <?= e($flash['msg']) ?>
            </div>
        <?php endif; ?>
        <?= $contenu ?? '' ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <a href="/licence" rel="license">Code libre · AGPL v3</a>
            <span class="meta"> · </span>
            <a href="https://github.com/lemurdu20escalade/LesCreneaux" rel="source">Source</a>
        </div>
    </footer>

    <dialog id="drawer" class="drawer" aria-label="Détails du créneau">
        <button type="button" class="drawer-close" aria-label="Fermer"
                onclick="document.getElementById('drawer').close()">
            <?= icon('close', 22) ?>
        </button>
        <div id="drawer-body" class="drawer-body">
            <p class="meta">Chargement…</p>
        </div>
    </dialog>

    <script>
    (function () {
        const drawer = document.getElementById('drawer');
        const body   = document.getElementById('drawer-body');
        if (!drawer || !body || typeof drawer.showModal !== 'function') return;

        document.addEventListener('click', function (e) {
            const link = e.target.closest('a[data-drawer]');
            if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            e.preventDefault();
            if (!window.htmx) { window.location = link.href; return; }
            drawer.showModal();
            window.htmx.ajax('GET', '/jour/' + encodeURIComponent(link.dataset.drawer), {
                target: '#drawer-body',
                swap: 'innerHTML'
            }).then(function () {
                const first = body.querySelector('input[name="nom"]');
                if (first) setTimeout(function () { first.focus(); }, 50);
            });
        });

        drawer.addEventListener('click', function (e) {
            if (e.target === drawer) drawer.close();
        });
    })();
    </script>
</body>
</html>
