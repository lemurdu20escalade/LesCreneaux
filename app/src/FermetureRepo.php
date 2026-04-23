<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD des journées de fermeture du gymnase.

final class FermetureRepo
{
    public static function lister(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM fermetures ORDER BY date')->fetchAll();
    }

    /** Fermetures dont la date tombe dans le mois yyyy-mm. */
    public static function listerMois(PDO $pdo, string $yyyymm): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM fermetures
             WHERE substr(date,1,7) = ?
             ORDER BY date'
        );
        $stmt->execute([$yyyymm]);
        return $stmt->fetchAll();
    }

    /**
     * Déclare une fermeture à cette date et, dans la même transaction,
     * supprime tout créneau qui tomberait le même jour (inscriptions et
     * référentes partent en cascade via les FK). La fermeture fait foi.
     *
     * Retourne [fermetureId, nbCreneauxSupprimes].
     */
    public static function ajouter(PDO $pdo, string $date, ?string $note): array
    {
        return Database::tx($pdo, function (PDO $pdo) use ($date, $note): array {
            $del = $pdo->prepare('DELETE FROM jours WHERE date = ?');
            $del->execute([$date]);
            $supprimes = $del->rowCount();

            $ins = $pdo->prepare('INSERT INTO fermetures (date, note) VALUES (?, ?)');
            $ins->execute([$date, $note]);
            return [(int)$pdo->lastInsertId(), $supprimes];
        });
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM fermetures WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function supprimerPlusieurs(PDO $pdo, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM fermetures WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /**
     * Retourne vrai si la date est déclarée fermée. Utilisé par MoisGenerator
     * pour sauter la création de créneaux sur les jours fermés.
     */
    public static function estFerme(PDO $pdo, string $date): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM fermetures WHERE date = ?');
        $stmt->execute([$date]);
        return (bool)$stmt->fetchColumn();
    }
}
