<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD léger des inscriptions d'un jour.

final class InscriptionRepo
{
    public static function ajouter(
        PDO $pdo, int $jourId, string $nom, bool $estVoisine, ?string $note = null
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO inscriptions(jour_id, nom, est_voisine, note) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$jourId, $nom, $estVoisine ? 1 : 0, $note]);
        return (int)$pdo->lastInsertId();
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM inscriptions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function jourExiste(PDO $pdo, int $jourId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM jours WHERE id = ?');
        $stmt->execute([$jourId]);
        return (bool)$stmt->fetchColumn();
    }
}
