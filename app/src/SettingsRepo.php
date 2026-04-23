<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Paramètres clé/valeur (nom de l'asso, extension du logo, etc.).

final class SettingsRepo
{
    public const CLE_ASSO_NOM      = 'asso_nom';
    public const CLE_ASSO_LOGO_URL = 'asso_logo_url';
    public const CLE_BANDEAU_HTML  = 'bandeau_html';

    public static function get(PDO $pdo, string $cle, string $defaut = ''): string
    {
        $stmt = $pdo->prepare('SELECT valeur FROM settings WHERE cle = ?');
        $stmt->execute([$cle]);
        $v = $stmt->fetchColumn();
        return $v === false ? $defaut : (string)$v;
    }

    public static function set(PDO $pdo, string $cle, string $valeur): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (cle, valeur) VALUES (?, ?)
             ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur'
        );
        $stmt->execute([$cle, $valeur]);
    }

    public static function effacer(PDO $pdo, string $cle): void
    {
        $pdo->prepare('DELETE FROM settings WHERE cle = ?')->execute([$cle]);
    }
}

/**
 * Helper pratique pour les vues : lit un setting via la connexion singleton.
 * Pas de cache : évite les lectures stales quand un POST /settings/update
 * et une lecture cohabitent dans le même processus (PHP-FPM).
 */
function setting(string $cle, string $defaut = ''): string
{
    return SettingsRepo::get(Database::connect(DB_PATH), $cle, $defaut);
}
