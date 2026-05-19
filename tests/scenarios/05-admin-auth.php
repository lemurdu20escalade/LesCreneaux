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

    // Login interactif : submit en < 2 s (timing_min désactivé sur ce flow).
    // Reproduit le password manager qui auto-fill et auto-submit avant que
    // la borne anti-bot de 2 s soit écoulée. Avant le fix : 400 anti-spam.
    $tokens = csrfTokens($cookieCsrf, 0);  // age = 0 s
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur', 'retour' => '/reglages'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST login submit immédiat (age=0s) → 303');

    // Échec CSRF (token incohérent) : on doit re-rendre le form avec un
    // message lisible, pas une page d'erreur 400 sèche — sinon le browser
    // reste sur l'URL POST et le reload propose Confirm Form Resubmission.
    $tokens = csrfTokens($cookieCsrf);
    $tokens['_csrf'] = str_repeat('0', 64);   // token forgé, ne matche pas le cookie
    $r = http(
        'POST',
        '/admin/login',
        array_merge(['password' => 'lemur'], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 400, 'Auth actif — POST login CSRF KO → 400 (re-render)');
    ok(
        str_contains($r['body'], 'Connexion admin')
            && str_contains($r['body'], 'recharge la page'),
        'Auth actif — POST login CSRF KO → form re-rendu avec message lisible'
    );

    // Ajout d'un·e référent·e : action publique, doit fonctionner SANS cookie
    // admin même en mode auth actif. La suppression reste admin (anti-vandalisme :
    // retirer la référent·e d'un autre).
    $pdoRef = dbConnect();
    $pdoRef->prepare(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite) VALUES (?, ?, ?, ?)"
    )->execute(['2026-08-10', '18:00', '22:30', 100]);
    $idJourRef = (int)$pdoRef->lastInsertId();
    unset($pdoRef);

    // GET détail d'un jour SANS cookie admin : la vue affiche les formulaires
    // d'édition (heures/étiquettes/limite/note) — action publique. Seule la
    // suppression du créneau reste cachée (anti-vandalisme).
    $rDetail = http('GET', "/jour/$idJourRef", [], ['csrf_session' => $cookieCsrf]);
    ok(
        str_contains($rDetail['body'], 'action="/jour/' . $idJourRef . '/update"'),
        'Auth actif — vue détail SANS admin contient le form /update (édition publique)'
    );
    ok(
        !str_contains($rDetail['body'], 'action="/jour/' . $idJourRef . '/supprimer"'),
        'Auth actif — vue détail SANS admin ne contient pas le form /supprimer créneau'
    );
    ok(
        str_contains($rDetail['body'], 'action="/jour/' . $idJourRef . '/inscrire"')
            && str_contains($rDetail['body'], 'action="/jour/' . $idJourRef . '/referente/ajouter"'),
        'Auth actif — vue détail SANS admin garde inscrire + referente/ajouter'
    );

    $rDetailAdmin = http('GET', "/jour/$idJourRef", [], [
        'csrf_session'  => $cookieCsrf,
        'admin_session' => $cookieAdminSession,
    ]);
    ok(
        str_contains($rDetailAdmin['body'], 'action="/jour/' . $idJourRef . '/update"')
            && str_contains($rDetailAdmin['body'], 'action="/jour/' . $idJourRef . '/supprimer"'),
        'Auth actif — vue détail AVEC admin contient bien /update et /supprimer'
    );

    // POST /jour/{id}/update : action publique en mode auth actif.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$idJourRef/update",
        array_merge([
            'heure_debut' => '19:00',
            'heure_fin'   => '22:00',
            'capacite'    => 50,
            'note'        => 'modifié sans login',
        ], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST /jour/{id}/update SANS admin → 303 (public)');
    ok(
        !str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth actif — POST /jour/{id}/update SANS admin → pas de redirect login'
    );

    // POST /jour/{id}/supprimer : reste admin-only.
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$idJourRef/supprimer",
        $tokens,
        ['csrf_session' => $cookieCsrf]
    );
    ok(
        $r['code'] === 303
            && str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth actif — POST /jour/{id}/supprimer SANS admin → redirect vers /admin/login'
    );

    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$idJourRef/referente/ajouter",
        array_merge(['nom' => 'TestRef', 'heure_debut' => '18:00'], $tokens),
        ['csrf_session' => $cookieCsrf]   // pas de cookie admin_session
    );
    ok($r['code'] === 303, 'Auth actif — POST referente/ajouter SANS admin → 303 (public)');
    ok(
        !str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth actif — POST referente/ajouter SANS admin → pas de redirect login'
    );

    $stmtRef = dbConnect()->prepare('SELECT COUNT(*) FROM referentes WHERE nom = ? AND jour_id = ?');
    $stmtRef->execute(['TestRef', $idJourRef]);
    ok((int)$stmtRef->fetchColumn() === 1, 'Auth actif — référent·e bien enregistré·e en DB');

    // Suppression : doit rester admin-only.
    $idRef = (int)dbConnect()->query("SELECT id FROM referentes WHERE nom='TestRef'")->fetchColumn();
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/referente/$idRef/supprimer",
        $tokens,
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, 'Auth actif — POST referente/supprimer SANS admin → 303');
    ok(
        str_starts_with($r['headers']['location'] ?? '', '/admin/login'),
        'Auth actif — POST referente/supprimer SANS admin → redirect vers /admin/login'
    );

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
