<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Front controller : reçoit toutes les requêtes non-fichiers via .htaccess.
// En dev via `php -S`, on laisse le serveur builtin servir les fichiers
// existants (ex: /assets/app.css) au lieu de les passer par index.php.
if (PHP_SAPI === 'cli-server') {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($reqPath !== '/' && is_file(__DIR__ . $reqPath)) {
        return false;
    }
}

$appDir = dirname(__DIR__) . '/app';

require $appDir . '/config.php';

// Défauts d'instance (nom d'asso, logo) : config.php peut les surcharger via
// `const`. Si l'install est antérieure à l'ajout de ces constantes, on
// retombe sur des valeurs neutres — le site reste fonctionnel et l'admin
// peut renseigner via /reglages (la DB a la priorité sur les constantes).
defined('ASSO_NOM_DEFAUT')      || define('ASSO_NOM_DEFAUT',      'Mon asso');
defined('ASSO_LOGO_URL_DEFAUT') || define('ASSO_LOGO_URL_DEFAUT', '');

require $appDir . '/src/helpers.php';
require $appDir . '/src/Database.php';
require $appDir . '/src/DateFr.php';
require $appDir . '/src/Version.php';
require $appDir . '/src/Csrf.php';
require $appDir . '/src/Router.php';
require $appDir . '/src/MoisGenerator.php';
require $appDir . '/src/InscriptionRepo.php';
require $appDir . '/src/JourRepo.php';
require $appDir . '/src/ReferenteRepo.php';
require $appDir . '/src/ModeleRepo.php';
require $appDir . '/src/LabelRepo.php';
require $appDir . '/src/FermetureRepo.php';
require $appDir . '/src/SettingsRepo.php';
require $appDir . '/src/HtmlSanitizer.php';
require $appDir . '/src/Surveillance.php';

// Compression transparente de toutes les réponses HTML : gzip/deflate
// selon Accept-Encoding. L'app sert beaucoup de HTML répétitif (formulaires
// CSRF, SVG inline) qui compresse très bien — typiquement ×6-8 sur
// /reglages. À poser avant tout output, avant même les headers de sécu.
if (extension_loaded('zlib')
    && !ini_get('zlib.output_compression')
    && PHP_SAPI !== 'cli') {
    ob_start('ob_gzhandler');
}

// Pose le cookie CSRF dès qu'on démarre (avant tout output).
Csrf::cookie();

/**
 * Headers de sécurité posés sur toutes les réponses.
 * CSP pragmatique : on autorise encore 'unsafe-inline' parce que l'app
 * utilise des handlers onclick/onsubmit et quelques <script> inline ;
 * la protection principale vient de frame-ancestors, base-uri et
 * form-action qui verrouillent l'app contre l'exfiltration et le
 * clickjacking. img-src autorise https: pour le logo d'asso externe.
 */
(function (): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' https: data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
})();

/**
 * Redirige (303) et arrête l'exécution. Si $action est fourni, pose le header
 * de redirection puis passe l'action à Surveillance, qui applique ses règles
 * et flush la réponse au client avant un éventuel POST Discord. La latence
 * du webhook n'impacte donc jamais la redirection côté navigateur.
 */
function redirect(string $url, ?string $action = null, array $ctx = []): void
{
    header('Location: ' . $url, true, 303);
    if ($action !== null) {
        Surveillance::surveiller($action, $ctx);
    }
    exit;
}

$router = new Router();

$router->get('/', function (): void {
    redirect('/mois/' . (new DateTimeImmutable('now'))->format('Y-m'));
});

$router->get('/licence', function () use ($appDir): void {
    $titre = 'Licence — LesCréneaux';
    ob_start();
    require $appDir . '/views/licence.php';
    $contenu = ob_get_clean();
    require $appDir . '/views/layout.php';
});

