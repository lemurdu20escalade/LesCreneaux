<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD des modèles de créneaux récurrents (§3 du plan).

final class ModeleRepo
{
    public static function lister(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT * FROM modeles ORDER BY jour_semaine, heure_debut'
        )->fetchAll();
    }

    public static function ajouter(
        PDO $pdo, int $jourSemaine,
        string $heureDebut, string $heureFin,
        int $capacite, ?string $noteDefaut = null
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO modeles
                (jour_semaine, heure_debut, heure_fin, capacite, note_defaut, active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $jourSemaine, $heureDebut, $heureFin,
            $capacite, $noteDefaut,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(
        PDO $pdo, int $id,
        int $jourSemaine,
        string $heureDebut, string $heureFin,
        int $capacite, bool $active,
        ?string $noteDefaut = null
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE modeles
             SET jour_semaine = ?, heure_debut = ?, heure_fin = ?,
                 capacite = ?, active = ?, note_defaut = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $jourSemaine, $heureDebut, $heureFin,
            $capacite, $active ? 1 : 0, $noteDefaut,
            $id,
        ]);
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM modeles WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function setActive(PDO $pdo, int $id, bool $active): void
    {
        $stmt = $pdo->prepare('UPDATE modeles SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /** Copie un modèle existant et retourne l'id du nouveau. */
    public static function dupliquer(PDO $pdo, int $id): ?int
    {
        $stmt = $pdo->prepare('SELECT * FROM modeles WHERE id = ?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) {
            return null;
        }
        $newId = self::ajouter(
            $pdo,
            (int)$m['jour_semaine'],
            $m['heure_debut'],
            $m['heure_fin'],
            (int)$m['capacite'],
            $m['note_defaut'] ?? null
        );
        LabelRepo::syncModele($pdo, $newId, LabelRepo::idsAttachesModele($pdo, $id));
        return $newId;
    }
}
