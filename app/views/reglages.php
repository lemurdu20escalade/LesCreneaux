<?php // SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1); ?>
<header class="page-entete">
    <h2 class="page-titre">Réglages</h2>
    <p class="page-intro">
        Configure l’identité de l’asso, les créneaux récurrents, les étiquettes
        et les fermetures du gymnase.<br>
        Clique sur une carte pour l’ouvrir.
    </p>
</header>

<details class="reglage-card" id="identite">
    <summary>
        <span class="reglage-card-titre">Identité de l’asso</span>
        <span class="reglage-card-resume"><?= e($assoNom) ?><?= $assoLogo !== '' ? ' · logo' : '' ?></span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
        <p class="meta" style="margin-bottom: var(--sp-3);">
            Nom affiché en en-tête et logo optionnel : URL publique vers une image
            hébergée ailleurs, par exemple sur le site de l’asso.
        </p>

        <form action="/settings/update" method="post" class="form-carte">
            <?= Csrf::champs() ?>
            <label class="field">
                <span class="field-label">Nom de l’asso</span>
                <input type="text" name="asso_nom" required maxlength="80"
                       value="<?= e($assoNom) ?>" placeholder="ex. AMAP du quartier, salle de répète…">
            </label>
            <label class="field">
                <span class="field-label">URL du logo <span class="meta">(facultatif, http/https)</span></span>
                <input type="url" name="asso_logo_url" maxlength="500"
                       value="<?= e($assoLogo) ?>" placeholder="https://mon-asso.org/logo.png">
            </label>
            <?php if ($assoLogo !== ''): ?>
                <p class="meta">Aperçu actuel : <img src="<?= e($assoLogo) ?>" alt="logo" style="max-height: 40px; vertical-align: middle;"></p>
            <?php endif; ?>
            <button type="submit" class="btn btn--filled">Enregistrer</button>
        </form>
    </div>
</details>

<details class="reglage-card" id="bandeau">
    <summary>
        <span class="reglage-card-titre">Bandeau d’accueil</span>
        <span class="reglage-card-resume"><?= $bandeau !== '' ? 'défini' : 'vide' ?></span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
        <p class="meta" style="margin-bottom: var(--sp-3);">
            Zone de texte libre affichée au-dessus du calendrier sur la page d’accueil.<br>
            Pratique pour rappeler les règles (référent·e obligatoire, mode d’emploi…), partager un numéro de téléphone du gymnase ou une info importante.<br>
            Gras, italique, liens et listes autorisés.
        </p>

        <form action="/settings/bandeau/update" method="post" class="form-carte">
            <?= Csrf::champs() ?>
            <div class="wysiwyg-toolbar" role="toolbar" aria-label="Mise en forme">
                <button type="button" data-cmd="bold"                 title="Gras (Ctrl+B)"><strong>B</strong></button>
                <button type="button" data-cmd="italic"               title="Italique (Ctrl+I)"><em>I</em></button>
                <button type="button" data-cmd="formatBlock" data-arg="h3" title="Titre">H</button>
                <button type="button" data-cmd="insertUnorderedList"  title="Liste à puces">•</button>
                <button type="button" data-cmd="insertOrderedList"    title="Liste numérotée">1.</button>
                <button type="button" data-cmd="createLink"           title="Ajouter un lien">🔗</button>
                <button type="button" data-cmd="removeFormat"         title="Retirer la mise en forme">⎯</button>
                <span class="wysiwyg-toolbar-sep" aria-hidden="true"></span>
                <button type="button" id="bandeau-mode-toggle" class="wysiwyg-toggle"
                        aria-pressed="false" title="Voir / modifier le code HTML">
                    &lt;/&gt;
                </button>
            </div>
            <div id="bandeau-editor" class="wysiwyg" contenteditable="true"
                 aria-label="Contenu du bandeau"><?= $bandeau /* déjà sanitizé au save */ ?></div>
            <textarea id="bandeau-source" name="html" class="wysiwyg-source"
                      rows="12" spellcheck="false" hidden
                      aria-label="Code HTML du bandeau"><?= e($bandeau) ?></textarea>
            <p class="meta wysiwyg-aide">
                Édite normalement avec les boutons ci-dessus. Bouton
                <code>&lt;/&gt;</code> pour voir / corriger le code HTML si besoin.<br>
                Balises conservées&nbsp;: <code>&lt;p&gt;</code> <code>&lt;strong&gt;</code>
                <code>&lt;em&gt;</code> <code>&lt;h3&gt;</code> <code>&lt;ul&gt;</code>
                <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code> <code>&lt;a href&gt;</code>.
                Tout le reste est retiré à l’enregistrement.
            </p>
            <div class="modele-edit-actions">
                <button type="submit" class="btn btn--filled">Enregistrer le bandeau</button>
            </div>
        </form>
        <script src="/assets/wysiwyg.js" defer></script>
    </div>