$router->get('/mois/{mois}', function (array $params) use ($appDir): void {
    $mois = $params['mois'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) {
        erreur(404, 'Mois invalide.'); return;
    }

    $pdo = Database::connect(DB_PATH);
    MoisGenerator::genererSiVide($pdo, $mois);

    // ETag pour le polling htmx : 304 si la DB n'a pas changé.
    $etag = Version::etag($pdo);
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }

    $jours       = MoisGenerator::listerJoursAvecDetails($pdo, $mois);
    $fermetures  = FermetureRepo::listerMois($pdo, $mois);
    $bandeauHtml = SettingsRepo::get($pdo, SettingsRepo::CLE_BANDEAU_HTML, '');
    $prenomMemo  = $_COOKIE['prenom'] ?? '';

    $titre     = 'Créneaux — ' . $mois;
    $needsHtmx = true; // polling htmx + drawer opener via window.htmx.ajax
    ob_start();
    require $appDir . '/views/mois.php';
    $contenu = ob_get_clean();
    require $appDir . '/views/layout.php';
});

$router->get('/jour/{id}', function (array $params) use ($appDir): void {
    $id     = (int)$params['id'];
    $pdo    = Database::connect(DB_PATH);
    $isHx   = ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';

    if ($isHx) {
        rendreDrawer($pdo, $id);
        return;
    }

    // Fallback page complète : reproduit ce que rendreDrawer charge pour
    // passer les mêmes variables à _detail.php via jour.php.
    $stmt = $pdo->prepare('SELECT * FROM jours WHERE id = ?');
    $stmt->execute([$id]);
    $jour = $stmt->fetch();
    if (!$jour) {
        erreur(404, 'Créneau introuvable.'); return;
    }
    $stmt = $pdo->prepare('SELECT * FROM referentes WHERE jour_id = ? ORDER BY heure_debut');
    $stmt->execute([$id]);
    $jour['referentes'] = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT * FROM inscriptions WHERE jour_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $jour['inscriptions'] = $stmt->fetchAll();
    $labelsParJour = LabelRepo::labelsParJour($pdo, [$id]);
    $jour['labels'] = $labelsParJour[$id] ?? [];
    $tousLabels = LabelRepo::lister($pdo);
    $prenomMemo = $_COOKIE['prenom'] ?? '';
    $mois       = substr((string)$jour['date'], 0, 7);

    $d = new DateTimeImmutable($jour['date']);
    $titre = 'Créneau du ' . DateFr::formatCourt($d);
    ob_start();
    require $appDir . '/views/jour.php';
    $contenu = ob_get_clean();
    require $appDir . '/views/layout.php';
});

$router->post('/jour/{id}/inscrire', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée (protection anti-spam).'); return;
    }
    $jourId = (int)$params['id'];
    $pdo    = Database::connect(DB_PATH);
    if (!InscriptionRepo::jourExiste($pdo, $jourId)) {
        erreur(404, 'Créneau introuvable.'); return;
    }
    $nom = trim((string)($_POST['nom'] ?? ''));
    if ($nom === '' || mb_strlen($nom) > 40) {
        erreur(400, 'Prénom invalide (1 à 40 caractères).'); return;
    }
    $noteIns = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($noteIns) > 80) {
        erreur(400, 'Note trop longue (80 caractères max).'); return;
    }
    $estVoisine = !empty($_POST['est_voisine']);
    InscriptionRepo::ajouter($pdo, $jourId, $nom, $estVoisine, $noteIns === '' ? null : $noteIns);

    // Mémorise le prénom côté cookie (1 an). Lu uniquement côté serveur pour
    // pré-remplir le champ nom, donc HttpOnly.
    setcookie('prenom', $nom, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Reflète le nouveau prénom dans la requête en cours, pour que le
    // rendu HX suivant (rendreDrawer) retrouve le prénom pré-rempli.
    $_COOKIE['prenom'] = $nom;

    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
        rendreDrawer($pdo, $jourId);
        return;
    }
    flash('Inscription enregistrée — à bientôt, ' . $nom . '.');
    $mois = JourRepo::moisDe($pdo, $jourId) ?? (new DateTimeImmutable('now'))->format('Y-m');
    redirect('/mois/' . $mois . '#jour-' . $jourId);
});

