<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// CRUD et association des étiquettes libres.

final class LabelRepo
{
    public const DEFAUT = '#90a4ae';

    /** Normalise une valeur `couleur` en hex #rrggbb. Retour DEFAUT si invalide. */
    public static function normaliserHex(string $c): string
    {
        $c = strtolower(trim($c));
        if (preg_match('/^#?([0-9a-f]{6})$/', $c, $m)) {
            return '#' . $m[1];
        }
        if (preg_match('/^#?([0-9a-f]{3})$/', $c, $m)) {
            $h = $m[1];
            return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return self::DEFAUT;
    }

    public static function lister(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM labels ORDER BY ordre, nom')->fetchAll();
    }

    /**
     * @param array{bloque_inscriptions?:bool,ouvre_voisines?:bool,sans_referent?:bool} $flags
     */
    public static function ajouter(
        PDO $pdo, string $nom, string $couleur, array $flags = []
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO labels (nom, couleur, bloque_inscriptions, ouvre_voisines, sans_referent)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $nom, self::normaliserHex($couleur),
            !empty($flags['bloque_inscriptions']) ? 1 : 0,
            !empty($flags['ouvre_voisines']) ? 1 : 0,
            !empty($flags['sans_referent']) ? 1 : 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array{bloque_inscriptions?:bool,ouvre_voisines?:bool,sans_referent?:bool} $flags
     */
    public static function update(
        PDO $pdo, int $id, string $nom, string $couleur, array $flags = []
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE labels
             SET nom = ?, couleur = ?,
                 bloque_inscriptions = ?, ouvre_voisines = ?, sans_referent = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $nom, self::normaliserHex($couleur),
            !empty($flags['bloque_inscriptions']) ? 1 : 0,
            !empty($flags['ouvre_voisines']) ? 1 : 0,
            !empty($flags['sans_referent']) ? 1 : 0,
            $id,
        ]);
    }

    public static function supprimer(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM labels WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** @return int[] ids des labels attachés au jour. */
    public static function idsAttachesJour(PDO $pdo, int $jourId): array
    {
        $stmt = $pdo->prepare('SELECT label_id FROM jour_label WHERE jour_id = ?');
        $stmt->execute([$jourId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int[] ids des labels attachés au modèle. */
    public static function idsAttachesModele(PDO $pdo, int $modeleId): array
    {
        $stmt = $pdo->prepare('SELECT label_id FROM modele_label WHERE modele_id = ?');
        $stmt->execute([$modeleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Labels complets (id, nom, couleur) attachés à un modèle donné. */
    public static function labelsParModele(PDO $pdo, int $modeleId): array
    {
        $stmt = $pdo->prepare(
            'SELECT l.id, l.nom, l.couleur
               FROM modele_label ml
               JOIN labels l ON l.id = ml.label_id
              WHERE ml.modele_id = ?
              ORDER BY l.ordre, l.nom'
        );
        $stmt->execute([$modeleId]);
        return $stmt->fetchAll();
    }

    /**
     * Labels attachés à chaque modèle pour une liste d'ids donnée.
     * Une seule requête au lieu d'une par modèle (évite le N+1 sur
     * la carte "Modèles" de /reglages).
     * Retourne ['modele_id' => [ ['id'=>..., 'nom'=>..., 'couleur'=>...], ... ]].
     */
    public static function labelsParModeles(PDO $pdo, array $modeleIds): array
    {
        if (empty($modeleIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($modeleIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT ml.modele_id, l.id, l.nom, l.couleur
               FROM modele_label ml
               JOIN labels l ON l.id = ml.label_id
              WHERE ml.modele_id IN ($ph)
              ORDER BY l.ordre, l.nom"
        );
        $stmt->execute($modeleIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['modele_id']][] = [
                'id'      => (int)$r['id'],
                'nom'     => $r['nom'],
                'couleur' => $r['couleur'],
            ];
        }
        return $out;
    }

    /**
     * Même idée pour idsAttachesModele : une requête qui retourne
     * ['modele_id' => [id1, id2, ...]].
     */
    public static function idsAttachesModeles(PDO $pdo, array $modeleIds): array
    {
        if (empty($modeleIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($modeleIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT modele_id, label_id FROM modele_label WHERE modele_id IN ($ph)"
        );
        $stmt->execute($modeleIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['modele_id']][] = (int)$r['label_id'];
        }
        return $out;
    }

    /**
     * Synchronise les labels attachés à un jour : ajoute ceux manquants,
     * retire ceux qui ne sont plus dans la liste.
     */
    public static function syncJour(PDO $pdo, int $jourId, array $labelIds): void
    {
        self::syncLabels($pdo, 'jour_label', 'jour_id', $jourId, $labelIds);
    }

    public static function syncModele(PDO $pdo, int $modeleId, array $labelIds): void
    {
        self::syncLabels($pdo, 'modele_label', 'modele_id', $modeleId, $labelIds);
    }

    /**
     * Remplace les labels attachés à une entité (jour ou modèle) :
     * DELETE de tout ce qui existe, INSERT des nouveaux. Les noms de
     * table et de colonne viennent de nos propres constantes, jamais
     * d'un input utilisateur — pas d'injection possible.
     */
    private static function syncLabels(
        PDO $pdo, string $table, string $fkCol, int $entityId, array $labelIds
    ): void {
        $pdo->prepare("DELETE FROM {$table} WHERE {$fkCol} = ?")->execute([$entityId]);
        if (empty($labelIds)) {
            return;
        }
        $ins = $pdo->prepare("INSERT INTO {$table} ({$fkCol}, label_id) VALUES (?, ?)");
        foreach ($labelIds as $lid) {
            $ins->execute([$entityId, (int)$lid]);
        }
    }

    /**
     * Labels attachés à chaque jour pour une liste d'ids donnée.
     * Retourne ['jour_id' => [ ['id'=>..., 'nom'=>..., 'couleur'=>...], ... ]].
     */
    public static function labelsParJour(PDO $pdo, array $jourIds): array
    {
        if (empty($jourIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($jourIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT jl.jour_id, l.id, l.nom, l.couleur,
                    l.bloque_inscriptions, l.ouvre_voisines, l.sans_referent
               FROM jour_label jl
               JOIN labels l ON l.id = jl.label_id
              WHERE jl.jour_id IN ($ph)
              ORDER BY l.ordre, l.nom"
        );
        $stmt->execute($jourIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['jour_id']][] = [
                'id'                  => (int)$r['id'],
                'nom'                 => $r['nom'],
                'couleur'             => $r['couleur'],
                'bloque_inscriptions' => (int)$r['bloque_inscriptions'],
                'ouvre_voisines'      => (int)$r['ouvre_voisines'],
                'sans_referent'       => (int)$r['sans_referent'],
            ];
        }
        return $out;
    }
}
