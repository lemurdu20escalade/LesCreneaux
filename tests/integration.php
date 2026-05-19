<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Harnais de tests d'intégration — standalone, sans Composer ni PHPUnit.
// Chaque run crée un répertoire temp isolé et un serveur builtin éphémère.
//
// La config de test est injectée via APP_CONFIG_OVERRIDE (env passée au
// processus serveur). www/index.php la lit à la place de app/config.php.
// Le data/ du dev n'est jamais touché.

// ---------------------------------------------------------------------------
// Init : répertoire et config jetables
// ---------------------------------------------------------------------------

$tmpDir     = sys_get_temp_dir() . '/lescreneaux-tests-' . getmypid();
$configPath = $tmpDir . '/config.php';
$dbPath     = $tmpDir . '/test.sqlite';
$ratePath   = $tmpDir . '/rate-limit.json';

if (!mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Impossible de créer le dossier temp : $tmpDir\n");
    exit(1);
}

// PORT : on laisse l'OS choisir en bindant sur 0, puis on récupère le numéro.
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock === false) {
    fwrite(STDERR, "socket_create échoué\n");
    exit(1);
}
socket_bind($sock, '127.0.0.1', 0);
socket_getsockname($sock, $addr, $port);
socket_close($sock);

$secretCsrf  = bin2hex(random_bytes(32));
$baseUrl     = "http://127.0.0.1:$port";
$projectRoot = dirname(__DIR__);
$wwwDir      = $projectRoot . '/www';

// Config PHP auto-contenue : DATA_DIR pointe vers $tmpDir.
file_put_contents($configPath, sprintf(
    "<?php\ndeclare(strict_types=1);\n"
    . "const BASE_URL    = %s;\n"
    . "const DATA_DIR    = %s;\n"
    . "const DB_PATH     = %s;\n"
    . "const SECRET_CSRF = %s;\n"
    . "const MAIL_FROM   = 'test@localhost';\n"
    . "const SSE_ENABLED = false;\n"
    . "const DISCORD_WEBHOOK_URL = '';\n"
    . "const ADMIN_PASSWORD_HASH = '';\n"
    . "const ASSO_NOM_DEFAUT      = 'TestAsso';\n"
    . "const ASSO_LOGO_URL_DEFAUT = '';\n"
    . "date_default_timezone_set('Europe/Paris');\n",
    var_export($baseUrl, true),
    var_export($tmpDir, true),
    var_export($dbPath, true),
    var_export($secretCsrf, true)
));

// ---------------------------------------------------------------------------
// Lancement du serveur builtin
// ---------------------------------------------------------------------------

$serverDescriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $tmpDir . '/server.log', 'w'],
    2 => ['file', $tmpDir . '/server.err', 'w'],
];

// On passe APP_CONFIG_OVERRIDE dans l'environnement du sous-processus.
$serverEnv = array_merge(getenv() ?: [], [
    'APP_CONFIG_OVERRIDE' => $configPath,
]);

$serverCmd = 'php -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($wwwDir)
    . ' ' . escapeshellarg($wwwDir . '/_router.php');

$serverProc = proc_open($serverCmd, $serverDescriptors, $pipes, null, $serverEnv);

