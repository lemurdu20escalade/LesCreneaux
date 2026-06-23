<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Harnais de tests d'intégration — standalone, sans Composer ni PHPUnit.
// Chaque run crée un répertoire temp isolé et un serveur builtin éphémère.
//
// La config de test est injectée via APP_CONFIG_OVERRIDE (env passée au
// processus serveur). www/index.php la lit à la place de app/config.php.
// Le data/ du dev n'est jamais touché.
//
// Structure :
//   tests/lib/        helpers réutilisables (HTTP, DB, assertions, serveur)
//   tests/scenarios/  un fichier par scénario, fonction run*() unique
//   tests/integration.php  ce fichier : init + boot + orchestration

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/server.php';

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

// Port : on laisse l'OS choisir en bindant sur 0, puis on récupère le numéro.
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

ecrireConfig($configPath, $baseUrl, $tmpDir, $dbPath, $secretCsrf, '');

// ---------------------------------------------------------------------------
// Lancement du serveur builtin + cleanup
// ---------------------------------------------------------------------------

$serverProc = lancerServeur($port, $wwwDir, $configPath, $tmpDir);

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

if (function_exists('pcntl_signal')) {
    $handler = static function (): void { exit(0); };
    pcntl_signal(SIGINT,  $handler);
    pcntl_signal(SIGTERM, $handler);
}

if (!attendreServeurPret($baseUrl, 5.0)) {
    fwrite(STDERR, "Le serveur builtin ne répond pas après 5 s\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Scénarios
// ---------------------------------------------------------------------------

require_once __DIR__ . '/scenarios/01-routes.php';
require_once __DIR__ . '/scenarios/02-inscriptions.php';
require_once __DIR__ . '/scenarios/03-rate-limit.php';
require_once __DIR__ . '/scenarios/04-csrf-expiration.php';
require_once __DIR__ . '/scenarios/05-admin-auth.php';
require_once __DIR__ . '/scenarios/06-sans-referent.php';
require_once __DIR__ . '/scenarios/07-note-multiligne.php';
require_once __DIR__ . '/scenarios/08-inscription-bloquee.php';
require_once __DIR__ . '/scenarios/09-mise-a-jour.php';
require_once __DIR__ . '/scenarios/10-mise-a-jour-unit.php';

runRoutesPubliques();
runInscriptions();
runRateLimit();
runCsrfExpiration();
runAdminAuth();
runSansReferent();
runNoteMultiligne();
runInscriptionBloquee();
runMiseAJour();
runMiseAJourUnit();

// ---------------------------------------------------------------------------
// Récap final
// ---------------------------------------------------------------------------

echo "\n$pass PASS / $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
