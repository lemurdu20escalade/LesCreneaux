<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Gestion du serveur builtin PHP utilisé par le harnais : génération de la
// config injectée via APP_CONFIG_OVERRIDE, redémarrage entre deux scénarios
// (nécessaire pour basculer le mode auth admin sans Composer ni FFI), et
// suppression récursive du dossier temp en fin de run.

/**
 * Écrit $configPath avec la config de test minimale. $hash injecte
 * la valeur d'ADMIN_PASSWORD_HASH (vide = mode compat).
 */
function ecrireConfig(string $configPath, string $baseUrl, string $dataDir, string $dbPath, string $secretCsrf, string $hash = ''): void
{
    file_put_contents($configPath, sprintf(
        "<?php\ndeclare(strict_types=1);\n"
        . "const BASE_URL    = %s;\n"
        . "const DATA_DIR    = %s;\n"
        . "const DB_PATH     = %s;\n"
        . "const SECRET_CSRF = %s;\n"
        . "const MAIL_FROM   = 'test@localhost';\n"
        . "const SSE_ENABLED = false;\n"
        . "const DISCORD_WEBHOOK_URL = '';\n"
        . "const ADMIN_PASSWORD_HASH = %s;\n"
        . "const ASSO_NOM_DEFAUT      = 'TestAsso';\n"
        . "const ASSO_LOGO_URL_DEFAUT = '';\n"
        . "date_default_timezone_set('Europe/Paris');\n",
        var_export($baseUrl, true),
        var_export($dataDir, true),
        var_export($dbPath, true),
        var_export($secretCsrf, true),
        var_export($hash, true)
    ));
}

/**
 * Lance un nouveau php -S sur 127.0.0.1:$port, configure le pipe vers
 * server.log / server.err dans le tmpDir. Retourne la resource proc_open.
 *
 * @return resource
 */
function lancerServeur(int $port, string $wwwDir, string $configPath, string $tmpDir)
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $tmpDir . '/server.log', 'w'],
        2 => ['file', $tmpDir . '/server.err', 'w'],
    ];
    $env = array_merge(getenv() ?: [], ['APP_CONFIG_OVERRIDE' => $configPath]);
    $cmd = 'php -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($wwwDir)
        . ' ' . escapeshellarg($wwwDir . '/_router.php');

    $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
    if ($proc === false) {
        fwrite(STDERR, "lancerServeur : proc_open échoué\n");
        exit(1);
    }
    return $proc;
}

/**
 * Poll GET / sur $baseUrl jusqu'au succès ou timeout de $timeoutS secondes.
 * Variable locale $http_response_header pour ne pas hériter d'un appel
 * précédent dans le scope global.
 */
function attendreServeurPret(string $baseUrl, float $timeoutS = 5.0): bool
{
    $deadline = microtime(true) + $timeoutS;
    while (microtime(true) < $deadline) {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'ignore_errors'   => true,
            'timeout'         => 0.5,
            'follow_location' => 0,
        ]]);
        $probe = @file_get_contents("$baseUrl/", false, $ctx);
        if ($probe !== false) {
            return true;
        }
        usleep(100_000);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
    return false;
}

/**
 * Tue le serveur courant, injecte un nouveau hash dans la config (en
 * remplaçant ADMIN_PASSWORD_HASH en place), relance sur un nouveau port
 * libre. Passer '' pour laisser la valeur actuelle inchangée. Le port
 * change à chaque appel pour éviter le TIME_WAIT sur Linux CI.
 */
function redemarrerServeur(string $nouvelleValeurHash): void
{
    global $serverProc, $configPath, $port, $wwwDir, $baseUrl, $tmpDir;

    if (proc_get_status($serverProc)['running'] ?? false) {
        proc_terminate($serverProc);
    }
    $deadlineKill = microtime(true) + 3.0;
    while (microtime(true) < $deadlineKill) {
        if (!(proc_get_status($serverProc)['running'] ?? false)) {
            break;
        }
        usleep(50_000);
    }
    proc_close($serverProc);

    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($sock, '127.0.0.1', 0);
    socket_getsockname($sock, $addr, $portLibre);
    socket_close($sock);
    $port    = (int)$portLibre;
    $baseUrl = "http://127.0.0.1:$port";

    // Le hash bcrypt contient des '$' que preg_replace interpréterait
    // comme des backreferences — d'où les callbacks. BASE_URL est patché
    // à chaque restart pour rester aligné sur le nouveau port.
    $contenu    = (string)file_get_contents($configPath);
    $contenu    = preg_replace_callback(
        "#(const BASE_URL    = ')[^']*(';\n)#",
        static fn(array $m): string => $m[1] . $baseUrl . $m[2],
        $contenu
    ) ?? $contenu;
    if ($nouvelleValeurHash !== '') {
        $contenu = preg_replace_callback(
            "#(const ADMIN_PASSWORD_HASH = ')[^']*(';\n)#",
            static fn(array $m): string => $m[1] . $nouvelleValeurHash . $m[2],
            $contenu
        ) ?? $contenu;
    }
    file_put_contents($configPath, $contenu);

    $serverProc = lancerServeur($port, $wwwDir, $configPath, $tmpDir);

    if (!attendreServeurPret($baseUrl, 5.0)) {
        fwrite(STDERR, "redemarrerServeur : serveur ne répond pas après 5 s\n");
        exit(1);
    }
}

/**
 * Remet ADMIN_PASSWORD_HASH à vide dans la config et relance le serveur.
 * Appelée en fin de runAdminAuth() pour que le scénario suivant retrouve
 * un état propre. BASE_URL est mis à jour par redemarrerServeur().
 */
function restaurerConfigInitiale(): void
{
    global $configPath;

    $contenu = (string)file_get_contents($configPath);
    $contenu = preg_replace_callback(
        "#(const ADMIN_PASSWORD_HASH = ')[^']*(';\n)#",
        static fn(array $m): string => $m[1] . $m[2],
        $contenu
    ) ?? $contenu;
    file_put_contents($configPath, $contenu);

    redemarrerServeur('');
}

/** Suppression récursive — appelée par register_shutdown_function. */
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