if ($serverProc === false) {
    fwrite(STDERR, "proc_open échoué pour le serveur builtin\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Cleanup au shutdown : kill serveur + suppression temp
// ---------------------------------------------------------------------------

register_shutdown_function(function () use (&$serverProc, $tmpDir): void {
    if ($serverProc === null) {
        supprimerDossier($tmpDir);
        return;
    }
    $status = proc_get_status($serverProc);
    if ($status['running'] ?? false) {
        proc_terminate($serverProc);
    }
    proc_close($serverProc);
    supprimerDossier($tmpDir);
});

// Intercepter SIGINT/SIGTERM si pcntl disponible.
if (function_exists('pcntl_signal')) {
    $handler = function () { exit(0); };
    pcntl_signal(SIGINT,  $handler);
    pcntl_signal(SIGTERM, $handler);
}

// ---------------------------------------------------------------------------
// Attendre que le serveur réponde (poll GET / max 5 s)
// ---------------------------------------------------------------------------

$pret     = false;
$deadline = microtime(true) + 5.0;

while (microtime(true) < $deadline) {
    $pollCtx = stream_context_create(['http' => [
        'method'          => 'GET',
        'ignore_errors'   => true,
        'timeout'         => 0.5,
        'follow_location' => 0,
    ]]);
    // Silence : on boucle jusqu'au succès ou au timeout.
    $probe = @file_get_contents("$baseUrl/", false, $pollCtx);
    if ($probe !== false || !empty($http_response_header)) {
        $pret = true;
        break;
    }
    usleep(100_000);
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

if (!$pret) {
    fwrite(STDERR, "Le serveur builtin ne répond pas après 5 s\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Effectue une requête HTTP via stream_context_create (sans ext-curl).
 *
 * Retourne code HTTP, headers normalisés (minuscules), corps brut,
 * et les valeurs des Set-Cookie parsés (name => value, avant le premier ';').
 *
 * @param  array<string,string> $form
 * @param  array<string,string> $cookies
 * @param  array<string,string> $headers
 * @return array{code:int,headers:array<string,string>,body:string,setCookies:array<string,string>}
 */
function http(
    string $method,
    string $path,
    array $form    = [],
    array $cookies = [],
    array $headers = []
): array {
    global $baseUrl;

    $bodyContent = $form !== [] ? http_build_query($form) : '';

    $reqHeaders = $headers;
    if ($cookies !== []) {
        $reqHeaders['Cookie'] = implode('; ', array_map(
            fn($k, $v) => "$k=$v",
            array_keys($cookies),
            $cookies
        ));
    }
    if ($bodyContent !== '') {
        $reqHeaders['Content-Type']   = 'application/x-www-form-urlencoded';
        $reqHeaders['Content-Length'] = (string)strlen($bodyContent);
    }

    $headerLines = array_map(
        fn($k, $v) => "$k: $v",
        array_keys($reqHeaders),
        $reqHeaders
    );

    $opts = [
        'http' => [
            'method'          => strtoupper($method),
            'header'          => implode("\r\n", $headerLines),
            'content'         => $bodyContent,
            'ignore_errors'   => true,
            'follow_location' => 0,
        ],
    ];

    // $http_response_header est peuplée par file_get_contents dans le scope global.
    $ctx          = stream_context_create($opts);
    $responseBody = file_get_contents($baseUrl . $path, false, $ctx);

    return parseHttpResponse(
        $http_response_header ?? [],
        $responseBody !== false ? $responseBody : ''
    );
}

/**
 * Parse les headers bruts retournés par file_get_contents.
 *
 * @param  string[]  $rawHeaders
 * @return array{code:int,headers:array<string,string>,body:string,setCookies:array<string,string>}
 */
function parseHttpResponse(array $rawHeaders, string $body): array
{
    $code       = 0;
    $headers    = [];
    $setCookies = [];

    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+ (\d+)#i', $line, $m)) {
            $code = (int)$m[1];
            continue;
        }
        $sep = strpos($line, ':');
        if ($sep === false) {
            continue;
        }
        $name  = strtolower(trim(substr($line, 0, $sep)));
        $value = trim(substr($line, $sep + 1));

        if ($name === 'set-cookie') {
            // Extraire name=value avant le premier ';'
            $pair = explode(';', $value, 2)[0];
            $eq   = strpos($pair, '=');
            if ($eq !== false) {
                $cName  = trim(substr($pair, 0, $eq));
                $cValue = trim(substr($pair, $eq + 1));
                $setCookies[$cName] = $cValue;
            }
        } else {
            $headers[$name] = $value;
        }
    }

    return ['code' => $code, 'headers' => $headers, 'body' => $body, 'setCookies' => $setCookies];
}

/**
 * Génère les champs CSRF valides, signés comme app/src/Csrf.php.
 * tsOffset < 0 simule un formulaire déjà ouvert depuis quelques secondes
 * (timing check exige age >= 2 s côté serveur).
 *
 * @return array{_csrf:string,_ts:int,_ts_sig:string,website:string}
 */
function csrfTokens(string $cookie, int $tsOffset = -3): array
{
    global $secretCsrf;

    $csrf  = hash_hmac('sha256', $cookie, $secretCsrf);
    $ts    = time() + $tsOffset;
    $tsSig = hash_hmac('sha256', (string)$ts, $secretCsrf);

    return ['_csrf' => $csrf, '_ts' => $ts, '_ts_sig' => $tsSig, 'website' => ''];
}

/** Connexion PDO à la base SQLite de test. */
function dbConnect(): PDO
{
    global $dbPath;

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Purge les inscriptions dont le nom commence par "Test"
 * et supprime rate-limit.json du run courant.
 */
function resetEtat(): void
{
    global $ratePath;

    $pdo = dbConnect();
    $pdo->exec("DELETE FROM inscriptions WHERE nom LIKE 'Test%'");

    if (file_exists($ratePath)) {
        unlink($ratePath);
    }
}

// ---------------------------------------------------------------------------
// Compteurs PASS / FAIL
// ---------------------------------------------------------------------------

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void
{
    global $pass, $fail;

    if ($cond) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label\n";
    }
}

// ---------------------------------------------------------------------------
// Helper interne : suppression récursive du dossier temp
// ---------------------------------------------------------------------------

function supprimerDossier(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $chemin = $dir . '/' . $item;
        is_dir($chemin) ? supprimerDossier($chemin) : unlink($chemin);
    }
    rmdir($dir);
}

// ---------------------------------------------------------------------------
// Tests routes publiques
// ---------------------------------------------------------------------------

function runRoutesPubliques(): void
{
    $mois = '/mois/2026-05';

    // Redirect racine
    $r = http('GET', '/');
    ok($r['code'] === 303,                              'GET / → 303');
    ok(str_contains($r['headers']['location'] ?? '', '/mois/'), 'GET / → location contient /mois/');

    // Page mois valide
    $r = http('GET', $mois);
    ok($r['code'] === 200,                              "GET $mois → 200");
    ok(str_contains($r['body'], '<!doctype html'),      "GET $mois → body contient <!doctype html");

    // Mois invalide
    $r = http('GET', '/mois/abc');
    ok($r['code'] === 404,                              'GET /mois/abc → 404');

    // Jour inexistant sur base neuve
    $r = http('GET', '/jour/1');
    ok($r['code'] === 404,                              'GET /jour/1 → 404');

    // Page licence
    $r = http('GET', '/licence');
    ok($r['code'] === 200,                              'GET /licence → 200');
    ok(str_contains($r['body'], 'AGPL'),                'GET /licence → body contient AGPL');

    // 404 stylé (layout HTML)
    $r = http('GET', '/pas-existe');
    ok($r['code'] === 404,                              'GET /pas-existe → 404');
    ok(str_contains($r['body'], '<!doctype html'),      'GET /pas-existe → 404 avec layout HTML');

    // Headers de sécurité
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

    // ETag / 304
    $r1   = http('GET', $mois);
    $etag = $r1['headers']['etag'] ?? '';
    ok($etag !== '', "GET $mois → etag présent");

    $r2 = http('GET', $mois, [], [], ['If-None-Match' => $etag]);
    ok($r2['code'] === 304, "GET $mois avec If-None-Match → 304");
}

runRoutesPubliques();

// ---------------------------------------------------------------------------
// Tests inscription / désinscription
// ---------------------------------------------------------------------------

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

runInscriptions();

// ---------------------------------------------------------------------------
// Tests rate-limit
// ---------------------------------------------------------------------------

function runRateLimit(): void
{
    // --- Cas 1 : rate-limit inscription (bloquant à 10) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-01', '18:00', '22:30', 100)"
    );
    $idJour1 = (int)$pdo->lastInsertId();
    unset($pdo);

    $cookieInscr = bin2hex(random_bytes(16));

    for ($i = 1; $i <= 10; $i++) {
        $tokens = csrfTokens($cookieInscr);
        $r = http(
            'POST',
            "/jour/$idJour1/inscrire",
            array_merge(['nom' => "TestRL$i"], $tokens),
            ['csrf_session' => $cookieInscr]
        );
        ok($r['code'] === 303, "Rate-limit inscription — POST $i/10 → 303");
    }

    $tokens = csrfTokens($cookieInscr);
    $r = http(
        'POST',
        "/jour/$idJour1/inscrire",
        array_merge(['nom' => 'TestRL11'], $tokens),
        ['csrf_session' => $cookieInscr]
    );
    ok($r['code'] === 429, 'Rate-limit inscription — POST 11 → 429');

    $pdo  = dbConnect();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE nom LIKE 'TestRL%'");
    $stmt->execute();
    ok((int)$stmt->fetchColumn() === 10, 'Rate-limit inscription — 10 inscrits en DB (pas 11)');

    resetEtat();
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour1]);
    unset($pdoClean);

    // --- Cas 2 : rate-limit désinscription (bloquant à 30) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-02', '18:00', '22:30', 100)"
    );
    $idJour2 = (int)$pdo->lastInsertId();

    $inscrIds = [];
    for ($i = 1; $i <= 31; $i++) {
        $stmt = $pdo->prepare(
            "INSERT INTO inscriptions (jour_id, nom, est_voisine) VALUES (?, ?, 0)"
        );
        $stmt->execute([$idJour2, "TestDes$i"]);
        $inscrIds[] = (int)$pdo->lastInsertId();
    }
    unset($pdo, $stmt);

    $cookieDesinscr = bin2hex(random_bytes(16));

    for ($i = 0; $i < 30; $i++) {
        $tokens = csrfTokens($cookieDesinscr);
        $r = http(
            'POST',
            "/jour/$idJour2/desinscrire",
            array_merge(['inscription_id' => $inscrIds[$i]], $tokens),
            ['csrf_session' => $cookieDesinscr]
        );
        ok($r['code'] === 303, 'Rate-limit désinscription — POST ' . ($i + 1) . '/30 → 303');
    }

    $tokens = csrfTokens($cookieDesinscr);
    $r = http(
        'POST',
        "/jour/$idJour2/desinscrire",
        array_merge(['inscription_id' => $inscrIds[30]], $tokens),
        ['csrf_session' => $cookieDesinscr]
    );
    ok($r['code'] === 429, 'Rate-limit désinscription — POST 31 → 429');

    $pdo  = dbConnect();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE nom LIKE 'TestDes%'");
    $stmt->execute();
    ok((int)$stmt->fetchColumn() === 1, 'Rate-limit désinscription — 1 inscription restante en DB');

    resetEtat();
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour2]);
    unset($pdoClean);

    // --- Cas 3 : compteur erreurs CSRF (alerte, pas blocage) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-03', '18:00', '22:30', 100)"
    );
    $idJour3 = (int)$pdo->lastInsertId();
    unset($pdo);

    for ($i = 1; $i <= 51; $i++) {
        $r = http(
            'POST',
            "/jour/$idJour3/inscrire",
            ['_csrf' => 'bad', '_ts' => '0', '_ts_sig' => 'bad', 'website' => '', 'nom' => 'Test'],
            []
        );
        ok($r['code'] === 400, "Rate-limit CSRF — POST $i/51 → 400 (pas bloqué)");
    }

    global $ratePath;
    $rateData = file_exists($ratePath)
        ? json_decode((string)file_get_contents($ratePath), true)
        : null;

    $cleCsrfTrouvee = false;
    $nbTimestamps   = 0;
    if (is_array($rateData)) {
        foreach (array_keys($rateData) as $cle) {
            if (str_starts_with((string)$cle, 'csrf:')) {
                $cleCsrfTrouvee = true;
                $nbTimestamps   = count($rateData[$cle]);
                break;
            }
        }
    }
    ok($cleCsrfTrouvee, 'Rate-limit CSRF — clé csrf:* présente dans rate-limit.json');
    ok($nbTimestamps >= 50, "Rate-limit CSRF — >= 50 timestamps dans la clé csrf:* ($nbTimestamps)");

    resetEtat();
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour3]);
    unset($pdoClean);

    // --- Cas 4 : clé IPv4/IPv6 normalisée dans le store ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-04', '18:00', '22:30', 100)"
    );
    $idJour4 = (int)$pdo->lastInsertId();
    unset($pdo);

    $cookieIp = bin2hex(random_bytes(16));
    $tokens   = csrfTokens($cookieIp);
    $r = http(
        'POST',
        "/jour/$idJour4/inscrire",
        array_merge(['nom' => 'TestIPv4'], $tokens),
        ['csrf_session' => $cookieIp]
    );
    ok($r['code'] === 303, 'Rate-limit clé IP — POST inscription → 303');

    $rateData2 = file_exists($ratePath)
        ? json_decode((string)file_get_contents($ratePath), true)
        : null;

    $cleIpTrouvee = false;
    if (is_array($rateData2)) {
        foreach (array_keys($rateData2) as $cle) {
            $cleStr = (string)$cle;
            if ($cleStr === '127.0.0.1' || str_ends_with($cleStr, '/64')) {
                $cleIpTrouvee = true;
                break;
            }
        }
    }
    ok($cleIpTrouvee, 'Rate-limit clé IP — clé 127.0.0.1 ou ::/64 dans rate-limit.json');

    resetEtat();
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour4]);
    unset($pdoClean);
}