</details>

<details class="reglage-card" id="modeles">
    <summary>
        <span class="reglage-card-titre">Modèles de créneaux récurrents</span>
        <span class="reglage-card-resume"><?= count($modeles) ?> modèle<?= count($modeles) > 1 ? 's' : '' ?></span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
    <?php if (empty($modeles)): ?>
        <div class="etat-vide">
            <p>Aucun modèle pour l’instant.</p>
            <p class="meta">Ajoute-en un ci-dessous, ou charge un exemple de semaine type en un clic.</p>
            <form action="/modele/semaine-type" method="post" class="inline">
                <?= Csrf::champs() ?>
                <button type="submit" class="btn btn--filled">
                    <?= icon('add', 18) ?>
                    <span>Charger la semaine type</span>
                </button>
            </form>
        </div>
    <?php else: ?>
        <?php
            $joursFr = [
                1 => 'lundi',   2 => 'mardi',    3 => 'mercredi',
                4 => 'jeudi',   5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche',
            ];
            // Batch queries : évite N+1 dans la boucle (une requête au lieu
            // d'une par modèle pour labelsParModele et idsAttachesModele).
            $modeleIds      = array_map(static fn($m) => (int)$m['id'], $modeles);
            $labelsParModId = LabelRepo::labelsParModeles($pdo, $modeleIds);
            $attachesParMod = LabelRepo::idsAttachesModeles($pdo, $modeleIds);
        ?>
        <ul class="liste-modeles">
            <?php foreach ($modeles as $m):
                $actif = (int)$m['active'] === 1;
                $modId = (int)$m['id'];
                $moment = momentJour($m['heure_debut']);
            ?>
                <li class="modele<?= $actif ? '' : ' modele--inactif' ?>" id="modele-<?= $modId ?>">
                    <details class="modele-details">
                        <summary class="modele-summary">
                            <span class="modele-summary-inner">
                                <span class="modele-jour-bloc">
                                    <span class="modele-jour"><?= e($joursFr[(int)$m['jour_semaine']] ?? '?') ?> <span class="modele-moment"><?= e($moment) ?></span></span>
                                    <span class="modele-horaire">
                                        <?= e(DateFr::formatPlage($m['heure_debut'], $m['heure_fin'])) ?>
                                    </span>
                                </span>

                                <span class="modele-chips">
                                    <?php foreach ($labelsParModId[$modId] ?? [] as $l): ?>
                                        <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!$actif): ?>
                                        <span class="chip chip--absente">
                                            <span class="chip-dot" aria-hidden="true"></span>
                                            inactif
                                        </span>
                                    <?php endif; ?>
                                </span>

                                <span class="modele-actions">
                                    <button type="submit" form="dupliquer-modele-<?= $modId ?>"
                                            class="btn-icone"
                                            title="Dupliquer ce modèle" aria-label="Dupliquer ce modèle"
                                            onclick="event.stopPropagation();">
                                        <?= icon('content_copy', 18) ?>
                                    </button>
                                    <span class="modele-chevron" aria-hidden="true">
                                        <?= icon('edit', 18) ?>
                                    </span>
                                </span>
                            </span>
                        </summary>
                        <form id="supprimer-modele-<?= $modId ?>"
                              action="/modele/<?= $modId ?>/supprimer" method="post" class="form-cache"
                              onsubmit="return confirm('Supprimer ce modèle ? Les créneaux déjà générés ne sont pas touchés.');">
                            <?= Csrf::champs() ?>
                        </form>
                        <form id="dupliquer-modele-<?= $modId ?>"
                              action="/modele/<?= $modId ?>/dupliquer" method="post" class="form-cache">
                            <?= Csrf::champs() ?>
                        </form>
                        <form action="/modele/<?= $modId ?>/update" method="post" class="modele-edit">
                            <?= Csrf::champs() ?>
                            <label class="field">
                                <span class="field-label">Jour de la semaine</span>
                                <select name="jour_semaine" required>
                                    <?php foreach ($joursFr as $n => $nom): ?>
                                        <option value="<?= $n ?>" <?= (int)$m['jour_semaine'] === $n ? 'selected' : '' ?>><?= e($nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="field-row">
                                <label class="field">
                                    <span class="field-label">De</span>
                                    <input type="time" name="heure_debut" required value="<?= e($m['heure_debut']) ?>">
                                </label>
                                <label class="field">
                                    <span class="field-label">À</span>
                                    <input type="time" name="heure_fin" required value="<?= e($m['heure_fin']) ?>">
                                </label>
                            </div>
                            <label class="field">
                                <span class="field-label">Capacité <span class="meta">(cas particulier)</span></span>
                                <input type="number" name="capacite" min="1" max="500" value="<?= (int)$m['capacite'] ?>" required>
                            </label>
                            <?php if (!empty($labels)): ?>
                                <?php $attaches = $attachesParMod[$modId] ?? []; ?>
                                <fieldset class="field-labels">
                                    <legend class="field-label">Étiquettes</legend>
                                    <?php foreach ($labels as $l): ?>
                                        <label class="check">
                                            <input type="checkbox" name="labels[]" value="<?= (int)$l['id'] ?>"
                                                   <?= in_array((int)$l['id'], $attaches, true) ? 'checked' : '' ?>>
                                            <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                            <label class="field">
                                <span class="field-label">Note par défaut <span class="meta">(facultatif, retours à la ligne conservés)</span></span>
                                <textarea name="note_defaut" maxlength="500" rows="3"><?= e($m['note_defaut'] ?? '') ?></textarea>
                            </label>
                            <label class="check">
                                <input type="checkbox" name="active" value="1" <?= $actif ? 'checked' : '' ?>>
                                <span>Actif <span class="meta">(générer ce créneau chaque semaine)</span></span>
                            </label>
                            <div class="modele-edit-actions">
                                <button type="submit" form="supprimer-modele-<?= $modId ?>"
                                        class="btn-text btn-text--danger">
                                    Supprimer
                                </button>
                                <button type="submit" class="btn btn--filled">Enregistrer</button>
                            </div>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="sous-section">
        <h4>Ajouter un modèle</h4>
        <?php if (!empty($labels)): ?>
            <p class="meta" style="margin-bottom: var(--sp-3);">Les étiquettes gérées dans la carte « Étiquettes&nbsp;libres » peuvent être attachées à chaque modèle.</p>
        <?php endif; ?>

        <form action="/modele/ajouter" method="post" class="form-carte">
        <?= Csrf::champs() ?>
        <label class="field">
            <span class="field-label">Jour de la semaine</span>
            <select name="jour_semaine" required>
                <option value="1">lundi</option>
                <option value="2">mardi</option>
                <option value="3">mercredi</option>
                <option value="4">jeudi</option>
                <option value="5">vendredi</option>
                <option value="6">samedi</option>
                <option value="7">dimanche</option>
            </select>
        </label>
        <div class="field-row">
            <label class="field">
                <span class="field-label">De</span>
                <input type="time" name="heure_debut" required value="18:00">
            </label>
            <label class="field">
                <span class="field-label">À</span>
                <input type="time" name="heure_fin" required value="22:30">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Limite d’inscription <span class="meta">(facultatif, cas particulier)</span></span>
            <input type="number" name="capacite" min="1" max="500" value="15" required>
        </label>
        <?php if (!empty($labels)): ?>
            <fieldset class="field-labels">
                <legend class="field-label">Étiquettes</legend>
                <?php foreach ($labels as $l): ?>
                    <label class="check">
                        <input type="checkbox" name="labels[]" value="<?= (int)$l['id'] ?>">
                        <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <label class="field">
            <span class="field-label">Note par défaut <span class="meta">(facultatif)</span></span>
            <textarea name="note_defaut" maxlength="500" rows="3" placeholder="ex. séance progression vol"></textarea>
        </label>
        <button type="submit" class="btn btn--filled">
            <?= icon('add', 18) ?>
            <span>Ajouter le modèle</span>
        </button>
    </form>
    </div><!-- /.sous-section -->
    </div><!-- /.reglage-card-corps -->
</details>

<details class="reglage-card" id="labels">
    <summary>
        <span class="reglage-card-titre">Étiquettes libres</span>
        <span class="reglage-card-resume"><?= count($labels) ?> étiquette<?= count($labels) > 1 ? 's' : '' ?></span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
    <p class="meta" style="margin-bottom: var(--sp-3);">
        Catégories personnalisables (ex. « Séance progression », « Check matos »).<br>
        À attacher aux créneaux et aux modèles récurrents.
    </p>

    <?php if (empty($labels)): ?>
        <div class="etat-vide">
            <p class="meta">Aucune étiquette pour l’instant.</p>
        </div>
    <?php else: ?>
        <ul class="liste-modeles">
            <?php foreach ($labels as $l): ?>
                <li class="modele" id="label-<?= (int)$l['id'] ?>">
                    <details class="modele-details">
                        <summary class="modele-summary">
                            <span class="modele-summary-inner">
                                <span class="modele-jour-bloc">
                                    <span class="modele-jour"><?= e($l['nom']) ?></span>
                                </span>
                                <span class="modele-chips">
                                    <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                                </span>
                                <span class="modele-actions">
                                    <span class="modele-chevron" aria-hidden="true">
                                        <?= icon('edit', 18) ?>
                                    </span>
                                </span>
                            </span>
                        </summary>
                        <form id="label-supprimer-<?= (int)$l['id'] ?>"
                              action="/label/<?= (int)$l['id'] ?>/supprimer" method="post" class="form-cache"
                              onsubmit="return confirm('Supprimer cette étiquette ? Elle sera retirée de tous les créneaux et modèles associés.');">
                            <?= Csrf::champs() ?>
                        </form>
                        <form action="/label/<?= (int)$l['id'] ?>/update" method="post" class="modele-edit">
                            <?= Csrf::champs() ?>
                            <label class="field">
                                <span class="field-label">Nom</span>
                                <input type="text" name="nom" required maxlength="40" value="<?= e($l['nom']) ?>">
                            </label>
                            <label class="field field-color">
                                <span class="field-label">Couleur</span>
                                <input type="color" name="couleur" value="<?= e($l['couleur']) ?>">
                            </label>
                            <label class="check">
                                <input type="checkbox" name="bloque_inscriptions" value="1"
                                       <?= (int)($l['bloque_inscriptions'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>Bloque les inscriptions <span class="meta">(salle fermée, séance privée…)</span></span>
                            </label>
                            <label class="check">
                                <input type="checkbox" name="ouvre_voisines" value="1"
                                       <?= (int)($l['ouvre_voisines'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>Ouvre aux voisin·es <span class="meta">(accepte les non-adhérent·es)</span></span>
                            </label>
                            <label class="check">
                                <input type="checkbox" name="sans_referent" value="1"
                                       <?= (int)($l['sans_referent'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>Pas de référent·e requis·e <span class="meta">(AG, événement…)</span></span>
                            </label>
                            <div class="modele-edit-actions">
                                <button type="submit" form="label-supprimer-<?= (int)$l['id'] ?>"
                                        class="btn-text btn-text--danger">Supprimer</button>
                                <button type="submit" class="btn btn--filled">Enregistrer</button>
                            </div>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="sous-section">
        <h4>Ajouter une étiquette</h4>
        <form action="/label/ajouter" method="post" class="form-carte">
        <?= Csrf::champs() ?>
        <label class="field">
            <span class="field-label">Nom</span>
            <input type="text" name="nom" required maxlength="40" placeholder="ex. Séance progression, Check matos">
        </label>
        <label class="field field-color">
            <span class="field-label">Couleur</span>
            <input type="color" name="couleur" value="<?= e(LabelRepo::DEFAUT) ?>">
        </label>
        <label class="check">
            <input type="checkbox" name="bloque_inscriptions" value="1">
            <span>Bloque les inscriptions <span class="meta">(salle fermée, séance privée…)</span></span>
        </label>
        <label class="check">
            <input type="checkbox" name="ouvre_voisines" value="1">
            <span>Ouvre aux voisin·es <span class="meta">(accepte les non-adhérent·es)</span></span>
        </label>
        <label class="check">
            <input type="checkbox" name="sans_referent" value="1">
            <span>Pas de référent·e requis·e <span class="meta">(AG, événement…)</span></span>
        </label>
        <button type="submit" class="btn btn--filled">
            <?= icon('add', 18) ?>
            <span>Ajouter l’étiquette</span>
        </button>
    </form>
    </div><!-- /.sous-section -->
    </div><!-- /.reglage-card-corps -->
</details>

<details class="reglage-card" id="plage">
    <summary>
        <span class="reglage-card-titre">Étiquettes par plage</span>
        <span class="reglage-card-resume">Étiquetage en masse sur une période</span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
    <p class="meta" style="margin-bottom: var(--sp-3);">
        Ajoute ou retire des étiquettes sur tous les créneaux d’une période, d’un coup.<br>
        Pratique pour marquer un été « ouverture » ou retirer une étiquette héritée d’un modèle sur quelques semaines.
    </p>

    <?php if (empty($labels)): ?>
        <div class="etat-vide">
            <p class="meta">Crée d’abord une étiquette dans la carte « Étiquettes&nbsp;libres ».</p>
        </div>
    <?php else: ?>
        <form action="/plage/etiquettes" method="post" class="form-carte">
            <?= Csrf::champs() ?>
            <div class="field-row">
                <label class="field">
                    <span class="field-label">Du</span>
                    <input type="date" name="debut" required>
                </label>
                <label class="field">
                    <span class="field-label">Au</span>
                    <input type="date" name="fin" required>
                </label>
            </div>
            <fieldset class="field-labels">
                <legend class="field-label">Jours concernés</legend>
                <div class="plage-jours">
                    <?php foreach ([1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'] as $num => $nom): ?>
                        <label class="check">
                            <input type="checkbox" name="jours_semaine[]" value="<?= $num ?>" checked>
                            <span><?= $nom ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="field-labels">
                <legend class="field-label">Étiquettes à appliquer</legend>
                <?php foreach ($labels as $l): $lid = (int)$l['id']; ?>
                    <div class="plage-action">
                        <span class="chip" style="<?= e(chipStyleLabel($l['couleur'])) ?>"><?= e($l['nom']) ?></span>
                        <span class="plage-action-choix">
                            <label class="check"><input type="radio" name="action[<?= $lid ?>]" value="rien" checked><span>Laisser</span></label>
                            <label class="check"><input type="radio" name="action[<?= $lid ?>]" value="ajouter"><span>Ajouter</span></label>
                            <label class="check"><input type="radio" name="action[<?= $lid ?>]" value="retirer"><span>Retirer</span></label>
                        </span>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <div class="plage-apercu-zone">
                <button type="button" class="btn-text"
                        hx-get="/plage/apercu" hx-include="closest form" hx-target="#plage-apercu">
                    <?= icon('history', 18) ?>
                    <span>Aperçu des créneaux concernés</span>
                </button>
                <div id="plage-apercu"></div>
            </div>
            <button type="submit" class="btn btn--filled">
                <?= icon('schedule', 18) ?>
                <span>Appliquer à la plage</span>
            </button>
        </form>
    <?php endif; ?>

    <?php if (!empty($plageOps)): ?>
        <?php
            $joursAbrev  = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
            $labelsParId = [];
            foreach ($labels as $lbl) { $labelsParId[(int)$lbl['id']] = $lbl; }
        ?>
        <div class="sous-section">
            <h4>Dernières applications</h4>
            <p class="meta" style="margin-bottom: var(--sp-2);">
                « Reprendre » recharge ces réglages dans le formulaire ci-dessus pour vérifier ou corriger (ex. repasser en <em>Retirer</em>).
            </p>
            <ul class="liste-modeles plage-historique">
                <?php foreach ($plageOps as $op):
                    $joursTxt = count($op['jours_semaine']) === 7
                        ? 'tous les jours'
                        : implode(', ', array_map(fn(int $n): string => $joursAbrev[$n] ?? '?', $op['jours_semaine']));
                ?>
                    <li class="modele plage-op"
                        data-debut="<?= e($op['debut']) ?>"
                        data-fin="<?= e($op['fin']) ?>"
                        data-jours="<?= e(implode(',', $op['jours_semaine'])) ?>"
                        data-ajouter="<?= e(implode(',', $op['labels_ajoutes'])) ?>"
                        data-retirer="<?= e(implode(',', $op['labels_retires'])) ?>">
                        <div class="plage-op-tete">
                            <span class="meta"><?= e($op['cree_le']) ?></span>
                            <button type="button" class="btn-text plage-op-reprendre">
                                <?= icon('history', 16) ?><span>Reprendre</span>
                            </button>
                        </div>
                        <div class="meta plage-op-meta">
                            <?= e($op['debut']) ?> → <?= e($op['fin']) ?> · <?= e($joursTxt) ?> · <?= (int)$op['nb_creneaux'] ?> créneau<?= $op['nb_creneaux'] > 1 ? 'x' : '' ?>
                        </div>
                        <span class="modele-chips">
                            <?php foreach ($op['labels_ajoutes'] as $lid): $lbl = $labelsParId[$lid] ?? null; ?>
                                <span class="chip" style="<?= $lbl ? e(chipStyleLabel($lbl['couleur'])) : '' ?>">+&nbsp;<?= $lbl ? e($lbl['nom']) : '#' . (int)$lid ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($op['labels_retires'] as $lid): $lbl = $labelsParId[$lid] ?? null; ?>
                                <span class="chip plage-op-chip-retire" style="<?= $lbl ? e(chipStyleLabel($lbl['couleur'])) : '' ?>">−&nbsp;<?= $lbl ? e($lbl['nom']) : '#' . (int)$lid ?></span>
                            <?php endforeach; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    </div><!-- /.reglage-card-corps -->
</details>

<details class="reglage-card" id="fermetures">
    <summary>
        <span class="reglage-card-titre">Jours de fermeture du gymnase</span>
        <span class="reglage-card-resume"><?= count($fermetures) ?> date<?= count($fermetures) > 1 ? 's' : '' ?></span>
        <span class="reglage-card-chevron" aria-hidden="true"><?= icon('expand_more', 20) ?></span>
    </summary>
    <div class="reglage-card-corps">
    <p class="meta" style="margin-bottom: var(--sp-3);">
        Les dates déclarées ici apparaîtront sur le calendrier comme « Gymnase&nbsp;fermé », et aucun créneau ne sera généré ces jours-là.<br>
        Pratique pour les jours fériés, travaux ou fermetures exceptionnelles.
    </p>

    <?php
        $ajoutees  = isset($_GET['ajoutees']) ? (int)$_GET['ajoutees'] : -1;
        $ignorees  = isset($_GET['ignorees']) ? (int)$_GET['ignorees'] : 0;
        $supprimes = isset($_GET['supprimes']) ? (int)$_GET['supprimes'] : 0;
    ?>
    <?php if ($ajoutees >= 0): ?>
        <p class="alerte-succes">
            <?= $ajoutees ?> fermeture<?= pluriel($ajoutees) ?> ajoutée<?= pluriel($ajoutees) ?><?php if ($ignorees > 0): ?>,
            <?= $ignorees ?> ignorée<?= pluriel($ignorees) ?> (doublons ou invalides)<?php endif; ?><?php if ($supprimes > 0): ?>,
            <?= $supprimes ?> créneau<?= pluriel($supprimes, 'x') ?> existant<?= pluriel($supprimes) ?> retiré<?= pluriel($supprimes) ?><?php endif; ?>.
        </p>
    <?php endif; ?>

    <?php if (empty($fermetures)): ?>
        <div class="etat-vide">
            <p class="meta">Aucune fermeture déclarée.</p>
        </div>
    <?php else: ?>
        <?php
            $parAnnee = [];
            foreach ($fermetures as $f) {
                $parAnnee[substr($f['date'], 0, 4)][] = $f;
            }
        ?>
        <div class="fermetures-bulk">
            <form id="form-supprimer-lot" action="/fermeture/supprimer-lot" method="post">
                <?= Csrf::champs() ?>
            </form>
            <div class="fermetures-toolbar">
                <label class="master-check-label">
                    <input type="checkbox" id="master-check" class="master-check">
                    <span id="master-check-texte">Tout sélectionner</span>
                </label>
                <span class="meta fermetures-astuce">Maj+clic pour sélectionner une plage</span>
            </div>
            <?php $anneeActuelle = (int)date('Y'); ?>
            <?php foreach ($parAnnee as $annee => $liste): ?>
                <details class="fermeture-annee-groupe"<?= (int)$annee === $anneeActuelle ? ' open' : '' ?>>
                    <summary class="fermeture-annee">
                        <span class="fermeture-annee-titre"><?= e((string)$annee) ?> <span class="meta">— <?= count($liste) ?> fermeture<?= count($liste) > 1 ? 's' : '' ?></span></span>
                        <span class="fermeture-annee-chevron" aria-hidden="true"><?= icon('expand_more', 18) ?></span>
                    </summary>
                    <ul class="liste-fermetures">
                        <?php foreach ($liste as $f): ?>
                        <?php
                            $d = new DateTimeImmutable($f['date']);
                            $libelle = DateFr::formatCourt($d);
                        ?>
                        <li class="fermeture-ligne">
                            <input type="checkbox" class="fermeture-check"
                                   name="ids[]" value="<?= (int)$f['id'] ?>"
                                   form="form-supprimer-lot"
                                   aria-label="Sélectionner la fermeture du <?= e($libelle) ?>">
                            <span class="fermeture-date"><?= e($libelle) ?></span>
                            <?php if (!empty($f['note'])): ?>
                                <span class="fermeture-note"><?= e($f['note']) ?></span>
                            <?php endif; ?>
                            <form action="/fermeture/<?= (int)$f['id'] ?>/supprimer" method="post" class="inline"
                                  onsubmit="return confirm('Retirer la fermeture du <?= e($libelle) ?> ?');">
                                <?= Csrf::champs() ?>
                                <button type="submit" class="btn-icone" aria-label="Retirer la fermeture">
                                    <?= icon('close', 18) ?>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
            <div class="barre-supprimer-lot" aria-live="polite">
                <span class="barre-compteur">
                    <span id="barre-compteur-n">0</span> sélectionnée<span id="barre-compteur-s" hidden>s</span>
                </span>
                <button type="button" class="btn-text" id="barre-decocher">Tout décocher</button>
                <button type="submit" class="btn btn--danger" form="form-supprimer-lot">
                    Supprimer la sélection
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="sous-section">
        <h4>Déclarer des fermetures</h4>
        <p class="meta" style="margin-bottom: var(--sp-3);">
            Choisis une date, ajoute une note si tu veux, puis clique sur « Ajouter&nbsp;à la liste ».<br>
            Répète autant de fois qu’il faut, et déclare tout d’un coup.
        </p>
        <form id="form-multi" action="/fermeture/ajouter-lot" method="post" class="form-carte">
            <?= Csrf::champs() ?>
            <div class="field-row">
                <label class="field">
                    <span class="field-label">Date</span>
                    <input type="date" id="multi-date">
                </label>
                <label class="field">
                    <span class="field-label">Note <span class="meta">(facultatif, ex. « travaux », « jour férié »)</span></span>
                    <input type="text" id="multi-note" maxlength="200" placeholder="ex. 1er mai">
                </label>
            </div>
            <button type="button" id="multi-ajouter" class="btn">
                <?= icon('add', 18) ?>
                <span>Ajouter à la liste</span>
            </button>
            <ul id="multi-liste" class="liste-fermetures" hidden></ul>
            <div class="ics-actions" id="multi-actions" hidden>
                <button type="button" id="multi-vider" class="btn-text">Tout retirer</button>
                <button type="submit" class="btn btn--filled">
                    <?= icon('add', 18) ?>
                    <span id="multi-btn-label">Déclarer la fermeture</span>
                </button>
            </div>
        </form>

        <template id="tpl-fermeture-ligne">
            <li class="fermeture-ligne">
                <span class="fermeture-date"></span>
                <span class="fermeture-note"></span>
                <input type="hidden" name="dates[]" value="">
                <input type="hidden" name="notes[]" value="">
                <button type="button" class="btn-icone" aria-label="Retirer cette date">
                    <?= icon('close', 18) ?>
                </button>
            </li>
        </template>
    </div>

    <div class="sous-section">
        <h4>Importer depuis un fichier .ics</h4>
        <p class="meta" style="margin-bottom: var(--sp-3);">
            Pour les jours fériés français, télécharger
            <a href="https://etalab.github.io/jours-feries-france-data/ics/jours_feries_metropole.ics" rel="noopener noreferrer" target="_blank">jours_feries_metropole.ics</a>
            (source : <a href="https://www.data.gouv.fr/datasets/jours-feries-en-france" rel="noopener noreferrer" target="_blank">data.gouv.fr</a>),
            puis le sélectionner ci-dessous.<br>
            Le fichier est analysé directement dans ton navigateur. Rien n’est envoyé tant que tu n’as pas validé l’import.
        </p>

        <form id="form-ics" action="/fermeture/ajouter-lot" method="post" class="form-carte">
            <?= Csrf::champs() ?>
            <label class="field">
                <span class="field-label">Fichier .ics</span>
                <input type="file" id="input-ics" accept=".ics,text/calendar">
            </label>
            <div id="ics-preview" hidden>
                <p class="meta" id="ics-resume"></p>
                <div class="ics-actions">
                    <button type="button" id="ics-tout-cocher" class="btn-text">Tout cocher</button>
                    <button type="button" id="ics-tout-decocher" class="btn-text">Tout décocher</button>
                </div>
                <ul id="ics-liste" class="liste-fermetures"></ul>
                <button type="submit" class="btn btn--filled">
                    <?= icon('add', 18) ?>
                    <span>Importer les dates cochées</span>
                </button>
            </div>
        </form>
    </div>

    <script src="/assets/ics-import.js" defer></script>
    <script src="/assets/fermetures-multi.js" defer></script>
    <script src="/assets/fermetures-supprimer-lot.js" defer></script>
    <script src="/assets/plage-historique.js" defer></script>
    </div><!-- /.reglage-card-corps -->
</details>

<script>
/* Ouvre automatiquement la carte pointée par le hash de l'URL (ex. #fermetures). */
(function () {
    function ouvrirDepuisHash() {
        const hash = window.location.hash.replace(/^#/, '');
        if (!hash) return;
        const card = document.getElementById(hash);
        if (card && card.tagName === 'DETAILS') {
            card.open = true;
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    ouvrirDepuisHash();
    window.addEventListener('hashchange', ouvrirDepuisHash);
})();
</script>
