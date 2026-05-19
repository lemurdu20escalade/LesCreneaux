<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Rate-limit IP sur les inscriptions, état persisté dans
// DATA_DIR/rate-limit.json. Anti-spam, pas firewall : l'appelant peut
// décider de fail-open si l'I/O échoue (cf. www/index.php).
//
// Note proxy/CDN : on utilise REMOTE_ADDR brut (cf. www/index.php). Si
// l'instance passe un jour derrière un proxy de confiance, factoriser
// une fonction clientIp() avant d'appeler ici, sinon toutes les
// inscriptions partagent un quota commun.
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

    // Compteur séparé pour les POST /inscrire avec CSRF KO : sans ce
    // suivi, un attaquant qui flood des requêtes mal signées passe sous
    // le radar — le check CSRF rejette en 400 AVANT que verifierInscription
    // soit appelé, donc le compteur principal n'est jamais incrémenté.
    // On alerte au-delà de SEUIL_ERREURS_CSRF erreurs / fenêtre / IP.
    private const PREFIXE_ERREUR_CSRF  = 'csrf:';
    private const SEUIL_ERREURS_CSRF   = 50;

    // Désinscriptions : la friction zéro est l'esprit du projet (un·e
    // référent·e peut nettoyer un créneau avant la séance). On laisse
    // donc une marge large, juste assez pour couper net un vandalisme
    // massif (effacer un mois entier = 45+ désinscriptions).
    private const PREFIXE_DESINSCR      = 'desinscr:';
    private const LIMITE_DESINSCRIPTION = 30;

    /**
     * True si l'IP peut s'inscrire, false si la limite est atteinte.
     * Enregistre l'événement uniquement quand l'accès est autorisé.
     * Lève RuntimeException si l'I/O sur l'état est impossible —
     * l'appelant choisit fail-open ou fail-closed.
     */
    public static function verifierInscription(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $ip   = self::normaliserIp($ip);
        $path = DATA_DIR . '/rate-limit.json';
        $fh   = self::ouvrirVerrouille($path);

        $now        = time();
        $data       = self::purgerGlobal(self::lireDonnees($fh), $now);
        $timestamps = $data[$ip] ?? [];

        if (count($timestamps) >= self::LIMITE_PAR_FENETRE) {
            self::libererEtFermer($fh);
            return false;
        }

        $burstApres   = self::compterBurst($timestamps, $now) + 1;
        $timestamps[] = $now;
        $data[$ip]    = $timestamps;

        self::ecrireDonnees($fh, $data);
        self::libererEtFermer($fh);

        // Notifier uniquement au franchissement du seuil — évite que
        // les hits suivants dans la même fenêtre burst noient le canal
        // Discord (la limite est de 30 msg/min/webhook côté Discord).
        if ($burstApres === self::BURST_SEUIL) {
            self::notifier(sprintf(
                'Burst inscriptions : %d en %d s depuis %s',
                $burstApres, self::BURST_WINDOW_SECONDS, $ip
            ));
        }

        return true;
    }

    /**
     * True si l'IP peut désinscrire, false si la limite est atteinte.
     * Pas de burst Discord : la désinscription est anonyme par design et
     * un usage normal peut atteindre quelques actions rapides (référent·e
     * qui nettoie un créneau avant la séance) — l'alerte serait du bruit.
     * Le 429 reste, et data/rate-limit.json garde la trace.
     */
    public static function verifierDesinscription(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $ip   = self::normaliserIp($ip);
        $path = DATA_DIR . '/rate-limit.json';
        $fh   = self::ouvrirVerrouille($path);

        $now        = time();
        $data       = self::purgerGlobal(self::lireDonnees($fh), $now);
        $cle        = self::PREFIXE_DESINSCR . $ip;
        $timestamps = $data[$cle] ?? [];

        if (count($timestamps) >= self::LIMITE_DESINSCRIPTION) {
            self::libererEtFermer($fh);
            return false;
        }

        $timestamps[] = $now;
        $data[$cle]   = $timestamps;
        self::ecrireDonnees($fh, $data);
        self::libererEtFermer($fh);
        return true;
    }

    /**
     * Incrémente le compteur d'erreurs CSRF pour cette IP, alerte au
     * franchissement de SEUIL_ERREURS_CSRF. Fail-open silencieux : si
     * l'I/O échoue, on log mais on ne lève pas — on est déjà dans le
     * chemin d'erreur du handler, pas le moment de tuer la réponse 400.
     */
    public static function compterErreurCsrf(string $ip): void
    {
        if ($ip === '') {
            return;
        }
        try {
            self::enregistrerErreurCsrf(self::normaliserIp($ip));
        } catch (RuntimeException $e) {
            error_log('RateLimit erreur CSRF dégradé : ' . $e->getMessage());
        }
    }

    private static function enregistrerErreurCsrf(string $ip): void
    {
        $cle  = self::PREFIXE_ERREUR_CSRF . $ip;
        $path = DATA_DIR . '/rate-limit.json';
        $fh   = self::ouvrirVerrouille($path);

        $now        = time();
        $data       = self::purgerGlobal(self::lireDonnees($fh), $now);
        $timestamps = $data[$cle] ?? [];
        $timestamps[] = $now;
        $data[$cle] = $timestamps;

        self::ecrireDonnees($fh, $data);
        self::libererEtFermer($fh);

        // Alerte au franchissement exact, pas à chaque hit au-delà —
        // sinon un attaquant qui maintient le flood spammerait le canal.
        if (count($timestamps) === self::SEUIL_ERREURS_CSRF) {
            self::notifier(sprintf(
                'Burst erreurs CSRF : %d en %d s depuis %s (brute-force probable)',
                self::SEUIL_ERREURS_CSRF, self::FENETRE_SECONDS, $ip
            ));
        }
    }

    /**
     * IPv6 → préfixe /64. Un FAI grand public route un /64 entier à un
     * abonné ; sans masque, un attaquant rote son IP pour bypass total.
     * IPv4 inchangé. Format inconnu : retourne tel quel (les tentatives
     * ultérieures auront la même clé, donc le rate-limit s'applique).
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
     * Purge toutes les IPs (pas seulement la courante). Sans ça, une IP
     * vue une seule fois reste dans le fichier indéfiniment — combiné
     * avec une rotation d'IP, le fichier grossit linéairement et plombe
     * chaque écriture sous flock.
     *
     * @param array<string, mixed> $data
     * @return array<string, list<int>>
     */
    private static function purgerGlobal(array $data, int $now): array
    {
        $limite = $now - self::FENETRE_SECONDS;
        $out    = [];
        foreach ($data as $cle => $timestamps) {
            if (!is_array($timestamps)) {
                continue;
            }
            $clean = array_values(array_filter(
                $timestamps,
                static fn($t) => is_int($t) && $t > $limite
            ));
            if ($clean !== []) {
                $out[$cle] = $clean;
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
            static fn(int $ts) => $ts > $limite
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
        flock($fh, LOCK_UN);
        fclose($fh);
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

    /** @param array<string, mixed> $data */
    private static function ecrireDonnees(mixed $fh, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, (string)$json);
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
