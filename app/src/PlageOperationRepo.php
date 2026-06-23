<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Historique des applications « Étiquettes par plage ».
// Sert à retrouver ce qui a été posé/retiré et à corriger après coup.

final class PlageOperationRepo
{
    /**
     * @param int[] $joursSemaine
     * @param int[] $labelsAjoutes
     * @param int[] $labelsRetires
     */
    public static function enregistrer(
        PDO $pdo,
        string $debut, string $fin, array $joursSemaine,
        array $labelsAjoutes, array $labelsRetires,
        int $nbCreneaux, string $creeLe
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO plage_operations
               (debut, fin, jours_semaine, labels_ajoutes, labels_retires,
                nb_creneaux, cree_le)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $debut, $fin,
            implode(',', $joursSemaine),
            implode(',', $labelsAjoutes),
            implode(',', $labelsRetires),
            $nbCreneaux, $creeLe,
        ]);
    }

    /**
     * Dernières opérations, plus récentes d'abord. Les champs CSV sont
     * reparsés en tableaux d'entiers pour l'affichage et le pré-remplissage.
     *
     * @return list<array{
     *   id:int, debut:string, fin:string, jours_semaine:int[],
     *   labels_ajoutes:int[], labels_retires:int[], nb_creneaux:int, cree_le:string
     * }>
     */
    public static function recentes(PDO $pdo, int $limite = 15): array
    {
        $limite = max(1, min($limite, 100));
        $stmt = $pdo->prepare(
            'SELECT * FROM plage_operations ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$limite]);

        return array_map(static function (array $r): array {
            return [
                'id'             => (int)$r['id'],
                'debut'          => (string)$r['debut'],
                'fin'            => (string)$r['fin'],
                'jours_semaine'  => self::csvVersInts($r['jours_semaine']),
                'labels_ajoutes' => self::csvVersInts($r['labels_ajoutes']),
                'labels_retires' => self::csvVersInts($r['labels_retires']),
                'nb_creneaux'    => (int)$r['nb_creneaux'],
                'cree_le'        => (string)$r['cree_le'],
            ];
        }, $stmt->fetchAll());
    }

    /** @return int[] */
    private static function csvVersInts(?string $csv): array
    {
        $csv = (string)$csv;
        if ($csv === '') {
            return [];
        }
        return array_map('intval', explode(',', $csv));
    }
}