$router->post('/jour/{id}/desinscrire', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée (protection anti-spam).'); return;
    }
    $jourId        = (int)$params['id'];
    $inscriptionId = (int)($_POST['inscription_id'] ?? 0);
    if ($inscriptionId <= 0) {
        erreur(400, 'Inscription invalide.'); return;
    }
    $pdo = Database::connect(DB_PATH);
    InscriptionRepo::supprimer($pdo, $inscriptionId);

    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
        rendreDrawer($pdo, $jourId);
        return;
    }
    flash('Désinscription prise en compte.', 'info');
    $mois = JourRepo::moisDe($pdo, $jourId) ?? (new DateTimeImmutable('now'))->format('Y-m');
    redirect('/mois/' . $mois . '#jour-' . $jourId);
});

$router->post('/jour/{id}/update', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $hd  = parseHeure((string)($_POST['heure_debut'] ?? ''));
    $hf  = parseHeure((string)($_POST['heure_fin']   ?? ''));
    $cap = (int)($_POST['capacite'] ?? 0);
    if ($hd === null || $hf === null || $hd >= $hf) {
        erreur(400, 'Horaires invalides.'); return;
    }
    if ($cap < 1 || $cap > 500) {
        erreur(400, 'Capacité invalide (1 à 500).'); return;
    }
    $note = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 500) {
        erreur(400, 'Note trop longue.'); return;
    }
    $labels = array_map('intval', (array)($_POST['labels'] ?? []));
    $pdo = Database::connect(DB_PATH);
    $mois = JourRepo::moisDe($pdo, $id);
    if ($mois === null) {
        erreur(404, 'Créneau introuvable.'); return;
    }
    Database::tx($pdo, function (PDO $pdo) use ($id, $hd, $hf, $cap, $note, $labels): void {
        JourRepo::update($pdo, $id, $hd, $hf, $cap, $note === '' ? null : $note);
        LabelRepo::syncJour($pdo, $id, $labels);
    });
    flash('Créneau mis à jour.');
    redirect('/mois/' . $mois . '#jour-' . $id);
});

$router->post('/jour/{id}/supprimer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id   = (int)$params['id'];
    $pdo  = Database::connect(DB_PATH);
    $mois = JourRepo::moisDe($pdo, $id) ?? (new DateTimeImmutable('now'))->format('Y-m');
    JourRepo::supprimer($pdo, $id);
    flash('Créneau supprimé.', 'info');
    redirect('/mois/' . $mois);
});

$router->post('/jour/{id}/referente/ajouter', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $jourId = (int)$params['id'];
    $nom    = trim((string)($_POST['nom'] ?? ''));
    $hd     = parseHeure((string)($_POST['heure_debut'] ?? ''));
    // heure_fin optionnelle : vide → null (pas de pression sur la sortie).
    $hfBrut = trim((string)($_POST['heure_fin'] ?? ''));
    $hf     = $hfBrut === '' ? null : parseHeure($hfBrut);
    $hfInvalide = $hfBrut !== '' && $hf === null;
    if ($nom === '' || mb_strlen($nom) > 40 || $hd === null || $hfInvalide
        || ($hf !== null && $hd >= $hf)) {
        erreur(400, 'Données invalides.'); return;
    }
    $pdo = Database::connect(DB_PATH);
    if (!InscriptionRepo::jourExiste($pdo, $jourId)) {
        erreur(404, 'Créneau introuvable.'); return;
    }
    ReferenteRepo::ajouter($pdo, $jourId, $nom, $hd, $hf);
    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
        rendreDrawer($pdo, $jourId);
        return;
    }
    flash($nom . ' ajouté·e comme référent·e — merci !');
    $mois = JourRepo::moisDe($pdo, $jourId);
    redirect('/mois/' . $mois . '#jour-' . $jourId);
});

$router->get('/reglages', function () use ($appDir): void {
    $pdo        = Database::connect(DB_PATH);
    $modeles    = ModeleRepo::lister($pdo);
    $labels     = LabelRepo::lister($pdo);
    $fermetures = FermetureRepo::lister($pdo);
    $assoNom    = SettingsRepo::get($pdo, SettingsRepo::CLE_ASSO_NOM,      ASSO_NOM_DEFAUT);
    $assoLogo   = SettingsRepo::get($pdo, SettingsRepo::CLE_ASSO_LOGO_URL, ASSO_LOGO_URL_DEFAUT);
    $bandeau    = SettingsRepo::get($pdo, SettingsRepo::CLE_BANDEAU_HTML,  '');
    $titre      = 'Réglages';
    ob_start();
    require $appDir . '/views/reglages.php';
    $contenu = ob_get_clean();
    require $appDir . '/views/layout.php';
});

