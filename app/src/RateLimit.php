<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Rate-limit IP sur les écritures publiques (inscription, désinscription)
// et le login admin. Anti-spam, pas firewall : les routes publiques
// laissent passer si l'I/O sur l'état échoue, c'est l'appelant qui
// choisit (cf. www/index.php).
//
// État persisté dans DATA_DIR/rate-limit.json, sérialisé par flock LOCK_EX
// avec try/finally pour garantir que le verrou est rendu même si une
// exception est levée entre l'acquisition et la libération.
//
// Note proxy/CDN : on utilise REMOTE_ADDR brut (cf. www/index.php). Si
// l'instance passe un jour derrière un proxy de confiance, factoriser
// une fonction clientIp() avant d'appeler ici, sinon toutes les requêtes
// partagent un quota commun.
//
// Note flock : verrou advisory, supposé sur FS local. Pas garanti sur
// NFS exotique de mutualisé ; si l'asso migre vers ce type d'héberge-
// ment, repenser le stockage (table SQLite serait plus sûre).

final class RateLimit
{
    private const FENETRE_SECONDS      = 600;
    private const LIMITE_PAR_FENETRE   = 10;
    private const BURST_WINDOW_SECONDS = 60;
    private const BURST_SEUIL          = 5;

    // Compteur séparé pour les POST avec CSRF KO : sans ce suivi, un
    // attaquant qui flood des requêtes mal signées passe sous le radar —
    // le check CSRF rejette en 400 AVANT que verifier* soit appelé,
    // donc le compteur principal n'est jamais incrémenté.
    private const PREFIXE_ERREUR_CSRF  = 'csrf:';
    private const SEUIL_ERREURS_CSRF   = 50;

    // Désinscriptions : la friction zéro est l'esprit du projet (un·e
    // référent·e peut nettoyer un créneau avant la séance). On laisse
    // une marge large par IP, juste assez pour couper net un vandalisme
    // massif (effacer un mois entier = 45+ désinscriptions).
    private const PREFIXE_DESINSCR      = 'desinscr:';
    private const LIMITE_DESINSCRIPTION = 30;

    // Compteur global (toutes IPs confondues) qui sert d'alerte en cas
    // de rotation d'IP (un attaquant qui contrôle un /56 IPv6 peut
    // contourner le quota par-IP en alternant 256 préfixes /64). On
    // n'utilise PAS ce compteur pour bloquer : un événement légitime
    // (groupe scolaire qui se désinscrit) peut générer des rafales. On
    // alerte juste l'admin pour qu'il regarde data/rate-limit.json.
    private const CLE_GLOBAL_DESINSCR    = '_total:desinscr';
    private const SEUIL_GLOBAL_DESINSCR  = 100;

    // Brute-force /admin/login. Sans ce compteur, un attaquant peut
    // saturer les workers PHP-FPM avec des tentatives parallèles ; le
    // sleep(1) côté AdminAuth retient un worker pendant 1 s par essai.
    private const PREFIXE_ECHEC_LOGIN = 'login:';
    private const LIMITE_ECHECS_LOGIN = 10;
    private const BURST_ECHEC_LOGIN   = 5;

    public static function verifierInscription(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $ip = self::normaliserIp($ip);
        return self::sousVerrou(function (array $data) use ($ip): array {
            $now        = time();
            $data       = self::purgerGlobal($data, $now);
            $timestamps = self::tsListe($data[$ip] ?? null);

            if (count($timestamps) >= self::LIMITE_PAR_FENETRE) {
                return ['result' => false, 'data' => $data, 'after' => null];
            }
            $burstApres   = self::compterBurst($timestamps, $now) + 1;
            $timestamps[] = $now;
            $data[$ip]    = $timestamps;
            $after        = $burstApres === self::BURST_SEUIL
                ? fn(): null => self::notifier(sprintf(
                    'Burst inscriptions : %d en %d s depuis %s',
                    $burstApres, self::BURST_WINDOW_SECONDS, $ip
                ))
                : null;
            return ['result' => true, 'data' => $data, 'after' => $after];
        });
    }

