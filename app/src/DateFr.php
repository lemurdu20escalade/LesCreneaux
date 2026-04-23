<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Formatage de dates en français — tables figées, zéro dépendance à intl.

final class DateFr
{
    private const JOURS = [
        1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi',
        5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
    ];

    private const MOIS = [
        1  => 'janvier',  2  => 'février',  3  => 'mars',       4  => 'avril',
        5  => 'mai',      6  => 'juin',     7  => 'juillet',    8  => 'août',
        9  => 'septembre',10 => 'octobre',  11 => 'novembre',   12 => 'décembre',
    ];

    public static function jourSemaine(DateTimeImmutable $d): string
    {
        return self::JOURS[(int)$d->format('N')];
    }

    public static function moisNom(int $m): string
    {
        return self::MOIS[$m] ?? '';
    }

    /** "mardi 7 avril" */
    public static function formatCourt(DateTimeImmutable $d): string
    {
        return sprintf(
            '%s %d %s',
            self::jourSemaine($d),
            (int)$d->format('j'),
            self::moisNom((int)$d->format('n'))
        );
    }

    /** "18h" ou "22h30" */
    public static function formatHeure(string $hhmm): string
    {
        [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
        return (int)$m === 0 ? ((int)$h) . 'h' : ((int)$h) . 'h' . $m;
    }

    /**
     * "18h – 22h30", ou "dès 18h" si la fin n'est pas connue
     * (heure_fin facultative sur les référent·es).
     */
    public static function formatPlage(string $debut, ?string $fin): string
    {
        if ($fin === null || $fin === '') {
            return 'dès ' . self::formatHeure($debut);
        }
        return self::formatHeure($debut) . ' – ' . self::formatHeure($fin);
    }
}