$router->post('/modele/ajouter', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $js  = (int)($_POST['jour_semaine'] ?? 0);
    $hd  = parseHeure((string)($_POST['heure_debut'] ?? ''));
    $hf  = parseHeure((string)($_POST['heure_fin']   ?? ''));
    $cap = (int)($_POST['capacite'] ?? 0);
    if ($js < 1 || $js > 7 || $hd === null || $hf === null || $hd >= $hf || $cap < 1 || $cap > 500) {
        erreur(400, 'Données invalides.'); return;
    }
    $note = trim((string)($_POST['note_defaut'] ?? ''));
    if (mb_strlen($note) > 500) {
        erreur(400, 'Note trop longue.'); return;
    }
    $labels = array_map('intval', (array)($_POST['labels'] ?? []));
    $pdo = Database::connect(DB_PATH);
    $newId = Database::tx($pdo, function (PDO $pdo) use ($js, $hd, $hf, $cap, $note, $labels): int {
        $id = ModeleRepo::ajouter($pdo, $js, $hd, $hf, $cap, $note === '' ? null : $note);
        LabelRepo::syncModele($pdo, $id, $labels);
        return $id;
    });
    $resume = "Modèle #{$newId} créé — jour {$js}, {$hd}-{$hf}, capacité {$cap}"
        . ($note === '' ? '' : ", note « {$note} »")
        . (empty($labels) ? '' : ', labels [' . implode(',', $labels) . ']');
    redirect('/reglages', 'modele.ajouter', ['resume' => $resume]);
});

$router->post('/modele/{id}/update', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $js  = (int)($_POST['jour_semaine'] ?? 0);
    $hd  = parseHeure((string)($_POST['heure_debut'] ?? ''));
    $hf  = parseHeure((string)($_POST['heure_fin']   ?? ''));
    $cap = (int)($_POST['capacite'] ?? 0);
    if ($js < 1 || $js > 7 || $hd === null || $hf === null || $hd >= $hf || $cap < 1 || $cap > 500) {
        erreur(400, 'Données invalides.'); return;
    }
    $active = !empty($_POST['active']);
    $note = trim((string)($_POST['note_defaut'] ?? ''));
    if (mb_strlen($note) > 500) {
        erreur(400, 'Note trop longue.'); return;
    }
    $labels = array_map('intval', (array)($_POST['labels'] ?? []));
    $pdo = Database::connect(DB_PATH);
    $old = $pdo->prepare('SELECT * FROM modeles WHERE id = ?');
    $old->execute([$id]);
    $avant = $old->fetch() ?: [];
    $labelsAvant = LabelRepo::idsAttachesModele($pdo, $id);
    Database::tx($pdo, function (PDO $pdo) use ($id, $js, $hd, $hf, $cap, $active, $note, $labels): void {
        ModeleRepo::update($pdo, $id, $js, $hd, $hf, $cap, $active, $note === '' ? null : $note);
        LabelRepo::syncModele($pdo, $id, $labels);
    });
    sort($labelsAvant); sort($labels);
    $diff = Surveillance::diff(
        [
            'jour_semaine' => $avant['jour_semaine'] ?? null,
            'heure_debut'  => $avant['heure_debut']  ?? null,
            'heure_fin'    => $avant['heure_fin']    ?? null,
            'capacite'     => $avant['capacite']     ?? null,
            'active'       => $avant['active']       ?? null,
            'note_defaut'  => $avant['note_defaut']  ?? null,
            'labels'       => $labelsAvant,
        ],
        [
            'jour_semaine' => $js,
            'heure_debut'  => $hd,
            'heure_fin'    => $hf,
            'capacite'     => $cap,
            'active'       => $active ? 1 : 0,
            'note_defaut'  => $note === '' ? null : $note,
            'labels'       => $labels,
        ],
    );
    redirect('/reglages', 'modele.update', ['diff' => "Modèle #{$id}\n{$diff}"]);
});