    public static function verifierDesinscription(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $ip = self::normaliserIp($ip);
        return self::sousVerrou(function (array $data) use ($ip): array {
            $now        = time();
            $data       = self::purgerGlobal($data, $now);
            $cle        = self::PREFIXE_DESINSCR . $ip;
            $timestamps = self::tsListe($data[$cle] ?? null);

            if (count($timestamps) >= self::LIMITE_DESINSCRIPTION) {
                return ['result' => false, 'data' => $data, 'after' => null];
            }
            $timestamps[]               = $now;
            $data[$cle]                 = $timestamps;
            $global                     = self::tsListe($data[self::CLE_GLOBAL_DESINSCR] ?? null);
            $global[]                   = $now;
            $data[self::CLE_GLOBAL_DESINSCR] = $global;

            // Alerte au franchissement exact du seuil global, pour
            // détecter une rotation d'IP qui passerait sous le quota
            // par-IP. Non bloquant.
            $after = count($global) === self::SEUIL_GLOBAL_DESINSCR
                ? fn(): null => self::notifier(sprintf(
                    'Désinscriptions inhabituelles : %d en %d s toutes IPs '
                    . 'confondues (rotation d\'IP probable)',
                    self::SEUIL_GLOBAL_DESINSCR, self::FENETRE_SECONDS
                ))
                : null;
            return ['result' => true, 'data' => $data, 'after' => $after];
        });
    }

    public static function compterErreurCsrf(string $ip): void
    {
        if ($ip === '') {
            return;
        }
        $ip = self::normaliserIp($ip);
        try {
            self::sousVerrou(function (array $data) use ($ip): array {
                $now        = time();
                $data       = self::purgerGlobal($data, $now);
                $cle        = self::PREFIXE_ERREUR_CSRF . $ip;
                $timestamps = self::tsListe($data[$cle] ?? null);
                $timestamps[] = $now;
                $data[$cle] = $timestamps;
                $after = count($timestamps) === self::SEUIL_ERREURS_CSRF
                    ? fn(): null => self::notifier(sprintf(
                        'Burst erreurs CSRF : %d en %d s depuis %s '
                        . '(brute-force probable)',
                        self::SEUIL_ERREURS_CSRF, self::FENETRE_SECONDS, $ip
                    ))
                    : null;
                return ['result' => true, 'data' => $data, 'after' => $after];
            });
        } catch (RuntimeException $e) {
            // Fail-open silencieux : on est déjà dans le chemin d'erreur
            // (CSRF KO → 400), pas le moment de transformer en 500.
            error_log('RateLimit erreur CSRF dégradé : ' . $e->getMessage());
        }
    }

    public static function verifierEchecLogin(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $ip = self::normaliserIp($ip);
        return self::sousVerrou(function (array $data) use ($ip): array {
            $data       = self::purgerGlobal($data, time());
            $cle        = self::PREFIXE_ECHEC_LOGIN . $ip;
            $timestamps = self::tsListe($data[$cle] ?? null);
            $autorise   = count($timestamps) < self::LIMITE_ECHECS_LOGIN;
            return ['result' => $autorise, 'data' => $data, 'after' => null];
        });
    }

    public static function compterEchecLogin(string $ip): void
    {
        if ($ip === '') {
            return;
        }
        $ip = self::normaliserIp($ip);
        try {
            self::sousVerrou(function (array $data) use ($ip): array {
                $now        = time();
                $data       = self::purgerGlobal($data, $now);
                $cle        = self::PREFIXE_ECHEC_LOGIN . $ip;
                $timestamps = self::tsListe($data[$cle] ?? null);
                $burstApres = self::compterBurst($timestamps, $now) + 1;
                $timestamps[] = $now;
                $data[$cle] = $timestamps;
                $after = $burstApres === self::BURST_ECHEC_LOGIN
                    ? fn(): null => self::notifier(sprintf(
                        'Burst échecs login admin : %d en %d s depuis %s '
                        . '(brute-force probable)',
                        $burstApres, self::BURST_WINDOW_SECONDS, $ip
                    ))
                    : null;
                return ['result' => true, 'data' => $data, 'after' => $after];
            });
        } catch (RuntimeException $e) {
            error_log('RateLimit échec login dégradé : ' . $e->getMessage());
        }
    }

    /**
     * Charge l'état, exécute $action (qui retourne la mise à jour) et
     * écrit le nouvel état, le tout sous LOCK_EX avec try/finally pour
     * garantir la libération du verrou et la fermeture du handle même
     * en cas d'exception dans $action.
     *
     * $action retourne ['result' => bool, 'data' => array, 'after' => ?callable].
     * 'after' est exécuté hors-verrou pour ne pas bloquer d'autres
     * requêtes pendant un éventuel POST Discord.
     */
    private static function sousVerrou(callable $action): bool
    {
        $path = DATA_DIR . '/rate-limit.json';
        $fh   = self::ouvrirVerrouille($path);
        try {
            $update = $action(self::lireDonnees($fh));
            self::ecrireDonnees($fh, $update['data']);
        } finally {
            self::libererEtFermer($fh);
        }
        if ($update['after'] !== null) {
            ($update['after'])();
        }
        return (bool)$update['result'];
    }

