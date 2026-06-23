<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Fragment htmx de l'aperçu « Étiquettes par plage ».
 * @var array<int,array<string,mixed>> $labels  étiquettes (id, nom, couleur, …)
 * @var array<int,int> $comptes  [label_id => nb de jours distincts]
 * @var int[] $jourIds  ids des créneaux concernés par la plage
 * @var bool $datesValides  dates renseignées, début ≤ fin, ≤ 366 jours
 * @var bool $aucunJour  dates valides mais aucune case jour-semaine cochée
 */
$nbCreneaux = count($jourIds);
?>
<?php if (!$datesValides): ?>
    <p class="meta">Renseigne une date de début et de fin valides (début ≤ fin, 366 jours max).</p>
<?php elseif ($aucunJour): ?>
    <p class="meta">Aucun jour de la semaine coché&nbsp;: coche au moins un jour.</p>
<?php elseif ($nbCreneaux === 0): ?>
    <p class="meta">Aucun créneau sur cette période (le mois n’a peut-être pas encore été généré).</p>
<?php else: ?>
    <p class="meta"><strong><?= $nbCreneaux ?></strong> créneau<?= pluriel($nbCreneaux, 'x') ?> concerné<?= pluriel($nbCreneaux) ?>.</p>
    <?php $presents = array_filter($labels, static fn (array $l): bool => isset($comptes[(int)$l['id']])); ?>
    <?php if (empty($presents)): ?>
        <p class="meta">Aucune étiquette posée sur ces créneaux pour l’instant.</p>
    <?php else: ?>
        <p class="meta">Étiquettes déjà présentes&nbsp;:</p>
        <span class="modele-chips">
            <?php foreach ($presents as $l): ?>
                <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>">
                    <?= e($l['nom']) ?> (<?= (int)$comptes[(int)$l['id']] ?>&nbsp;j)
                </span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>
<?php endif; ?>
