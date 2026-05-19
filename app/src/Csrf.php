<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Protection des POST : CSRF HMAC double-submit, honeypot, champ timing.
// Voir §3.2 du plan.

final class Csrf
{
    // Fenêtre de validité maximale d'un token signé côté formulaire.
    // Sans borne haute, un token capturé une fois est rejouable
    // indéfiniment — bot zéro-friction. 2 h = couvre le cas « j'ouvre
    // la page, je pars boire un café, je reviens soumettre » sans
    // laisser un script tourner toute la journée sur le même couple.
    private const MAX_AGE_S = 7200;

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

    /** True si le POST est valide (CSRF + honeypot + timing ≥ 2 s). */
    public static function verifierPost(array $post): bool
    {
        $tokenPost    = (string)($post['_csrf']   ?? '');
        $honeypot     = (string)($post['website'] ?? '');
        $ts           = (int)   ($post['_ts']     ?? 0);
        $tsSigPost    = (string)($post['_ts_sig'] ?? '');

        if ($honeypot !== '') {
            return false;
        }
        if (!hash_equals(self::token(), $tokenPost)) {
            return false;
        }
        $tsSigExpected = hash_hmac('sha256', (string)$ts, SECRET_CSRF);
        if (!hash_equals($tsSigExpected, $tsSigPost)) {
            return false;
        }
        $age = time() - $ts;
        if ($ts <= 0 || $age < 2 || $age > self::MAX_AGE_S) {
            return false;
        }
        return true;
    }

}