    /**
     * IPv6 → préfixe /64. Un FAI grand public route un /64 entier à un
     * abonné ; sans masque, un attaquant rote son IP pour bypass total.
     * IPv4 inchangé. Format inconnu : retourne tel quel.
     */
    private static function normaliserIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 16) {
            $masque = substr($bin, 0, 8) . str_repeat("\0", 8);
            $norm   = @inet_ntop($masque);
            return $norm !== false ? $norm . '/64' : $ip;
        }
        return $ip;
    }

    /**
     * Purge toutes les IPs/clés (pas seulement la courante). Sans ça, une
     * IP vue une seule fois reste indéfiniment et le fichier grossit.
     *
     * @param array<string, mixed> $data
     * @return array<string, list<int>>
     */
    private static function purgerGlobal(array $data, int $now): array
    {
        $limite = $now - self::FENETRE_SECONDS;
        $out    = [];
        foreach ($data as $cle => $timestamps) {
            $clean = self::tsListe($timestamps);
            $clean = array_values(array_filter(
                $clean,
                static fn(int $t): bool => $t > $limite
            ));
            if ($clean !== []) {
                $out[$cle] = $clean;
            }
        }
        return $out;
    }

    /**
     * Filtre une valeur quelconque en une list<int>. Tolère qu'une clé
     * du fichier JSON ait été corrompue à la main ou par un bug ancien.
     *
     * @return list<int>
     */
    private static function tsListe(mixed $brut): array
    {
        if (!is_array($brut)) {
            return [];
        }
        $out = [];
        foreach ($brut as $v) {
            if (is_int($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @param list<int> $timestamps */
    private static function compterBurst(array $timestamps, int $now): int
    {
        $limite = $now - self::BURST_WINDOW_SECONDS;
        return count(array_filter(
            $timestamps,
            static fn(int $ts): bool => $ts > $limite
        ));
    }

    private static function ouvrirVerrouille(string $path): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('RateLimit: impossible de créer le répertoire : ' . $dir);
        }
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            throw new RuntimeException('RateLimit: impossible d\'ouvrir : ' . $path);
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException('RateLimit: impossible d\'acquérir le verrou : ' . $path);
        }
        return $fh;
    }

    private static function libererEtFermer(mixed $fh): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    /** @return array<string, mixed> */
    private static function lireDonnees(mixed $fh): array
    {
        rewind($fh);
        $raw = stream_get_contents($fh);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sérialise et écrit. Si ftruncate ou fwrite échoue (disque plein,
     * quota), on lève — laisser un fichier corrompu silencieusement
     * désactiverait le rate-limit jusqu'à la prochaine purge naturelle.
     *
     * @param array<string, mixed> $data
     */
    private static function ecrireDonnees(mixed $fh, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('RateLimit: json_encode échoué : ' . json_last_error_msg());
        }
        rewind($fh);
        if (ftruncate($fh, 0) === false) {
            throw new RuntimeException('RateLimit: ftruncate échoué');
        }
        $ecrits = fwrite($fh, $json);
        if ($ecrits === false || $ecrits !== strlen($json)) {
            throw new RuntimeException('RateLimit: fwrite incomplet ('
                . ($ecrits === false ? 'false' : $ecrits) . '/'
                . strlen($json) . ' octets)');
        }
        fflush($fh);
    }

    /**
     * Alerte locale + Discord. Log systématique pour garder une trace
     * même si DISCORD_WEBHOOK_URL est vide ou si Discord est KO.
     */
    private static function notifier(string $resume): void
    {
        error_log('RateLimit : ' . $resume);

        // constant() retourne mixed, ce qui empêche PHPStan d'inférer
        // depuis la valeur '' du bootstrap config.example que cette
        // comparaison est toujours vraie.
        if (!defined('DISCORD_WEBHOOK_URL')) {
            return;
        }
        $webhook = (string) constant('DISCORD_WEBHOOK_URL');
        if ($webhook === '') {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $quand   = (new DateTimeImmutable('now'))->format('d/m H:i');
        $content = "[RATE-LIMIT] {$resume} · {$quand}";

        $payload = json_encode(
            ['content' => $content],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
        ]);
        @curl_exec($ch);
    }
}
