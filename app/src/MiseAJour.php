<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Notification de mise à jour — passive, fail-open, zéro écriture sur le code.
//
// banniere() lit l'état caché et le compare à Version::APP : retourne de quoi
// afficher un bandeau « version X disponible », ou null. Pure, sans réseau.
//
// rafraichirSiNecessaire() est appelé en fin de handler /reglages : si le cache
// a dépassé le TTL, flush la réponse déjà rendue puis interroge l'API GitHub
// releases/latest et réécrit l'état. Tout échec réseau est silencieux : le
// check est un confort, jamais une dépendance fonctionnelle.
//
// État dans DATA_DIR/maj-state.json : { checked_at, version, url, etag }.

final class MiseAJour
{
    private const DEPOT           = 'lemurdu20escalade/LesCreneaux';
    private const TTL_SECONDS     = 86400; // 24 h
    private const TIMEOUT_CONNECT = 2;
    private const TIMEOUT_TOTAL   = 3;

    /**
     * Bandeau à afficher, ou null si on est à jour / pas d'info. Lecture seule
     * du cache + version_compare, aucun accès réseau.
     *
     * @return array{version: string, url: string}|null
     */
    public static function banniere(): ?array
    {
        if (!self::actif()) {
            return null;
        }
        $etat     = self::chargerEtat();
        $derniere = (string)($etat['version'] ?? '');
        if ($derniere === '' || version_compare(Version::APP, $derniere, '>=')) {
            return null;
        }
        // Revalidation au rendu : l'URL vient du cache disque (potentiellement
        // forgé) et part dans un href. e() n'arrête pas un schéma javascript:.
        return [
            'version' => $derniere,
            'url'     => self::urlSure((string)($etat['url'] ?? '')),
        ];
    }

    /**
     * Rafraîchit le cache depuis GitHub si le TTL est dépassé. À appeler APRÈS
     * le rendu : flush la réponse au client, puis fait l'appel réseau pour que
     * sa latence n'impacte jamais l'affichage. No-op si désactivé, sous le
     * serveur builtin (pas de flush fiable), ou si le cache est encore frais.
     */
    public static function rafraichirSiNecessaire(): void
    {
        if (!self::actif() || PHP_SAPI === 'cli-server') {
            return;
        }
        $etat = self::chargerEtat();
        if (time() - (int)($etat['checked_at'] ?? 0) < self::TTL_SECONDS) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // Best-effort sous mod_php (pas de fastcgi_finish_request) : on pousse
            // le HTML déjà bufferisé vers le client. Boucle bornée sur le niveau
            // initial — un ob_end_flush() qui échoue ne décrémente pas le niveau
            // et bouclerait à l'infini sinon.
            for ($niveau = ob_get_level(); $niveau > 0; $niveau--) {
                @ob_end_flush();
            }
            @flush();
        }

        self::interroger($etat);
    }

    private static function actif(): bool
    {
        // Cast booléen tolérant : seul un false explicite (la valeur documentée
        // pour désactiver) éteint la feature ; true/1/'1' l'activent.
        return !defined('MAJ_CHECK') || (bool)constant('MAJ_CHECK');
    }

    /** @param array<string, mixed> $etat */
    private static function interroger(array $etat): void
    {
        $reponse = self::fetch((string)($etat['etag'] ?? ''));
        if ($reponse === null) {
            return; // réseau injoignable : on retentera au prochain /reglages
        }
        [$code, $corps, $etag] = $reponse;
        self::sauverEtat(self::appliquerReponse($etat, $code, $corps, $etag));
    }

    /**
     * Applique une réponse HTTP à l'état caché et le retourne (pur, testable).
     * Toute réponse reçue (200, 304, 404 « aucune release », 403 rate limit…)
     * repousse le prochain check de 24 h. version/url ne changent que sur un 200
     * portant un tag semver valide ; l'ETag est mémorisé dès qu'il est présent,
     * même si le tag est ignoré, pour permettre un 304 au check suivant.
     *
     * @param  array<string, mixed> $etat
     * @return array<string, mixed>
     */
    public static function appliquerReponse(array $etat, int $code, string $corps, string $etag): array
    {
        $etat['checked_at'] = time();

        if ($code === 200) {
            $data    = json_decode($corps, true);
            $version = self::versionDepuis($data);
            if ($version !== null) {
                $etat['version'] = $version;
                $etat['url']     = self::urlDepuis($data);
            }
        }
        if ($etag !== '') {
            $etat['etag'] = $etag;
        }
        return $etat;
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null [httpCode, body, etag]
     */
    private static function fetch(string $etag): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: LesCreneaux-maj-check',
        ];
        if ($etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $ch = curl_init('https://api.github.com/repos/' . self::DEPOT . '/releases/latest');
        if ($ch === false) {
            return null;
        }
        $etagRecu = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $ligne) use (&$etagRecu): int {
                if (stripos($ligne, 'etag:') === 0) {
                    $etagRecu = trim(substr($ligne, 5));
                }
                return strlen($ligne);
            },
        ]);
        $corps = curl_exec($ch);
        if ($corps === false) {
            return null;
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, (string)$corps, $etagRecu];
    }

    private static function versionDepuis(mixed $data): ?string
    {
        if (!is_array($data) || !isset($data['tag_name'])) {
            return null;
        }
        $tag = ltrim((string)$data['tag_name'], 'vV');
        return preg_match('/^\d+\.\d+\.\d+$/', $tag) === 1 ? $tag : null;
    }

    private static function urlDepuis(mixed $data): string
    {
        return self::urlSure(is_array($data) ? (string)($data['html_url'] ?? '') : '');
    }

    /** N'accepte qu'une URL https github.com, sinon retombe sur /releases. */
    private static function urlSure(string $url): string
    {
        return str_starts_with($url, 'https://github.com/') ? $url : self::pageReleases();
    }

    private static function pageReleases(): string
    {
        return 'https://github.com/' . self::DEPOT . '/releases';
    }

    /** @return array<string, mixed> */
    private static function chargerEtat(): array
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $etat */
    private static function sauverEtat(array $etat): void
    {
        $path = self::statePath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('MiseAJour: impossible de créer ' . $dir);
            return;
        }
        $ok = @file_put_contents($path, json_encode($etat, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($ok === false) {
            error_log('MiseAJour: écriture échouée sur ' . $path);
        }
    }

    private static function statePath(): string
    {
        return DATA_DIR . '/maj-state.json';
    }
}
