<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Ajout/retrait d'une référente sur un jour (avec plage horaire).

final class ReferenteRepo
{
    public static function ajouter(
        PDO $pdo, int $jourId, string $nom,
        string $heureDebut, ?string $heureFin
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO referentes(jour_id, nom, heure_debut, heure_fin)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$jourId, $nom, $heureDebut, $heureFin]);
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM referentes WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function jourDe(PDO $pdo, int $id): ?int
    {
        $stmt = $pdo->prepare('SELECT jour_id FROM referentes WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }
}
