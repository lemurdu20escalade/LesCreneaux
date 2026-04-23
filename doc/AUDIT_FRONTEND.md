# Audit frontend — logique, actions, cohérence

Audit en mode **observation passive** (pas d'intervention) — 2026-04-23.
Objectif : lister ce qui mérite d'être **optimisé** (pas amélioré, pas refondé).

Le site est simple, cohérent, la plupart des flux tiennent déjà la route.
Ce qui suit est un inventaire des frictions / doublons / micro-incohérences
qui pourraient être lissés sans toucher aux fonctionnalités.

Périmètre couvert : `/`, `/mois/{mois}`, `/jour/{id}`, `/reglages`, `/licence`,
drawer htmx, tous les POST côté `www/index.php`, JS de `www/assets/`.

---

## 1. Par page

### `/mois/{mois}` (`app/views/mois.php` + `_ligne.php` + `_fermeture.php`)

**OK**
- Navigation mois précédent/suivant (+ `aria-label` explicite, `min-height: 44px`).
- Bandeau d'accueil via `<details>` natif, mémoire localStorage (ouvert/fermé).
- Fusion chronologique créneaux + fermetures bien traitée (même clé de tri, date fermée écrase créneau).
- Polling htmx toutes les 30 s sur `#liste-jours`, ETag géré → 304 si rien changé.
- Historique des créneaux passés replié dans un `<details>` — bon réflexe visuel.
- Compteur d'historique n'agrège que les créneaux (ignore fermetures passées) — cohérent avec le label « créneau passé ».

**À optimiser**
- **Aucun bouton « Ajouter un créneau » côté UI** alors que la route `POST /jour/nouveau` existe (`www/index.php:248`). Route morte ou flux manquant ? À décider : supprimer la route ou exposer un formulaire dans `/reglages` (pas dans `/mois` si l'idée est de tout piloter par modèles).
- **Polling 30 s** : agressif pour une salle dont l'état change typiquement toutes les 10 min. Passer à 60 s ou 90 s coupe le trafic de moitié sans impact perceptible (ETag garantit déjà le coût 304 minimal, mais chaque poll = une requête HTTP complète).
- **`hx-preserve` n'est posé que sur l'input prénom du drawer**. Sur le mois, les `<details class="historique">` et cartes modèles perdent leur état ouvert à chaque swap de 30 s. Actuellement `hx-select="#liste-jours"` ne touche pas l'historique avant dépliage → OK. Mais une fois l'historique ouvert, un swap de la liste le re-rend fermé côté DOM, puis l'utilisateur doit rouvrir. À vérifier en live — si reproductible, un `hx-preserve` sur `.historique` suffirait.
- **`<li class="creneau">` → `<a class="creneau-link">`** : toute la ligne est cliquable, bon. Mais le compteur `.c-compteur`, les chips et la note sont aussi à l'intérieur du `<a>`. Conséquence : impossible d'ajouter un tap direct sur un chip (ex. filtrer) sans restructurer. À noter si un jour on veut des chips interactives.
- **`_ligne.php` — `$rendreRef`** : l'horaire ne s'affiche pas si `heure_fin === null`. Le `_detail.php`, lui, affiche `dès HHhMM` via `DateFr::formatPlage`. Incohérence volontaire (cf. commentaire « pas de pression à afficher ») — à garder, mais ça vaut un one-liner dans le readme de la vue pour que le lecteur futur ne re-ajoute pas le cas null.

### `/jour/{id}` (`app/views/_detail.php` — rendu en drawer OU page autonome)

**OK**
- Double rendu : full-page (`jour.php`) fallback sans JS, drawer htmx sinon — couverture no-JS propre.
- Compteur inscrit·es dans le titre de section (badge).
- Label « Avec référent·e / Encadré / Sans référent·e » avec chip coloré, libellé non culpabilisant.
- Confirmations `confirm()` partout où c'est destructif.

**À optimiser**
- **Compteur « Inscrit·es »** : affiche uniquement `count($jour['inscriptions'])`. Ne compte pas les référent·es présent·es physiquement dans la salle. C'est le sujet du todo en cours (tâche #1). À décider : compteur **double** (« 5 inscrit·es + 2 référent·es = 7 dans la salle ») ou **fusion** (total). La fusion risque de brouiller les sémantiques — le double-compteur est plus lisible mais prend de la place.
- **Hint texte du champ « À » (référent·e)** : affiche `22h30` (format FR lisible) mais le `<input type="time">` attend `22:30`. La personne doit mentalement convertir en tapant. Soit aligner le texte sur `22:30`, soit pré-remplir le champ via un lien/bouton « utiliser la fin du créneau ».
- **Bloc « Ajouter un·e référent·e »** replié dans un `<details>` même quand l'alerte « Sans référent·e, la salle n'ouvre pas » est affichée. L'utilisateur voit un appel à l'action mais rien ne se passe s'il clique dessus. Ouvrir le `<details>` par défaut quand `referentes` est vide serait plus évident (attribut `open` conditionnel, pas de JS).
- **POST inscription → redirect 303 → full reload** : le formulaire n'est pas boosté htmx, donc la soumission dans le drawer provoque un reload complet. L'utilisateur perd le drawer, re-scroll vers `#jour-X`, sans feedback visuel d'« ok, c'est pris en compte ». Soit ajouter `hx-post` + re-swap du drawer, soit assumer le full reload mais afficher un flash sur la ligne. Coût : quelques lignes, gain UX notable.
- **Erreurs 400 brutes** : `http_response_code(400); echo 'Prénom invalide';` → page blanche avec texte brut. Moche sur soumission native. Même remarque qu'au-dessus : un re-render du formulaire avec erreur in-place (via htmx) serait plus propre. À coupler au point précédent.
- **Focus auto sur `input[name="nom"]`** après ouverture du drawer (`layout.php:74`). Si le créneau est bloqué (label bloquant), il n'y a pas de champ nom → focus perdu (reste sur le lien). Minor.
- **Bouton « Supprimer ce créneau »** : dans le bloc `<details class="bloc-edition">`. Bien caché, OK. Le `confirm()` est correct. Suggestion : inclure la date dans le prompt (« Supprimer le créneau du 25 avril ? ») pour éviter un clic erroné sur tablette partagée.

### `/reglages` (`app/views/reglages.php`)

**OK**
- Structure en cartes `<details>` dépliables — bien sur mobile.
- Résumé dans chaque summary (« 5 modèles », « 12 dates »).
- Hash-link pour ouvrir une carte (`#fermetures`, `#labels`…).
- Flash succès après import lot via query string (simple, pas de session).
- Bandeau WYSIWYG avec sanitizer côté serveur (HtmlSanitizer).
- Bulk select fermetures avec Maj+clic pour plages — pattern solide.

**À optimiser**
- **`document.execCommand('createLink', …)`** + **`prompt('URL du lien…')`** dans le WYSIWYG du bandeau : API dépréciée (pas retirée, mais plus maintenue) + prompt natif bloquant mal stylé sur mobile. Fonctionne aujourd'hui, probablement OK pendant 5 ans, mais c'est le talon d'Achille à surveiller.
- **Pas de mémoire d'ouverture des cartes** entre navigations : si on modifie un modèle, submit, on est redirigé sur `/reglages#modeles` — le hash ouvre la carte, bien. Mais toutes les autres cartes se referment. OK pour l'instant.
- **Carte « Identité de l'asso »** : si logo vide, aperçu absent — bien. Mais pas de validation de taille/format côté client avant submit — le serveur ne vérifie que l'URL pattern. Si URL casse, juste 500px max text. Mineur.
- **Carte « Modèles »** : le bouton `<?= icon('edit', 18) ?>` sert de chevron pour ouvrir le `<details>` d'édition. Visuellement astucieux (une icône crayon = ouvrir édition), mais la sémantique `<summary>` reste « bouton toggle ». Un lecteur d'écran annonce « résumé, bouton » sans rapport avec l'édition. OK fonctionnel, mais `aria-label` dédié possible.
- **Bouton « Dupliquer »** imbriqué dans `<summary>` avec `event.stopPropagation()` → un clic sur le bouton ne doit pas déplier. Fragile : si JS bloqué, le clic déplie ET submit ? À vérifier. (Submit fonctionne sans JS car c'est un form ; mais le `<details>` natif s'ouvre quand même. Conséquence bénigne.)
- **Route `POST /fermeture/ajouter`** (single) définie côté backend mais l'UI n'expose que le flux lot (`/fermeture/ajouter-lot`) — route morte ? À vérifier et supprimer si c'est le cas.
- **Carte « Fermetures » — flash succès** : affiché via query string (`?ajoutees=N`) → persiste si on recharge la page. Pas très grave (message contextuel), mais à nettoyer en cliquant sur tout autre lien. OK en pratique.
- **Toolbar « Tout sélectionner »** pour les fermetures : le texte change « sélectionner / désélectionner » selon l'état indéterminé — bien. Mais sur mobile, la toolbar est-elle sticky ? À vérifier CSS (peut disparaître au scroll d'une longue liste, forçant un retour en haut pour décocher).

### `/licence` (`app/views/licence.php`)

**OK**
- Contenu statique propre, liens externes avec `rel="noopener"` et `target="_blank"`.
- Retour en bas de page vers `/`.

**À optimiser**
- Rien. Page figée, RAS.

---

## 2. Par action (flux utilisateur)

### S'inscrire à un créneau
- **Drawer s'ouvre** (click sur `.creneau-link`, `data-drawer` → htmx.ajax).
- **Focus sur prénom** (cookie `prenom` pré-remplit si déjà venu).
- **Submit natif** → 303 → full reload `/mois/YYYY-MM#jour-X`.
- **Friction** : perte du drawer, pas de feedback visuel « inscription OK ».
- **Optimisation** : `hx-post` + re-render du `#drawer-body` avec le formulaire vidé et la liste des inscrit·es mise à jour. Ou a minima un flash CSS (animation 1 s) sur la ligne après scroll.

### Ajouter un·e référent·e
- Idem, dans `<details>` replié.
- **Optimisation prioritaire** : ouvrir le `<details>` par défaut quand la liste est vide (attribut `open` conditionnel, 0 JS).
- **Micro-friction** : pré-remplir `heure_debut` avec l'heure du créneau est bien, mais si la personne arrive 30 min après, elle doit modifier. Pas urgent.

### Retirer un·e référent·e / un·e inscrit·e
- Bouton icône `×` + `confirm()` avec le nom.
- POST → redirect vers `/mois/...#jour-X`.
- **Friction** : même full reload qu'au-dessus. Même optimisation.

### Modifier un créneau
- `<details>` d'édition en bas du drawer.
- Formulaire complet : labels, horaires, capacité, note.
- **OK** côté validation serveur (range capacité, longueur note, horaires cohérents).
- **Optimisation** : pas de bouton « Annuler » explicite. Refermer le `<details>` = cliquer sur le summary. Découvrabilité faible mais acceptable.

### Ajouter un lot de fermetures (multi-date)
- Flux « ajouter à la liste puis déclarer ».
- **OK** : anti-doublon côté client (flash), Enter = ajouter, vider d'un clic.
- **Optimisation** : le compteur du bouton submit change bien (« Déclarer les 5 fermetures »), bon signal.

### Importer .ics
- Parser local, preview avec cases à cocher, dates passées décochées par défaut.
- **OK** : parse minimal mais robuste (déplie les continuations RFC 5545).
- **Optimisation** : si le fichier est énorme (>1000 events), pas de virtualisation — la liste devient lourde. Très edge case.

### Supprimer fermetures en lot
- Master-check + Maj+clic pour plages + confirm global.
- **OK** : toolbar state machine bien gérée.
- **Optimisation** : si liste très longue + toolbar non sticky, décocher oblige à remonter. CSS à vérifier.

### Dupliquer un modèle
- Bouton icône dans `<summary>` → POST → redirect vers `#modele-{nouveau}`.
- **OK** : `event.stopPropagation()` empêche l'ouverture du `<details>`.
- **Optimisation** : pas de confirm → un double-tap accidentel crée un doublon silencieux. Acceptable (moins dangereux qu'un delete), mais un toast « modèle #42 dupliqué » serait plus clair que juste le scroll.

### Charger la semaine type
- Visible seulement si modèles vides.
- Si on re-submit alors que modèles > 0, redirect silencieux sans flash. Conditions de course très marginales.

---

## 3. Points transversaux

### Cohérence des retours après POST
- Tous les POST redirigent via 303 + fragment. Uniforme, bien.
- **Mais** : pas de flash uniformisé. Certaines actions (import lot) affichent un flash via query string ; d'autres (inscription, suppression référent·e) ne montrent rien. Pattern incohérent. À uniformiser si on ajoute un système de flash (cookie flash éphémère, ou session).

### Gestion des erreurs 400/404/409
- Toutes les routes font `http_response_code(4xx); echo 'msg';` → page vierge avec texte brut.
- Sur submit natif : expérience dégradée. Pas bloquant mais cheap.
- Optimisation globale : une fonction `erreur400(string $msg)` qui rend un template minimal (layout.php + message + bouton retour), réutilisable partout.

### Confirmations natives
- `confirm('…')` partout. Accessible, fonctionne sans JS côté backend (le serveur accepte le POST indépendamment). OK.
- Limite : non-stylé, moche sur desktop moderne, non personnalisable. Acceptable pour un outil asso.

### Accessibilité
- Déjà sérieux : `aria-label`, `aria-current`, `aria-hidden` sur icônes, `min-height: 44px`.
- **À noter** : `aria-label="<?= $nb ?> inscrit·e"` sur `.c-compteur` — le `·` (point médian) est parfois lu comme « point milieu » par certains screen readers. Envisager `aria-label="X personnes inscrites"` (forme plurielle simple).

### Responsive
- Screenshots existent en mobile et desktop (cf. `/tmp/pw-audit/`).
- Je n'ai pas pu les visualiser (limite de taille d'image), donc je ne peux pas valider visuellement les breakpoints. À compléter dans une passe dédiée.

### Cookies
- `prenom` : HttpOnly, 1 an, SameSite=Lax. Bien côté sécu.
- Inconvénient : tablette partagée = dernier nom reste. Pas de déconnexion évidente. Acceptable pour le contexte asso.

### Polling htmx + état drawer
- Polling sur `#liste-jours` toutes les 30 s.
- **Risque** : si l'utilisateur a un drawer ouvert et qu'un swap arrive, le drawer reste mais la ligne dessous change. OK (drawer est au-dessus via `<dialog>`).
- Inversement : le drawer ne se met **pas** à jour quand la DB change. Si 3 personnes s'inscrivent en même temps via leurs téléphones, chacune voit sa propre inscription + les précédentes (rechargement), mais pas les simultanées. Acceptable, détectable au refresh drawer.

### Routes backend non exposées dans l'UI
- `POST /jour/nouveau` : pas de formulaire visible.
- `POST /fermeture/ajouter` (single) : remplacé par le flux lot, à vérifier s'il est appelé depuis ailleurs.
- Nettoyage possible : supprimer les routes mortes (réduit la surface d'attaque).

### CSS et assets
- 1 seul fichier `app.css` (1534 lignes). Pas de split, pas de build — volontaire et assumé, bon choix pour le contexte.
- Pas de minification visible — OK pour petit trafic asso.

---

## 4. Suggestions priorisées

### Priorité 1 — à gain utilisateur direct
1. **Drawer qui se met à jour in-place** après inscription/désinscription (htmx `hx-post` + re-swap). Supprime le full reload pénible.
2. **Ouvrir par défaut le `<details>` « Ajouter un·e référent·e »** quand la liste est vide. Attribut `open` conditionnel, 0 JS.
3. **Compteur « personnes à la salle »** intègre les référent·es (todo #1 déjà identifié).

### Priorité 2 — cohérence / ménage
4. **Supprimer ou exposer** les routes mortes (`/jour/nouveau`, `/fermeture/ajouter` single).
5. **Aligner le hint « 22h30 » avec le format attendu par `<input type="time">`** (soit `22:30`, soit pré-remplissage d'un clic).
6. **Passer le polling à 60 s** (ou 90 s) pour diviser par 2-3 les requêtes idle.

### Priorité 3 — petits polissages
7. **Inclure la date dans le `confirm()` de suppression de créneau** (« du 25 avril ? »).
8. **Flash unifié** après toutes les actions de fond (cookie flash éphémère).
9. **Template d'erreur minimal** pour les 400/404 au lieu du texte brut.

### Hors scope optimisation (= ce sont des ajouts, pas des optimisations)
- Système d'auth/permissions.
- Toast notifications.
- WYSIWYG moderne (tiptap etc.) en remplacement de `execCommand`.
- Virtualisation de longues listes.

---

## 5. Ce qui ne mérite PAS d'être touché

Pour éviter la tentation de refondre :
- La navigation mois ← → (déjà bien).
- Le rendu `<details>` natif pour cards réglages (simple, a11y gratuite).
- Le polling htmx lui-même (pattern propre, ETag bien géré).
- Le parser .ics (minimal, robuste, documenté).
- La structure `_ligne.php` / `_detail.php` (bonne séparation).
- Les icônes SVG inline dans `helpers.php` (zéro fetch, zéro cache).
- L'absence d'auth (c'est une feature, pas un oubli — cf. contexte asso).

---

*Audit par observation uniquement. Aucune modification appliquée.
Les screenshots produits par le script playwright sont dans `/tmp/pw-audit/`
et n'ont pas pu être visualisés dans cette passe (limite de taille d'image).*