$router->post('/modele/{id}/supprimer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $pdo = Database::connect(DB_PATH);
    $stmt = $pdo->prepare('SELECT * FROM modeles WHERE id = ?');
    $stmt->execute([$id]);
    $avant = $stmt->fetch() ?: [];
    ModeleRepo::supprimer($pdo, $id);
    $resume = $avant
        ? "Modèle #{$id} supprimé — jour {$avant['jour_semaine']}, "
          . "{$avant['heure_debut']}-{$avant['heure_fin']}, capacité {$avant['capacite']}"
        : "Modèle #{$id} (introuvable)";
    redirect('/reglages', 'modele.supprimer', ['resume' => $resume]);
});

$router->post('/modele/{id}/dupliquer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $pdo = Database::connect(DB_PATH);
    $new = ModeleRepo::dupliquer($pdo, $id);
    if ($new === null) {
        erreur(404, 'Modèle introuvable.'); return;
    }
    redirect('/reglages#modele-' . $new, 'modele.dupliquer', ['resume' => "Modèle #{$id} dupliqué → #{$new}"]);
});

$router->post('/label/ajouter', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $nom     = trim((string)($_POST['nom'] ?? ''));
    $couleur = (string)($_POST['couleur'] ?? LabelRepo::DEFAUT);
    if ($nom === '' || mb_strlen($nom) > 40) {
        erreur(400, 'Nom invalide.'); return;
    }
    $bloque = !empty($_POST['bloque_inscriptions']);
    $ouvre  = !empty($_POST['ouvre_voisines']);
    $pdo = Database::connect(DB_PATH);
    try {
        $newId = LabelRepo::ajouter($pdo, $nom, $couleur, $bloque, $ouvre);
    } catch (PDOException $e) {
        erreur(409, 'Une étiquette avec ce nom existe déjà.'); return;
    }
    $flags = [];
    if ($bloque) $flags[] = 'bloque_inscriptions';
    if ($ouvre)  $flags[] = 'ouvre_voisines';
    $resume = "Label #{$newId} « {$nom} » créé (couleur {$couleur})"
        . ($flags ? ' — ' . implode(', ', $flags) : '');
    redirect('/reglages#labels', 'label.ajouter', ['resume' => $resume]);
});

$router->post('/label/{id}/update', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id      = (int)$params['id'];
    $nom     = trim((string)($_POST['nom'] ?? ''));
    $couleur = (string)($_POST['couleur'] ?? LabelRepo::DEFAUT);
    if ($nom === '' || mb_strlen($nom) > 40) {
        erreur(400, 'Nom invalide.'); return;
    }
    $bloque = !empty($_POST['bloque_inscriptions']);
    $ouvre  = !empty($_POST['ouvre_voisines']);
    $pdo = Database::connect(DB_PATH);
    $stmt = $pdo->prepare('SELECT * FROM labels WHERE id = ?');
    $stmt->execute([$id]);
    $avant = $stmt->fetch() ?: [];
    LabelRepo::update($pdo, $id, $nom, $couleur, $bloque, $ouvre);
    $diff = Surveillance::diff(
        [
            'nom'                 => $avant['nom']                 ?? null,
            'couleur'             => $avant['couleur']             ?? null,
            'bloque_inscriptions' => $avant['bloque_inscriptions'] ?? null,
            'ouvre_voisines'      => $avant['ouvre_voisines']      ?? null,
        ],
        [
            'nom'                 => $nom,
            'couleur'             => LabelRepo::normaliserHex($couleur),
            'bloque_inscriptions' => $bloque ? 1 : 0,
            'ouvre_voisines'      => $ouvre ? 1 : 0,
        ],
    );
    redirect('/reglages#labels', 'label.update', ['diff' => "Label #{$id}\n{$diff}"]);
});

$router->post('/label/{id}/supprimer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $pdo = Database::connect(DB_PATH);
    $stmt = $pdo->prepare('SELECT nom FROM labels WHERE id = ?');
    $stmt->execute([$id]);
    $nomAvant = (string)$stmt->fetchColumn();
    LabelRepo::supprimer($pdo, $id);
    $resume = $nomAvant !== ''
        ? "Label #{$id} « {$nomAvant} » supprimé"
        : "Label #{$id} (introuvable)";
    redirect('/reglages#labels', 'label.supprimer', ['resume' => $resume]);
});

