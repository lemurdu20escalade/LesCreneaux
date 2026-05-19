<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Token d'ownership pour la désinscription. L'app n'a pas d'auth :
// quiconque voit la page voit toutes les inscriptions et leur id (auto-
// increment énumérable). Sans contrôle, n'importe qui peut désinscrire
// n'importe qui. On lie chaque droit de désinscription à un HMAC du
// SECRET_CSRF du serveur, stocké côté client dans un cookie HttpOnly.
//
// Contrainte UX assumée : la désinscription ne marche que depuis le
// navigateur qui a fait l'inscription. Pour les autres cas (changement
// de machine, perte de cookie), l'asso désinscrit à la main côté
// admin/SQL. Acceptable pour le volume d'une asso de quartier.

final class Desinscrire
{
    private const COOKIE_NOM     = 'desinscrire_keys';
    private const COOKIE_DUREE_S = 2592000;  // 30 jours
    private const MAX_ENTREES    = 30;

    public static function token(int $inscriptionId): string
    {
        return hash_hmac(
            'sha256',
            'desinscrire:' . $inscriptionId,
            SECRET_CSRF
        );
    }

    public static function verifierToken(int $inscriptionId, string $fourni): bool
    {
        if ($inscriptionId <= 0 || $fourni === '') {
            return false;
        }
        return hash_equals(self::token($inscriptionId), $fourni);
    }

    /**
     * Liste des inscriptions que ce navigateur a créées : id → token.
     * Retour filtré : seules les entrées au format attendu remontent.
     */
    public static function keysCourantes(): array
    {
        $brut = $_COOKIE[self::COOKIE_NOM] ?? '';
        if ($brut === '') {
            return [];
        }
        $data = json_decode((string)$brut, true);
        if (!is_array($data)) {
            return [];
        }
        $valides = [];
        foreach ($data as $id => $token) {
            if (is_numeric($id) && is_string($token) && $token !== '') {
                $valides[(int)$id] = $token;
            }
        }
        return $valides;
    }

    public static function ajouterKey(int $inscriptionId): void
    {
        $keys = self::keysCourantes();
        $keys[$inscriptionId] = self::token($inscriptionId);
        if (count($keys) > self::MAX_ENTREES) {
            // On garde les 30 plus grands ID — proxy raisonnable du « plus
            // récent », SQLite assignant les INTEGER PRIMARY KEY de manière
            // monotone croissante. ksort + array_slice queue est l'idiome
            // PHP le plus court pour ça.
            ksort($keys);
            $keys = array_slice($keys, -self::MAX_ENTREES, null, true);
        }
        $json = json_encode($keys, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('Desinscrire: json_encode keys a échoué — désinscription HS pour cette session.');
            return;
        }
        setcookie(self::COOKIE_NOM, $json, [
            'expires'  => time() + self::COOKIE_DUREE_S,
            'path'     => '/',
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Propagation intra-requête : si htmx déclenche un re-rendu du
        // drawer juste après (cas inscription → drawer rafraîchi), le
        // rendu doit voir l'état à jour, sinon le bouton « désinscrire »
        // n'apparaîtra qu'au prochain GET.
        $_COOKIE[self::COOKIE_NOM] = $json;
    }

    public static function retirerKey(int $inscriptionId): void
    {
        $keys = self::keysCourantes();
        if (!isset($keys[$inscriptionId])) {
            return;
        }
        unset($keys[$inscriptionId]);
        $json = $keys === [] ? '' : (string)json_encode($keys, JSON_UNESCAPED_SLASHES);
        setcookie(self::COOKIE_NOM, $json, [
            'expires'  => $json === '' ? time() - 3600 : time() + self::COOKIE_DUREE_S,
            'path'     => '/',
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($json === '') {
            unset($_COOKIE[self::COOKIE_NOM]);
        } else {
            $_COOKIE[self::COOKIE_NOM] = $json;
        }
    }
}