runRateLimit();

// ---------------------------------------------------------------------------
// Tests expiration CSRF
// ---------------------------------------------------------------------------

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

    $fabriquerTokens = function (int $ts) use ($cookieCsrf, $csrf, $secretCsrf): array {
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

runCsrfExpiration();

// ---------------------------------------------------------------------------
// Tests auth admin (phase 6)
// ---------------------------------------------------------------------------

/**
 * Tue le serveur courant, injecte un nouveau hash dans la config (en remplaçant
 * ADMIN_PASSWORD_HASH en place), relance le serveur sur le même port, attend
 * qu'il réponde (max 5s). Passer '' pour laisser la valeur actuelle inchangée.
 */
function redemarrerServeur(string $nouvelleValeurHash): void
{
    global $serverProc, $configPath, $port, $wwwDir, $baseUrl;

    $status = proc_get_status($serverProc);
    if ($status['running'] ?? false) {
        proc_terminate($serverProc);
    }
    proc_close($serverProc);

    // Petit délai pour laisser l'OS libérer le port.
    usleep(300_000);

    // Remplace la valeur de ADMIN_PASSWORD_HASH sans passer par preg_replace :
    // le hash bcrypt contient des '$' que preg_replace interpréterait comme
    // des backreferences dans la chaîne de remplacement.
    if ($nouvelleValeurHash !== '') {
        $contenuActuel = (string)file_get_contents($configPath);
        $contenuMaj = preg_replace_callback(
            "#(const ADMIN_PASSWORD_HASH = ')[^']*(';\n)#",
            static function (array $m) use ($nouvelleValeurHash): string {
                return $m[1] . $nouvelleValeurHash . $m[2];
            },
            $contenuActuel
        ) ?? $contenuActuel;
        file_put_contents($configPath, $contenuMaj);
    }

    $serverDescriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', dirname($configPath) . '/server.log', 'w'],
        2 => ['file', dirname($configPath) . '/server.err', 'w'],
    ];
    $serverEnv = array_merge(getenv() ?: [], [
        'APP_CONFIG_OVERRIDE' => $configPath,
    ]);
    $serverCmd = 'php -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($wwwDir)
        . ' ' . escapeshellarg($wwwDir . '/_router.php');

    $serverProc = proc_open($serverCmd, $serverDescriptors, $pipes, null, $serverEnv);
    if ($serverProc === false) {
        fwrite(STDERR, "redemarrerServeur : proc_open échoué\n");
        exit(1);
    }

    $pret     = false;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $ctx   = stream_context_create(['http' => [
            'method'          => 'GET',
            'ignore_errors'   => true,
            'timeout'         => 0.5,
            'follow_location' => 0,
        ]]);
        $probe = @file_get_contents("$baseUrl/", false, $ctx);
        if ($probe !== false || !empty($http_response_header)) {
            $pret = true;
            break;
        }
        usleep(100_000);
    }
    if (!$pret) {
        fwrite(STDERR, "redemarrerServeur : serveur ne répond pas après 5 s\n");
        exit(1);
    }
}

