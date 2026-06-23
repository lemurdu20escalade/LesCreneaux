<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Signature faible-coût des tables pour générer un ETag HTTP.
// Détecte tout INSERT ou DELETE (changement de COUNT ou de MAX(rowid)).
// Les UPDATE purs ne sont pas détectés — acceptable : rares à notre échelle,
// le prochain POST les pousse au polling suivant.

final class Version
{
    // Version applicative publiée (semver). À bumper avec le CHANGELOG à
    // chaque release. Comparée à la dernière release GitHub par MiseAJour
    // pour afficher la notification de mise à jour.
    public const APP = '0.1.0';

    public static function signature(PDO $pdo): string
    {
        $row = $pdo->query(
            'SELECT
               COALESCE((SELECT MAX(rowid) FROM jours), 0)        AS jmax,
               (SELECT COUNT(*) FROM jours)                        AS jcount,
               COALESCE((SELECT MAX(rowid) FROM inscriptions), 0) AS imax,
               (SELECT COUNT(*) FROM inscriptions)                 AS icount,
               COALESCE((SELECT MAX(rowid) FROM referentes), 0)   AS rmax,
               (SELECT COUNT(*) FROM referentes)                   AS rcount,
               COALESCE((SELECT MAX(rowid) FROM modeles), 0)      AS mmax,
               (SELECT COUNT(*) FROM modeles)                      AS mcount,
               COALESCE((SELECT MAX(rowid) FROM jour_label), 0)   AS jlmax,
               (SELECT COUNT(*) FROM jour_label)                   AS jlcount'
        )->fetch(PDO::FETCH_NUM);

        return md5(implode('-', array_map('strval', $row ?: [])));
    }

    public static function etag(PDO $pdo): string
    {
        return '"' . self::signature($pdo) . '"';
    }
}
