<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Connexion SQLite + transactions atomiques avec retry sur SQLITE_BUSY.
// Voir doc/PLAN_DEV.md §2.

final class Database
{
    private static ?PDO $instance = null;

    public static function connect(string $dbPath): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous  = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA cache_size   = -8000');
        $pdo->exec('PRAGMA temp_store   = MEMORY');
        // mmap 64 Mo : lectures qui frappent les mêmes pages servies
        // depuis le cache OS via mmap → moins de syscalls, moins de copies
        // kernel→userspace. Pas de risque, juste du gain sur lectures.
        $pdo->exec('PRAGMA mmap_size    = 67108864');

        // 001_init.sql est idempotente (CREATE TABLE IF NOT EXISTS + INSERT OR IGNORE)
        // donc on l'applique systématiquement : création sur DB neuve, no-op sur DB existante.
        self::migrate($pdo);

        return self::$instance = $pdo;
    }

    public static function tx(PDO $pdo, callable $fn): mixed
    {
        $delayMs = 10;
        for ($i = 0; $i < 3; $i++) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $result = $fn($pdo);
                $pdo->exec('COMMIT');
                return $result;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
                $busy = $e->getCode() === 5
                    || str_contains($e->getMessage(), 'database is locked')
                    || str_contains($e->getMessage(), 'SQLITE_BUSY');
                if ($busy && $i < 2) {
                    usleep($delayMs * 1000);
                    $delayMs *= 5;       // 10 → 50 → 250 ms
                    continue;
                }
                throw $e;
            }
        }
        throw new RuntimeException('Transaction : retry épuisé après SQLITE_BUSY.');
    }

    private static function migrate(PDO $pdo): void
    {
        $file = dirname(__DIR__) . '/migrations/001_init.sql';
        if (!is_file($file)) {
            throw new RuntimeException("Migration initiale introuvable : $file");
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Migration initiale illisible : $file");
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec($sql);
            $pdo->exec('COMMIT');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw new RuntimeException(
                'Échec de la migration initiale : ' . $e->getMessage(),
                0,
                $e
            );
        }

        self::fixups($pdo);
    }

    /**
     * Patchs de schéma idempotents appliqués à chaque connexion, pour
     * mettre à niveau les bases antérieures au schéma courant. SQLite ne
     * sait pas ALTER COLUMN : les corrections de contraintes passent par
     * recréation de table. Chaque fixup doit détecter son propre état avant
     * d'agir, et être un no-op sur une base déjà à jour.
     */
    private static function fixups(PDO $pdo): void
    {
        // Rend referentes.heure_fin nullable si ce n'est pas déjà le cas.
        $col = $pdo->query(
            "SELECT \"notnull\" FROM pragma_table_info('referentes') WHERE name='heure_fin'"
        )->fetchColumn();
        if ($col !== false && (int)$col === 1) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->exec('
                    CREATE TABLE referentes_new (
                      id           INTEGER PRIMARY KEY,
                      jour_id      INTEGER NOT NULL REFERENCES jours(id) ON DELETE CASCADE,
                      nom          TEXT    NOT NULL,
                      heure_debut  TEXT    NOT NULL,
                      heure_fin    TEXT
                    );
                    INSERT INTO referentes_new (id, jour_id, nom, heure_debut, heure_fin)
                      SELECT id, jour_id, nom, heure_debut, heure_fin FROM referentes;
                    DROP TABLE referentes;
                    ALTER TABLE referentes_new RENAME TO referentes;
                    CREATE INDEX IF NOT EXISTS idx_referentes_jour ON referentes(jour_id);
                ');
                $pdo->exec('COMMIT');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
                throw new RuntimeException(
                    'Échec du fixup referentes.heure_fin : ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }
}