$router->post('/modele/semaine-type', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $pdo = Database::connect(DB_PATH);
    // Refus si la liste n'est pas vide, pour éviter les doublons.
    $n = (int)$pdo->query('SELECT COUNT(*) FROM modeles')->fetchColumn();
    if ($n > 0) {
        redirect('/reglages');
    }
    // Labels par défaut déjà seedés par les migrations 007 et 008.
    $labelCaf = (int)$pdo->query("SELECT id FROM labels WHERE nom = 'CAF'")->fetchColumn();
    $labelPe  = (int)$pdo->query("SELECT id FROM labels WHERE nom = 'Parents-enfants'")->fetchColumn();
    $labelV   = (int)$pdo->query("SELECT id FROM labels WHERE nom = 'Ouvert aux voisin·es'")->fetchColumn();

    $modeles = [
        [1, '18:00', '22:30', 15, null, [$labelV]],
        [2, '18:00', '22:30', 15, null, [$labelV]],
        [4, '18:00', '22:30', 15, null, [$labelV]],
        [6, '12:00', '14:00', 15, null, []],
        [6, '16:00', '18:00', 15, null, [$labelCaf, $labelPe]],
        [6, '18:00', '22:00', 15, null, [$labelCaf]],
        [7, '14:00', '18:00', 15, null, [$labelCaf, $labelPe]],
    ];
    foreach ($modeles as [$js, $hd, $hf, $cap, $note, $lbls]) {
        $mid = ModeleRepo::ajouter($pdo, $js, $hd, $hf, $cap, $note);
        LabelRepo::syncModele($pdo, $mid, array_filter($lbls));
    }
    redirect('/reglages', 'modele.semaine-type', ['resume' => count($modeles) . ' modèles seedés']);
});

$router->post('/settings/update', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $nom = trim((string)($_POST['asso_nom'] ?? ''));
    $url = trim((string)($_POST['asso_logo_url'] ?? ''));
    if ($nom === '' || mb_strlen($nom) > 80) {
        erreur(400, 'Nom invalide (1 à 80 caractères).'); return;
    }
    if ($url !== '') {
        if (mb_strlen($url) > 500 || !preg_match('#^https?://#i', $url)) {
            erreur(400, 'URL du logo invalide (http/https requis).'); return;
        }
    }
    $pdo = Database::connect(DB_PATH);
    $avant = [
        'nom'  => SettingsRepo::get($pdo, SettingsRepo::CLE_ASSO_NOM,      ''),
        'logo' => SettingsRepo::get($pdo, SettingsRepo::CLE_ASSO_LOGO_URL, ''),
    ];
    SettingsRepo::set($pdo, SettingsRepo::CLE_ASSO_NOM, $nom);
    if ($url === '') {
        SettingsRepo::effacer($pdo, SettingsRepo::CLE_ASSO_LOGO_URL);
    } else {
        SettingsRepo::set($pdo, SettingsRepo::CLE_ASSO_LOGO_URL, $url);
    }
    $diff = Surveillance::diff($avant, ['nom' => $nom, 'logo' => $url]);
    redirect('/reglages#identite', 'settings.update', [
        'diff'       => $diff,
        'changement' => $diff !== '(aucun changement visible)',
    ]);
});

$router->post('/settings/bandeau/update', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $brut = (string)($_POST['html'] ?? '');
    if (mb_strlen($brut) > 20000) {
        erreur(400, 'Contenu trop long (20 000 caractères max).'); return;
    }
    $propre = HtmlSanitizer::sanitize($brut);
    $pdo = Database::connect(DB_PATH);
    $avant = SettingsRepo::get($pdo, SettingsRepo::CLE_BANDEAU_HTML, '');
    if ($propre === '') {
        SettingsRepo::effacer($pdo, SettingsRepo::CLE_BANDEAU_HTML);
    } else {
        SettingsRepo::set($pdo, SettingsRepo::CLE_BANDEAU_HTML, $propre);
    }
    if ($avant === $propre) {
        redirect('/reglages#bandeau');
    }
    $resume = Surveillance::diff(['html' => $avant], ['html' => $propre]);
    redirect('/reglages#bandeau', 'settings.bandeau', [
        'diff' => $resume,
        'html' => $propre,
    ]);
});