/**
 * Réécrit $configPath avec la config initiale (sans ADMIN_PASSWORD_HASH surchargé)
 * et relance le serveur. Appelée en fin de runAdminAuth().
 */
function restaurerConfigInitiale(): void
{
    global $configPath, $baseUrl, $secretCsrf, $dbPath, $port, $wwwDir;

    file_put_contents($configPath, sprintf(
        "<?php\ndeclare(strict_types=1);\n"
        . "const BASE_URL    = %s;\n"
        . "const DATA_DIR    = %s;\n"
        . "const DB_PATH     = %s;\n"
        . "const SECRET_CSRF = %s;\n"
        . "const MAIL_FROM   = 'test@localhost';\n"
        . "const SSE_ENABLED = false;\n"
        . "const DISCORD_WEBHOOK_URL = '';\n"
        . "const ADMIN_PASSWORD_HASH = '';\n"
        . "const ASSO_NOM_DEFAUT      = 'TestAsso';\n"
        . "const ASSO_LOGO_URL_DEFAUT = '';\n"
        . "date_default_timezone_set('Europe/Paris');\n",
        var_export($baseUrl, true),
        var_export(dirname($configPath), true),
        var_export($dbPath, true),
        var_export($secretCsrf, true)
    ));

    redemarrerServeur('');
}

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

runAdminAuth();

// ---------------------------------------------------------------------------
// Récap final
// ---------------------------------------------------------------------------

echo "\n$pass PASS / $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
