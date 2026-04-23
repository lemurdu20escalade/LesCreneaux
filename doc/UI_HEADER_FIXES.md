# Correctifs — en-tête `/mois/{mois}`

Audit UX/UI + frontend sur :
- bouton « Afficher les infos importantes » (`app/views/mois.php:11-14`)
- bandeau + bouton ×  (`app/views/mois.php:15-20`)
- navigation mois (`app/views/mois.php:52-60`)

## Statut — refonte appliquée 2026-04-23

La zone a été refondue (`<details>` natif pour le bandeau + correctifs nav mois).
Ce fichier reste pour trace d'audit.

Correctifs intégrés à la refonte :
- ✓ #1 Touch targets (`min-height: 44px` sur `.nav-btn` et `.bandeau-summary`)
- ✓ #3 aria-label sur les flèches nav (mois précédent/suivant)
- ✓ #4 `:focus-visible` explicite sur `.nav-btn` et `.bandeau-summary`
- ✓ #6 ellipsis + `min-width: 0` sur `.titre-mois`
- ✓ #7 `font-weight: 500` sur `.titre-mois`
- ✓ #9 label « Infos du mois » (plus court, moins prescriptif)
- N/A #2 ARIA toggle bandeau : `<details>` natif fournit l'a11y gratuitement (bouton summary, ouvert/fermé annoncé)
- N/A #5 `<aside>` aria-label : plus de `<aside>` (remplacé par `<details>`)
- N/A #8 transition visuelle : chevron animé seul ; animation de dépliage non rétro-compatible
- ✗ #10 `<h2>` hors `<nav>` : non appliqué (restructuration non justifiée — le `<nav>` a déjà `aria-label`)
- ✗ #11 `rel="prev"/"next"` dans `<head>` : non appliqué (demanderait que layout.php accepte ces variables)

## Critique (3)

### 1. Touch targets < 44×44 px

`.nav-btn` : padding `--sp-2 --sp-3` + icône 18 + texte 13 ≈ 30-34 px de haut.
`.bandeau-toggle--montrer` : padding `--sp-1 --sp-3` + texte 13 ≈ 21-25 px.

**Fix** (`www/assets/app.css:165` et `:466`) :
```css
.nav-btn {
    /* ... existant ... */
    min-height: 44px;
}
.bandeau-toggle--montrer {
    /* ... existant ... */
    min-height: 44px;
    padding: var(--sp-2) var(--sp-3);
}
```

### 2. ARIA manquantes sur les boutons toggle du bandeau

`#bandeau-montrer` et `#bandeau-cacher` n'ont ni `aria-expanded` ni `aria-controls`.

**Fix** (`app/views/mois.php`) :
```html
<button type="button" id="bandeau-montrer" class="bandeau-toggle bandeau-toggle--montrer"
        aria-expanded="false" aria-controls="bandeau-content" hidden>
```
```html
<button type="button" id="bandeau-cacher" class="bandeau-close"
        aria-expanded="true" aria-controls="bandeau-content"
        aria-label="Masquer les infos">
```

Dans le JS, synchroniser `aria-expanded` dans `appliquer()` :
```js
const ouvert = !estCache();
bandeau.hidden = !ouvert;
montrer.hidden = ouvert;
montrer.setAttribute('aria-expanded', String(ouvert));
cacher.setAttribute('aria-expanded', String(ouvert));
```

### 3. Flèches nav sans contexte pour les lecteurs d'écran

Un lecteur d'écran annonce « lien Juin » sans préciser « mois précédent ».

**Fix** (`app/views/mois.php:53-58`) :
```html
<a href="/mois/<?= e($prev) ?>" rel="prev" class="nav-btn"
   aria-label="Mois précédent : <?= e(ucfirst($moisPrev)) ?>">
    <?= icon('arrow_back', 18) ?><span><?= e(ucfirst($moisPrev)) ?></span>
</a>
<a href="/mois/<?= e($next) ?>" rel="next" class="nav-btn"
   aria-label="Mois suivant : <?= e(ucfirst($moisNext)) ?>">
    <span><?= e(ucfirst($moisNext)) ?></span><?= icon('arrow_forward', 18) ?>
</a>
```

## Important (5)

### 4. `:focus-visible` explicite sur `.nav-btn` et `.bandeau-toggle--montrer`

La règle globale ne garantit pas un contraste suffisant sur ces fonds.

**Fix** (`www/assets/app.css`) :
```css
.nav-btn:focus-visible,
.bandeau-toggle--montrer:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
```

### 5. `<aside>` sans nom accessible

**Fix** (`app/views/mois.php:15`) :
```html
<aside class="bandeau" id="bandeau-content" aria-label="Informations du mois">
```

### 6. Titre mois peut se couper sur mobile étroit

`.titre-mois` avec `flex: 1` sans contrainte d'overflow.

**Fix** (`www/assets/app.css:158`) :
```css
.titre-mois {
    font-size: 22px;
    font-weight: 500;              /* +hiérarchie, voir #7 */
    letter-spacing: -0.01em;
    text-align: center;
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
```

### 7. `font-weight: 400` sur `.titre-mois` — hiérarchie faible

Fusionné avec #6 : `font-weight: 500`.

### 8. Saut visuel sans transition à l'affichage/masquage du bandeau

**Fix** : soit animation `max-height` (complexe à mesurer), soit simple `opacity` :
```css
.bandeau, .bandeau-toggle--montrer {
    transition: opacity 150ms ease;
}
.bandeau[hidden], .bandeau-toggle--montrer[hidden] {
    display: none;    /* reste nécessaire pour retirer le flow */
}
```
(Limite : `display: none` court-circuite la transition. Alternative propre = utiliser `.hidden` class + `opacity 0 + pointer-events: none` au lieu de l'attribut `hidden`.)

## Mineur (3)

### 9. Label « importantes » ambigu

Un bandeau masquable par l'utilisateur n'est pas critique. Remplacer par « Afficher les annonces » ou « Afficher les informations du mois ».

### 10. `<h2>` enfant direct de `<nav>`

Pattern inhabituel. Options :
- Sortir le `<h2>` du `<nav>` (restructuration DOM)
- Conserver tel quel (`aria-label="Navigation entre mois"` déjà présent sur le `<nav>`, donc le landmark est nommé indépendamment du h2)

### 11. `rel="prev"/"next"` sur `<a>`

Non standard sur `<a>` (définis pour `<link>` en `<head>`). Retirer et ajouter dans `<head>` via `layout.php` :
```html
<link rel="prev" href="/mois/<?= e($prev) ?>">
<link rel="next" href="/mois/<?= e($next) ?>">
```

## Non appliqués — faux positifs

- Icône dans `#bandeau-montrer` sans `aria-hidden` : en fait `helpers.php icon()` émet déjà `aria-hidden="true"` sur le SVG (`app/src/helpers.php:187`). RAS.
- `hidden` + `display: none` redondant : défensif contre un reset CSS. Ok.
- Script inline rejoué au refresh htmx : le script est hors de `#liste-jours` (la zone swappée), pas de double-binding.
