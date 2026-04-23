<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Ligne "Gymnase fermé" sur /mois. Attend $fermeture (date, note).
 * Affichée à la bonne place chronologique, pas cliquable.
 */
declare(strict_types=1);
$d = new DateTimeImmutable($fermeture['date']);
$today = new DateTimeImmutable('today');
$temporalite = $d < $today ? 'passe' : ($d == $today ? 'aujourdhui' : 'futur');
?>
<li class="fermeture creneau--<?= e($temporalite) ?>" aria-label="Gymnase fermé le <?= e(DateFr::formatCourt($d)) ?>">
    <span class="fermeture-inner">
        <span class="fermeture-date"><?= e(DateFr::formatCourt($d)) ?></span>
        <span class="fermeture-label">
            <span class="fermeture-badge">Gymnase fermé</span>
            <?php if (!empty($fermeture['note'])): ?>
                <span class="fermeture-note-inline"><?= e($fermeture['note']) ?></span>
            <?php endif; ?>
        </span>
    </span>
</li>
