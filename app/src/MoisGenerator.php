<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Génération automatique d'un mois à partir des modèles actifs.
// Idempotent via UNIQUE(date, heure_debut). Voir §5 du plan.

final class MoisGenerator
{
    /** Retourne le nombre de jours créés (0 si le mois est déjà peuplé). */
    public static function genererSiVide(PDO $pdo, string $yyyymm): int
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yyyymm)) {
            throw new InvalidArgumentException("Mois invalide : $yyyymm");
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM jours WHERE substr(date,1,7) = ?'
        );
        $stmt->execute([$yyyymm]);
        if ((int)$stmt->fetchColumn() > 0) {
            return 0;
        }

        return Database::tx($pdo, function (PDO $pdo) use ($yyyymm): int {
            $modeles = $pdo->query(
                'SELECT id, jour_semaine, heure_debut, heure_fin, capacite,
                        note_defaut
                 FROM modeles WHERE active = 1'
            )->fetchAll();

            // Dates fermées sur ce mois, regroupées en set pour test O(1).
            $fermees = [];
            foreach (FermetureRepo::listerMois($pdo, $yyyymm) as $f) {
                $fermees[$f['date']] = true;
            }

            $ins = $pdo->prepare(
                'INSERT OR IGNORE INTO jours
                   (date, heure_debut, heure_fin, capacite, note)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $attachLabel = $pdo->prepare(
                'INSERT OR IGNORE INTO jour_label (jour_id, label_id) VALUES (?, ?)'
            );

            $debut = new DateTimeImmutable("$yyyymm-01");
            $fin   = $debut->modify('first day of next month');
            $count = 0;

            for ($d = $debut; $d < $fin; $d = $d->modify('+1 day')) {
                $dateStr = $d->format('Y-m-d');
                if (isset($fermees[$dateStr])) {
                    continue;
                }
                $jsem = (int)$d->format('N');
                foreach ($modeles as $m) {
                    if ((int)$m['jour_semaine'] !== $jsem) {
                        continue;
                    }
                    $ins->execute([
                        $dateStr,
                        $m['heure_debut'],
                        $m['heure_fin'],
                        (int)$m['capacite'],
                        $m['note_defaut'] ?? null,
                    ]);
                    if ($ins->rowCount() > 0) {
                        $count++;
                        $jourId = (int)$pdo->lastInsertId();
                        foreach (LabelRepo::idsAttachesModele($pdo, (int)$m['id']) as $labelId) {
                            $attachLabel->execute([$jourId, $labelId]);
                        }
                    }
                }
            }
            return $count;
        });
    }

    /** Liste les jours d'un mois, triés chronologiquement. */
    public static function listerJours(PDO $pdo, string $yyyymm): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM jours
             WHERE substr(date,1,7) = ?
             ORDER BY date, heure_debut'
        );
        $stmt->execute([$yyyymm]);
        return $stmt->fetchAll();
    }

    /**
     * Liste les jours + leurs référentes + leurs inscriptions, regroupés.
     * 3 queries, pas de N+1.
     */
    public static function listerJoursAvecDetails(PDO $pdo, string $yyyymm): array
    {
        $jours = self::listerJours($pdo, $yyyymm);
        if (empty($jours)) {
            return [];
        }

        $ids = array_column($jours, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT * FROM referentes WHERE jour_id IN ($ph) ORDER BY heure_debut"
        );
        $stmt->execute($ids);
        $referentesParJour = [];
        foreach ($stmt->fetchAll() as $r) {
            $referentesParJour[(int)$r['jour_id']][] = $r;
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM inscriptions WHERE jour_id IN ($ph) ORDER BY id"
        );
        $stmt->execute($ids);
        $inscriptionsParJour = [];
        foreach ($stmt->fetchAll() as $i) {
            $inscriptionsParJour[(int)$i['jour_id']][] = $i;
        }

        $labelsParJour = LabelRepo::labelsParJour($pdo, $ids);

        foreach ($jours as &$j) {
            $id = (int)$j['id'];
            $j['referentes']   = $referentesParJour[$id]   ?? [];
            $j['inscriptions'] = $inscriptionsParJour[$id] ?? [];
            $j['labels']       = $labelsParJour[$id]       ?? [];
        }
        return $jours;
    }
}
