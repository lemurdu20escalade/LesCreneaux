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

register_shutdown_function(function () use ($serverProc, $tmpDir): void {
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
// Récap final
// ---------------------------------------------------------------------------

echo "\n$pass PASS / $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
