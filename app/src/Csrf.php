<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Protection des POST : CSRF HMAC double-submit, honeypot, champ timing.
// Voir §3.2 du plan.

final class Csrf
{
    // Codes de vérification retournés par verifierPostDetail. Stables :
    // utilisés par le handler /admin/login pour afficher un message
    // différencié, et par les tests pour cibler un motif précis.
    public const OK          = 'ok';
    public const MANQUANT    = 'manquant';     // champ absent du POST
    public const HONEYPOT    = 'honeypot';     // bot a rempli le piège
    public const TOKEN       = 'token';        // _csrf ne correspond pas au cookie
    public const TS_SIG      = 'ts_sig';       // _ts_sig forgé
    public const TROP_RAPIDE = 'trop_rapide';  // soumission en moins de TIMING_MIN_S
    public const EXPIRE      = 'expire';       // _ts hors fenêtre (passé > MAX_AGE_S ou futur)

    // Fenêtre de validité maximale d'un token signé côté formulaire.
    // Sans borne haute, un token capturé une fois est rejouable
    // indéfiniment — bot zéro-friction. 2 h = couvre le cas « j'ouvre
    // la page, je pars boire un café, je reviens soumettre » sans
    // laisser un script tourner toute la journée sur le même couple.
    private const MAX_AGE_S    = 7200;

    // Borne basse anti-bot. Un humain rempli/clique difficilement en
    // moins de 2 s ; un script si. Désactivable par les flows
    // interactifs où ce filtre dessert l'UX (login admin avec password
    // manager qui auto-fill + submit, par exemple).
    private const TIMING_MIN_S = 2;

    /** Cookie anonyme posé au premier GET, 16 octets hex. */
    public static function cookie(): string
    {
        $c = $_COOKIE['csrf_session'] ?? '';
        if (!preg_match('/^[0-9a-f]{32}$/', $c)) {
            $c = bin2hex(random_bytes(16));
            setcookie('csrf_session', $c, [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $_COOKIE['csrf_session'] = $c;
        }
        return $c;
    }

    public static function token(): string
    {
        return hash_hmac('sha256', self::cookie(), SECRET_CSRF);
    }

    /** Champs cachés à inclure dans chaque <form method="POST">. */
    public static function champs(): string
    {
        $token = self::token();
        $ts    = time();
        $tsSig = hash_hmac('sha256', (string)$ts, SECRET_CSRF);
        return
              '<input type="hidden" name="_csrf" value="'   . e($token) . '">'
            . '<input type="hidden" name="_ts" value="'     . $ts       . '">'
            . '<input type="hidden" name="_ts_sig" value="' . e($tsSig) . '">'
            . '<input type="text" name="website" value="" tabindex="-1"'
              . ' autocomplete="off" aria-hidden="true" class="hp">';
    }

    /**
     * Vérification détaillée. Retourne self::OK ou l'un des motifs
     * d'échec ci-dessus. Utile pour différencier le message UX
     * (« token expiré, recharge » vs « soumission refusée »).
     *
     * @param array<string,mixed>           $post
     * @param array{timing_min?:bool}       $options  timing_min=false
     *        bypasse la borne basse de 2 s (à réserver aux flows
     *        interactifs où l'utilisateur peut légitimement
     *        submit en < 2 s, ex. login).
     */
    public static function verifierPostDetail(array $post, array $options = []): string
    {
        $timingMin = $options['timing_min'] ?? true;

        if (!isset($post['_csrf'], $post['_ts'], $post['_ts_sig'])) {
            return self::MANQUANT;
        }
        $tokenPost = (string)$post['_csrf'];
        $honeypot  = (string)($post['website'] ?? '');
        $ts        = (int)   $post['_ts'];
        $tsSigPost = (string)$post['_ts_sig'];

        if ($honeypot !== '') {
            return self::HONEYPOT;
        }
        if (!hash_equals(self::token(), $tokenPost)) {
            return self::TOKEN;
        }
        $tsSigExpected = hash_hmac('sha256', (string)$ts, SECRET_CSRF);
        if (!hash_equals($tsSigExpected, $tsSigPost)) {
            return self::TS_SIG;
        }
        if ($ts <= 0) {
            return self::EXPIRE;
        }
        $age = time() - $ts;
        if ($age < 0 || $age > self::MAX_AGE_S) {
            return self::EXPIRE;
        }
        if ($timingMin && $age < self::TIMING_MIN_S) {
            return self::TROP_RAPIDE;
        }
        return self::OK;
    }

    /**
     * Wrapper booléen pour les appels qui ne s'intéressent pas au motif
     * d'échec (inscription, désinscription, réglages). Conserve la
     * sémantique stricte historique : timing minimum actif.
     *
     * @param array<string,mixed> $post
     */
    public static function verifierPost(array $post): bool
    {
        return self::verifierPostDetail($post) === self::OK;
    }
}
