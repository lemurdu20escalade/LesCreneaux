<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD des jours (créneaux ponctuels).

final class JourRepo
{
    public static function creer(
        PDO $pdo,
        string $date, string $heureDebut, string $heureFin,
        int $capacite, ?string $note
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO jours(date, heure_debut, heure_fin, capacite, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$date, $heureDebut, $heureFin, $capacite, $note]);
        return (int)$pdo->lastInsertId();
    }

    public static function update(
        PDO $pdo, int $id,
        string $heureDebut, string $heureFin,
        int $capacite, ?string $note
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE jours
             SET heure_debut = ?, heure_fin = ?, capacite = ?, note = ?
             WHERE id = ?'
        );
        $stmt->execute([$heureDebut, $heureFin, $capacite, $note, $id]);
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM jours WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Retourne 'YYYY-MM' du jour ou null. */
    public static function moisDe(PDO $pdo, int $id): ?string
    {
        $stmt = $pdo->prepare('SELECT date FROM jours WHERE id = ?');
        $stmt->execute([$id]);
        $date = $stmt->fetchColumn();
        return $date === false ? null : substr((string)$date, 0, 7);
    }

    /**
     * Ids des créneaux dont la date tombe dans [debut..fin] (bornes incluses),
     * filtrés par jours de semaine ISO (1=lundi … 7=dimanche). Liste vide =
     * AUCUN jour (zéro créneau) — pas « tous » : décocher toutes les cases du
     * formulaire ne doit pas appliquer à tout par surprise. Le filtre se fait
     * en PHP : la plage est bornée côté appelant (≤ 366 j), volume petit.
     *
     * @param int[] $joursSemaine
     * @return int[]
     */
    public static function idsDansPlage(
        PDO $pdo, string $debut, string $fin, array $joursSemaine
    ): array {
        if ($joursSemaine === []) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, date FROM jours WHERE date BETWEEN ? AND ? ORDER BY date'
        );
        $stmt->execute([$debut, $fin]);

        $filtre = array_flip($joursSemaine);
        $ids = [];
        foreach ($stmt->fetchAll() as $r) {
            $jsem = (int)(new DateTimeImmutable((string)$r['date']))->format('N');
            if (isset($filtre[$jsem])) {
                $ids[] = (int)$r['id'];
            }
        }
        return $ids;
    }
}
