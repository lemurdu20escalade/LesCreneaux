<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Ligne condensée d'un jour dans la liste du mois.
 * Attend $jour (avec referentes[], inscriptions[] et labels[]).
 */
declare(strict_types=1);
$normal  = jourAccueilleInscriptions($jour);
$statut  = couleurReferente($jour);
$d       = new DateTimeImmutable($jour['date']);
$today   = new DateTimeImmutable('today');
$temporalite = $d < $today ? 'passe' : ($d == $today ? 'aujourdhui' : 'futur');
$nb      = count($jour['inscriptions']);
$cap     = (int)$jour['capacite'];
$jourId  = (int)$jour['id'];
$affiche = array_slice($jour['inscriptions'], 0, 5);
$reste   = max(0, $nb - count($affiche));
$rendreNom = static function (array $ins): string {
    $txt = $ins['nom'];
    if (!empty($ins['note'])) {
        $txt .= ' (' . $ins['note'] . ')';
    }
    return $txt;
};

// Référent·es : 3 noms max, compact. Affiche l'horaire uniquement quand
// un·e référent·e ne couvre pas la totalité du créneau (sinon le nom suffit).
$referentes = $jour['referentes'] ?? [];
$nbRef       = count($referentes);
$afficheRef  = array_slice($referentes, 0, 3);
$resteRef    = max(0, $nbRef - count($afficheRef));
// Compteur "personnes à la salle" = inscrit·es + référent·es (tout le
// monde compte dans la jauge physique du gymnase).
$nbTotal = $nb + $nbRef;
$hDebutJour  = $jour['heure_debut'];
$hFinJour    = $jour['heure_fin'];
$rendreRef = static function (array $r) use ($hDebutJour, $hFinJour): string {
    $hf = $r['heure_fin'] ?? null;
    // Pas d'horaire affiché si la personne couvre la totalité, ou si elle
    // n'a pas précisé son heure de sortie (pas de pression à afficher "à
    // partir de…" sur la liste condensée, le drawer le précisera).
    $couvreTout = $r['heure_debut'] === $hDebutJour
                  && ($hf === $hFinJour || $hf === null || $hf === '');
    return $couvreTout
        ? $r['nom']
        : $r['nom'] . ' ' . DateFr::formatPlage($r['heure_debut'], $hf);
};
?>
<li class="creneau<?= $normal ? '' : ' creneau--bloque' ?><?= ' creneau--' . e($temporalite) ?>">
    <a class="creneau-link"
       href="/jour/<?= $jourId ?>"
       data-drawer="<?= $jourId ?>"
       <?= $temporalite === 'aujourdhui' ? 'aria-current="date"' : '' ?>>
        <span class="c-date-bloc">
            <span class="c-date"><?= e(DateFr::formatCourt($d)) ?></span>
            <span class="c-horaire"><?= e(DateFr::formatPlage($jour['heure_debut'], $jour['heure_fin'])) ?></span>
        </span>

        <span class="c-chips">
            <?php if ($normal): ?>
                <span class="chip chip--<?= e($statut) ?>">
                    <span class="chip-dot" aria-hidden="true"></span>
                    <?= e(libelleStatut($statut)) ?>
                </span>
            <?php endif; ?>
            <?php foreach ($jour['labels'] ?? [] as $l): ?>
                <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
            <?php endforeach; ?>
            <?php if (!empty($jour['note'])): ?>
                <span class="c-note-jour"><?= e($jour['note']) ?></span>
            <?php endif; ?>
        </span>

        <span class="c-compteur" aria-label="<?= $nbTotal ?> personne<?= pluriel($nbTotal) ?> à la salle">
            <?= icon('group', 18) ?>
            <span class="c-compteur-val"><?= $nbTotal ?></span>
        </span>

        <?php if ($nbRef > 0): ?>
            <span class="c-refs">
                Référent·es : <?= e(implode(', ', array_map($rendreRef, $afficheRef))) ?><?php if ($resteRef > 0): ?><span class="c-reste"> + <?= $resteRef ?></span><?php endif; ?>
            </span>
        <?php endif; ?>

        <?php if ($nb > 0): ?>
            <span class="c-noms">
                <?= e(implode(', ', array_map($rendreNom, $affiche))) ?><?php if ($reste > 0): ?><span class="c-reste"> + <?= $reste ?></span><?php endif; ?>
            </span>
        <?php endif; ?>

        <span class="c-chevron" aria-hidden="true"><?= icon('chevron_right', 20) ?></span>
    </a>
</li>
