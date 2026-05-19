<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Authentification admin pour les routes mutating non-publiques :
// modification/suppression de créneaux, réglages, étiquettes, modèles,
// fermetures, bandeau, identité. Sans ce verrou, n'importe qui ayant
// récupéré un cookie csrf_session peut tout effacer en boucle.
//
// Mode compatibilité : si ADMIN_PASSWORD_HASH est vide ou non défini,
// `estActive()` renvoie false et `exigerConnexion()` est no-op — comportement
// identique aux instances antérieures. Pour activer, renseigner un hash
// dans config.php :
//   php -r "echo password_hash('mot-de-passe', PASSWORD_DEFAULT);"
//
// Cookie signé : `<expires>.<hmac(expires|fingerprint(hash), SECRET_CSRF)>`.
// Le fingerprint (32 premiers caractères du hash bcrypt) inclut un fragment
// du salt — changer ADMIN_PASSWORD_HASH invalide donc immédiatement TOUS
// les cookies existants. C'est notre mécanisme de révocation côté serveur
// sans table sessions : déconnexion = changer le hash.

final class AdminAuth
{
    private const COOKIE_NOM   = 'admin_session';
    private const COOKIE_TTL_S = 604800;  // 7 jours
    private const DELAI_ECHEC  = 1;       // sleep après mauvais mot de passe

    public static function estActive(): bool
    {
        // Passe par constant() plutôt qu'un accès direct : PHPStan a la
        // valeur littérale '' via le bootstrap config.example et déduirait
        // que la comparaison est toujours fausse. constant() retourne mixed,
        // donc l'inférence reste prudente. Runtime inchangé.
        if (!defined('ADMIN_PASSWORD_HASH')) {
            return false;
        }
        return (string) constant('ADMIN_PASSWORD_HASH') !== '';
    }

    public static function connecte(): bool
    {
        if (!self::estActive()) {
            return true;  // mode compat : tout le monde passe
        }
        $brut = (string)($_COOKIE[self::COOKIE_NOM] ?? '');
        if ($brut === '' || !str_contains($brut, '.')) {
            return false;
        }
        [$expiresStr, $sigFournie] = explode('.', $brut, 2);
        if (!ctype_digit($expiresStr) || $sigFournie === '') {
            return false;
        }
        $expires = (int)$expiresStr;
        if ($expires < time()) {
            return false;
        }
        return hash_equals(self::signer($expires), $sigFournie);
    }

    /**
     * HMAC qui inclut un fragment du hash bcrypt courant : changer
     * ADMIN_PASSWORD_HASH (rotation de mot de passe) invalide d'un coup
     * tous les cookies déjà émis. Sans ce fragment, un cookie volé reste
     * valide jusqu'à son expiry naturel même après changement de mdp.
     */
    private static function signer(int $expires): string
    {
        $hash        = (string) constant('ADMIN_PASSWORD_HASH');
        $empreinte   = substr($hash, 0, 32);
        return hash_hmac('sha256', 'admin:' . $expires . ':' . $empreinte, SECRET_CSRF);
    }

    /**
     * Si l'auth admin est active et que la session n'est pas valide,
     * redirige vers /admin/login en mémorisant l'URL d'origine. Pour
     * htmx, on renvoie un header HX-Redirect au lieu d'un Location.
     */
    public static function exigerConnexion(): void
    {
        if (self::connecte()) {
            return;
        }
        $retour = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $cible  = '/admin/login?retour=' . rawurlencode($retour);
        if (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true') {
            header('HX-Redirect: ' . $cible);
            http_response_code(204);
            exit;
        }
        header('Location: ' . $cible, true, 303);
        exit;
    }

    public static function tenterConnexion(string $passwordClair): bool
    {
        if (!self::estActive()) {
            return false;
        }
        $hash = (string) constant('ADMIN_PASSWORD_HASH');
        if (!password_verify($passwordClair, $hash)) {
            // Délai constant pour gêner le brute-force et masquer un
            // éventuel side-channel timing sur password_verify.
            sleep(self::DELAI_ECHEC);
            error_log('AdminAuth : tentative login échouée depuis '
                . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            return false;
        }
        $expires = time() + self::COOKIE_TTL_S;
        $cookie  = $expires . '.' . self::signer($expires);
        setcookie(self::COOKIE_NOM, $cookie, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NOM] = $cookie;
        return true;
    }

    public static function deconnecter(): void
    {
        setcookie(self::COOKIE_NOM, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NOM]);
    }
}
