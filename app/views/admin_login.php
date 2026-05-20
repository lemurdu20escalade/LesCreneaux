<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
/**
 * Page de connexion admin. Attend $erreur (string|null) et $retour (string).
 * Défauts défensifs pour PHPStan qui ne suit pas les variables passées via
 * scope d'include — runtime, le contrôleur les renseigne toujours.
 */
$erreur = $erreur ?? null;
$retour = $retour ?? '/reglages';
?>
<header class="page-entete">
    <h2 class="page-titre">Connexion admin</h2>
    <p class="page-intro">
        Cette protection couvre les modifications des créneaux, des
        modèles, des étiquettes, des fermetures et des réglages.
    </p>
    <p class="page-intro">
        Les inscriptions et désinscriptions restent publiques.
    </p>
</header>

<?php if ($erreur !== null): ?>
    <p class="alerte" role="alert">
        <?= icon('error_outline', 18) ?>
        <span><?= e($erreur) ?></span>
    </p>
<?php endif; ?>

<form action="/admin/login" method="post" class="form-edit">
    <?= Csrf::champs() ?>
    <input type="hidden" name="retour" value="<?= e($retour) ?>">
    <label class="field">
        <span class="field-label">Mot de passe</span>
        <input type="password" name="password" required autocomplete="current-password"
               autofocus maxlength="200">
    </label>
    <button type="submit" class="btn btn--filled btn--large">Se connecter</button>
</form>