$router->post('/fermeture/ajouter-lot', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $dates = (array)($_POST['dates'] ?? []);
    $notes = (array)($_POST['notes'] ?? []);
    if (empty($dates)) {
        redirect('/reglages#fermetures');
    }
    $pdo   = Database::connect(DB_PATH);
    $ajoutees = 0;
    $ignorees = 0;
    $creneauxSupprimes = 0;
    foreach ($dates as $i => $d) {
        $date = parseDate((string)$d);
        if ($date === null) {
            $ignorees++;
            continue;
        }
        $note = trim((string)($notes[$i] ?? ''));
        if (mb_strlen($note) > 200) {
            $note = mb_substr($note, 0, 200);
        }
        try {
            [, $supprimes] = FermetureRepo::ajouter($pdo, $date, $note === '' ? null : $note);
            $ajoutees++;
            $creneauxSupprimes += $supprimes;
        } catch (PDOException $e) {
            $ignorees++; // UNIQUE déjà existant
        }
    }
    // Info de résultat via query string (simple, pas de session flash)
    $suffixSup = $creneauxSupprimes > 0 ? ", {$creneauxSupprimes} créneau(x) retiré(s)" : '';
    $resume = "Import lot — {$ajoutees} ajoutée(s), {$ignorees} ignorée(s){$suffixSup}";
    redirect(
        '/reglages?ajoutees=' . $ajoutees . '&ignorees=' . $ignorees
            . ($creneauxSupprimes > 0 ? '&supprimes=' . $creneauxSupprimes : '')
            . '#fermetures',
        $ajoutees > 0 ? 'fermeture.ajouter-lot' : null,
        ['resume' => $resume]
    );
});

$router->post('/fermeture/supprimer-lot', function (): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));
    if (empty($ids)) {
        redirect('/reglages#fermetures');
    }
    // SQLITE_MAX_VARIABLE_NUMBER = 999 par défaut, on garde de la marge.
    $ids = array_slice($ids, 0, 500);
    $pdo = Database::connect(DB_PATH);
    $supprimees = FermetureRepo::supprimerPlusieurs($pdo, $ids);
    if ($supprimees === 0) {
        redirect('/reglages#fermetures');
    }
    $resume = "Suppression lot — {$supprimees} fermeture(s) supprimée(s)";
    redirect('/reglages#fermetures', 'fermeture.supprimer-lot', ['resume' => $resume]);
});

$router->post('/fermeture/{id}/supprimer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $pdo = Database::connect(DB_PATH);
    $stmt = $pdo->prepare('SELECT date, note FROM fermetures WHERE id = ?');
    $stmt->execute([$id]);
    $avant = $stmt->fetch() ?: [];
    FermetureRepo::supprimer($pdo, $id);
    $resume = $avant
        ? "Fermeture #{$id} supprimée — {$avant['date']}"
          . (($avant['note'] ?? '') === '' ? '' : " (« {$avant['note']} »)")
        : "Fermeture #{$id} (introuvable)";
    redirect('/reglages#fermetures', 'fermeture.supprimer', ['resume' => $resume]);
});

$router->post('/referente/{id}/supprimer', function (array $params): void {
    if (!Csrf::verifierPost($_POST)) {
        erreur(400, 'Requête refusée.'); return;
    }
    $id  = (int)$params['id'];
    $pdo = Database::connect(DB_PATH);
    $jourId = ReferenteRepo::jourDe($pdo, $id);
    if ($jourId === null) {
        erreur(404, 'Référent·e introuvable.'); return;
    }
    ReferenteRepo::supprimer($pdo, $id);
    if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
        rendreDrawer($pdo, $jourId);
        return;
    }
    flash('Référent·e retiré·e.', 'info');
    $mois = JourRepo::moisDe($pdo, $jourId) ?? (new DateTimeImmutable('now'))->format('Y-m');
    redirect('/mois/' . $mois . '#jour-' . $jourId);
});

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI']    ?? '/'
);
