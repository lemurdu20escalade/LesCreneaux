<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD léger des inscriptions d'un jour.

final class InscriptionRepo
{
    public static function ajouter(
        PDO $pdo, int $jourId, string $nom, bool $estVoisine, ?string $note = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO inscriptions(jour_id, nom, est_voisine, note) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$jourId, $nom, $estVoisine ? 1 : 0, $note]);
    }

    /**
     * Supprime une inscription en vérifiant qu'elle appartient bien au
     * jour passé en URL. Sans ce filtre, un POST /jour/1/desinscrire
     * avec un inscription_id du jour 42 supprimerait quand même : on
     * pourrait désinscrire n'importe qui depuis n'importe quelle URL,
     * et le rate-limit qui s'applique à l'URL deviendrait contournable.
     */
    public static function supprimer(PDO $pdo, int $id, int $jourId): bool
    {
        $stmt = $pdo->prepare('DELETE FROM inscriptions WHERE id = ? AND jour_id = ?');
        $stmt->execute([$id, $jourId]);
        return $stmt->rowCount() > 0;
    }

    public static function jourExiste(PDO $pdo, int $jourId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM jours WHERE id = ?');
        $stmt->execute([$jourId]);
        return (bool)$stmt->fetchColumn();
    }
}
