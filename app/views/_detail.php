<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Détail complet d'un jour. Rendu dans un drawer (htmx) ou en page autonome.
 * Attend $jour (avec referentes[], inscriptions[], labels[]) et $prenomMemo.
 */
declare(strict_types=1);
$normal       = jourAccueilleInscriptions($jour);
$inscriptible = $normal;
$voisines     = jourOuvreVoisines($jour);
$statut       = couleurReferente($jour);
$sansReferent = jourSansReferent($jour);
$d      = new DateTimeImmutable($jour['date']);
$nb     = count($jour['inscriptions']);
$cap    = (int)$jour['capacite'];
$jourId = (int)$jour['id'];

// Rendu dans le drawer : on boost les POST "légers" (inscrire, desinscrire,
// référent·e ajouter/supprimer) via htmx pour rafraîchir _detail.php
// in-place. Les POST "lourds" (update, supprimer créneau) font toujours
// un full reload — l'état visible peut être trop impacté pour un swap.
$inDrawer = $inDrawer ?? false;
$hxSwap   = $inDrawer
    ? ' hx-target="#drawer-body" hx-swap="innerHTML"'
    : '';
?>
<article class="detail<?= $normal ? '' : ' detail--bloque' ?>" id="jour-<?= $jourId ?>">
    <header class="detail-entete">
        <p class="detail-date"><?= e(DateFr::formatCourt($d)) ?></p>
        <p class="detail-horaire">
            <?= icon('schedule', 18) ?>
            <span><?= e(DateFr::formatPlage($jour['heure_debut'], $jour['heure_fin'])) ?></span>
        </p>
        <p class="detail-chips">
            <?php if ($normal && !$sansReferent): ?>
                <span class="chip chip--<?= e($statut) ?>">
                    <span class="chip-dot" aria-hidden="true"></span>
                    <?= e(libelleStatut($statut)) ?>
                </span>
            <?php endif; ?>
            <?php foreach ($jour['labels'] ?? [] as $l): ?>
                <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
            <?php endforeach; ?>
        </p>
        <?php if (!empty($jour['note'])): ?>
            <p class="detail-note"><?= e($jour['note']) ?></p>
        <?php endif; ?>
    </header>

    <section class="detail-section">
        <h3 class="detail-titre-section">Référent·es</h3>
        <?php if (empty($jour['referentes'])): ?>
            <?php if ($sansReferent): ?>
                <p class="meta">Pas de référent·e requis·e pour ce créneau.</p>
            <?php else: ?>
                <p class="alerte">
                    <?= icon('error_outline', 18) ?>
                    <span>Sans référent·e, la salle n’ouvre pas.</span>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <ul class="liste-referentes">
                <?php foreach ($jour['referentes'] as $r): ?>
                    <li>
                        <span class="li-principal"><strong><?= e($r['nom']) ?></strong>
                            <span class="meta"><?= e(DateFr::formatPlage($r['heure_debut'], $r['heure_fin'])) ?></span>
                        </span>
                        <form action="/referente/<?= (int)$r['id'] ?>/supprimer" method="post" class="inline"
                              <?php if ($inDrawer): ?>hx-post="/referente/<?= (int)$r['id'] ?>/supprimer"<?= $hxSwap ?><?php endif; ?>
                              onsubmit="return confirm('Retirer <?= e($r['nom']) ?> comme référent·e ?');">
                            <?= Csrf::champs() ?>
                            <button type="submit" class="btn-icone" aria-label="Retirer <?= e($r['nom']) ?>">
                                <?= icon('close', 18) ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <details class="bloc-ajout"<?= (empty($jour['referentes']) && !$sansReferent) ? ' open' : '' ?>>
            <summary><?= icon('person_add', 16) ?><span>Ajouter un·e référent·e</span></summary>
            <form action="/jour/<?= $jourId ?>/referente/ajouter" method="post" class="form-ajout"
                  <?php if ($inDrawer): ?>hx-post="/jour/<?= $jourId ?>/referente/ajouter"<?= $hxSwap ?><?php endif; ?>>
                <?= Csrf::champs() ?>
                <label class="field">
                    <span class="field-label">Nom</span>
                    <input type="text" name="nom" required maxlength="40">
                </label>
                <div class="field-row">
                    <label class="field">
                        <span class="field-label">De</span>
                        <input type="time" name="heure_debut" required value="<?= e($jour['heure_debut']) ?>">
                    </label>
                    <label class="field">
                        <span class="field-label">À <span class="meta">(facultatif)</span></span>
                        <input type="time" name="heure_fin" placeholder="jusqu’à quand ?">
                    </label>
                </div>
                <p class="meta">
                    Pas besoin de renseigner « À » si tu ne sais pas encore.
                    <button type="button" class="btn-text"
                            onclick="this.closest('form').elements['heure_fin'].value='<?= e($jour['heure_fin']) ?>'">
                        Utiliser la fin du créneau (<?= e(DateFr::formatHeure($jour['heure_fin'])) ?>)
                    </button>
                </p>
                <button type="submit" class="btn btn--filled">Ajouter</button>
            </form>
        </details>
    </section>

    <section class="detail-section">
        <h3 class="detail-titre-section">
            Inscrit·es
            <span class="compteur-badge"><?= $nb ?></span>
        </h3>
        <?php if ($nb > 0): ?>
            <ul class="liste-inscrits">
                <?php foreach ($jour['inscriptions'] as $ins): ?>
                    <li>
                        <span class="li-principal">
                            <strong><?= e($ins['nom']) ?></strong>
                            <?php if (!empty($ins['note'])): ?>
                                <span class="meta"><?= e($ins['note']) ?></span>
                            <?php endif; ?>
                            <?php if ((int)$ins['est_voisine'] === 1): ?>
                                <span class="tag-voisine">Voisin·e</span>
                            <?php endif; ?>
                        </span>
                        <form action="/jour/<?= $jourId ?>/desinscrire" method="post" class="inline"
                              <?php if ($inDrawer): ?>hx-post="/jour/<?= $jourId ?>/desinscrire"<?= $hxSwap ?><?php endif; ?>
                              onsubmit="return confirm('Désinscrire <?= e($ins['nom']) ?> ?');">
                            <?= Csrf::champs() ?>
                            <input type="hidden" name="inscription_id" value="<?= (int)$ins['id'] ?>">
                            <button type="submit" class="btn-icone" aria-label="Désinscrire <?= e($ins['nom']) ?>">
                                <?= icon('close', 18) ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="meta">Personne pour l’instant.</p>
        <?php endif; ?>

        <?php if ($inscriptible): ?>
            <form action="/jour/<?= $jourId ?>/inscrire" method="post" class="form-inscrire"
                  <?php if ($inDrawer): ?>hx-post="/jour/<?= $jourId ?>/inscrire"<?= $hxSwap ?><?php endif; ?>>
                <?= Csrf::champs() ?>
                <label class="field">
                    <span class="field-label">Ton prénom</span>
                    <input type="text" name="nom" required maxlength="40" autocomplete="given-name"
                           placeholder="ex. Marion"
                           id="nom-<?= $jourId ?>" hx-preserve="true"
                           value="<?= e($prenomMemo ?? '') ?>">
                </label>
                <label class="field">
                    <span class="field-label">Note <span class="meta">(facultatif)</span></span>
                    <input type="text" name="note" maxlength="80"
                           placeholder="ex. arrive à 19h, +1, avec matos">
                </label>
                <?php if ($voisines): ?>
                    <label class="check">
                        <input type="checkbox" name="est_voisine" value="1">
                        <span>Je suis voisin·e (pas adhérent·e)</span>
                    </label>
                <?php endif; ?>
                <button type="submit" class="btn btn--filled btn--large">Je m’inscris</button>
            </form>
        <?php else: ?>
            <?php
                $motif = '';
                foreach ($jour['labels'] ?? [] as $l) {
                    if ((int)($l['bloque_inscriptions'] ?? 0) === 1) {
                        $motif = $l['nom'];
                        break;
                    }
                }
            ?>
            <p class="meta"><?= e($motif) ?> — inscriptions désactivées.</p>
        <?php endif; ?>
    </section>

    <details class="bloc-edition">
        <summary><?= icon('edit', 16) ?><span>Modifier ce créneau</span></summary>
        <form action="/jour/<?= $jourId ?>/update" method="post" class="form-edit">
            <?= Csrf::champs() ?>
            <?php
                $tousLabels = $tousLabels ?? [];
                $attachesJour = array_column($jour['labels'] ?? [], 'id');
            ?>
            <?php if (!empty($tousLabels)): ?>
                <fieldset class="field-labels">
                    <legend class="field-label">Étiquettes</legend>
                    <?php foreach ($tousLabels as $l): ?>
                        <label class="check">
                            <input type="checkbox" name="labels[]" value="<?= (int)$l['id'] ?>"
                                   <?= in_array((int)$l['id'], $attachesJour, true) ? 'checked' : '' ?>>
                            <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <div class="field-row">
                <label class="field">
                    <span class="field-label">Début</span>
                    <input type="time" name="heure_debut" value="<?= e($jour['heure_debut']) ?>" required>
                </label>
                <label class="field">
                    <span class="field-label">Fin</span>
                    <input type="time" name="heure_fin" value="<?= e($jour['heure_fin']) ?>" required>
                </label>
            </div>
            <label class="field">
                <span class="field-label">Limite d’inscription <span class="meta">(séance privatisée, cas rare)</span></span>
                <input type="number" name="capacite" min="1" max="500" value="<?= $cap ?>" required>
            </label>
            <label class="field">
                <span class="field-label">Note <span class="meta">(les retours à la ligne sont conservés)</span></span>
                <textarea name="note" maxlength="500" rows="3" placeholder="ex. niveau débutant"><?= e($jour['note'] ?? '') ?></textarea>
            </label>
            <button type="submit" class="btn btn--filled">Enregistrer</button>
        </form>

        <?php if (!AdminAuth::estActive() || AdminAuth::connecte()): ?>
        <form action="/jour/<?= $jourId ?>/supprimer" method="post"
              onsubmit="return confirm('Supprimer le créneau du <?= e(DateFr::formatCourt($d)) ?> ?');">
            <?= Csrf::champs() ?>
            <button type="submit" class="btn btn--danger">Supprimer ce créneau</button>
        </form>
        <?php endif; ?>
    </details>
</article>
