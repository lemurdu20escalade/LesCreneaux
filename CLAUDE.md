# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack et contraintes structurantes

- PHP 8.2+ pur, **zéro Composer en prod, zéro build step, zéro dépendance JS au-delà d'htmx auto-hébergé**.
- PDO SQLite en mode WAL, une seule migration consolidée et idempotente.
- Refus par défaut (cf. `CONTRIBUTING.md`) : framework (Laravel, Symfony…), bundler (vite, webpack, npm), service externe obligatoire, JS supplémentaire côté front.
- Tout doit garder un **fallback HTML pur** : htmx est un confort, jamais une dépendance fonctionnelle. Une route POST renvoie soit un fragment HX, soit un `redirect()` 303 selon `HX-Request`.

## Commandes

```sh
# Dev local — la base SQLite et les tables se créent à la première visite
cp app/config.php.example app/config.php   # éditer SECRET_CSRF (64 hex)
php -S localhost:8080 -t www www/_router.php

# Lint PHP (même check que la CI)
find app www scripts -name '*.php' -print0 | xargs -0 -n1 php -l

# Migration SQL appliquée à froid (CI fait pareil)
sqlite3 /tmp/test.sqlite < app/migrations/001_init.sql

# Tests d'intégration — harnais standalone (pas de PHPUnit, pas de Composer)
php tests/integration.php

# PHPStan niveau 6 (baseline absorbe l'existant)
composer require --dev phpstan/phpstan:^2.0   # une fois
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpstan analyse --generate-baseline   # à régénérer après chaque modif PHP

# Seeds (DESTRUCTIFS — ne pas lancer en prod)
php scripts/seed-modeles.php          # écrase tous les modèles récurrents
php scripts/seed-avril-2026.php       # écrase le mois d'avril 2026

# Backup (cron : 0 4 * * *)
scripts/backup.sh                     # data/backups/creneaux-YYYY-MM-DD.sqlite.gz, purge > 90j
```

Pas de commande pour lancer un seul test : `tests/integration.php` est un harnais maison qui orchestre les scénarios dans `tests/scenarios/0X-*.php`. Pour cibler un cas isolé, commenter les `run*()` non voulus dans `tests/integration.php`.

## Architecture

### Boot et front controller

`www/index.php` est le **seul** point d'entrée. Il :
1. Charge `app/config.php` — sauf si `APP_CONFIG_OVERRIDE` est défini (les tests injectent leur config jetable par cette variable d'env).
2. `require` toute la stack (`app/src/*.php`) — pas d'autoloader.
3. Pose les headers de sécurité (CSP avec `frame-ancestors 'none'`, `X-Frame-Options: DENY`, HSTS si HTTPS). **Source unique de la CSP** : ne pas la dupliquer dans `.htaccess`.
4. Démarre `ob_gzhandler` pour compresser les réponses HTML.
5. Pose le cookie CSRF avant tout output (`Csrf::cookie()`).
6. Construit `new Router()` et y enregistre toutes les routes en closures inline, puis dispatch.

En dev avec `php -S`, le `_router.php` (ou `index.php` quand `PHP_SAPI === 'cli-server'`) laisse le serveur builtin servir les fichiers statiques directement (`/assets/…`) et passe le reste à `index.php`.

### Couches

- `app/src/Router.php` — micro-routeur, pattern `/jour/{id}`, dispatch (méthode, regex) → handler.
- `app/src/*Repo.php` — un repo par table, requêtes préparées uniquement. Aucune logique métier ici, juste des SELECT/INSERT/UPDATE.
- `app/src/Database.php` — connexion singleton, `tx()` transactionnel avec retry sur `SQLITE_BUSY` (3 tentatives, backoff 10→50→250 ms), applique la migration unique et les `fixups()` idempotents à chaque connexion.
- `app/src/Csrf.php` — HMAC double-submit (cookie `csrf_session` + token signé `SECRET_CSRF`), champ `_ts` signé pour rejet > 2h ou < 2s, honeypot `website`. Cas spéciaux (login interactif) passent `['timing_min' => false]` à `verifierPostDetail()`.
- `app/src/RateLimit.php` — stockage **fichier JSON** dans `data/rate-limit.json` avec `flock()`, **fail-open** sur exception I/O (log + laisse passer). Compteurs séparés : inscription (10/IP/10 min), désinscription, erreurs CSRF, échecs login. Sur mutualisé NFS le `flock` advisory peut être incohérent — voir EXPLOITATION.md §7.
- `app/src/AdminAuth.php` — auth optionnelle. Si `ADMIN_PASSWORD_HASH` est défini dans `config.php`, les routes admin exigent `exigerConnexion()`. Sinon **tout est ouvert** (mode "asso de confiance" : un bandeau rouge sur `/reglages` avertit). Cookie signé inclut une empreinte du hash → changer le hash invalide toutes les sessions.
- `app/src/MoisGenerator.php` — `genererSiVide($pdo, 'YYYY-MM')` crée les jours du mois depuis les `modeles` actifs s'ils n'existent pas. Idempotent.
- `app/src/Surveillance.php` — alerte Discord optionnelle (`DISCORD_WEBHOOK_URL`) sur changements de réglages. POST différé après `flush()` du client.
- `app/src/HtmlSanitizer.php` — DOMDocument + whitelist de tags pour le bandeau HTML libre.
- `app/views/*.php` — templates PHP simples avec `ob_start()` → `$contenu` → `require layout.php`. Pas de moteur de templates. Variables passées par fermeture, échapper avec `e()` (helper global).

### Flux d'une requête typique

1. `GET /mois/2026-04` : `MoisGenerator::genererSiVide()` puis `Version::etag()` → `304` si polling htmx sans changement, sinon rendu de `views/mois.php` + `layout.php`.
2. `POST /jour/{id}/inscrire` : vérif CSRF → rate-limit (fail-open) → vérif jour existe → insert via `InscriptionRepo` → cookie `prenom` (1 an, HttpOnly) → si `HX-Request: true` rendu drawer via `rendreDrawer()`, sinon `redirect()` 303 vers `/mois/YYYY-MM#jour-{id}` avec flash.
3. Routes `/reglages` et toutes les routes admin (`/jour/{id}/update`, `/modele/*`, `/label/*`, `/fermeture/*`, `/settings/update`) commencent par `AdminAuth::exigerConnexion()` puis vérif CSRF.

### Données et migrations

- `data/` est hors docroot, `chmod 750`. Contient : `creneaux.sqlite` (ou `grimpe.sqlite` sur instances pré-v0.2, le code détecte les deux noms), `rate-limit.json`, `surveillance-state.json`, `backups/`.
- **Une seule migration** : `app/migrations/001_init.sql`. Idempotente (`CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE`). Pour rajouter du schéma : soit étendre cette migration si elle reste idempotente, soit créer `002_xxx.sql` + l'enregistrer dans `Database::migrate()`. SQLite ne supporte pas `ALTER COLUMN` : les corrections de contraintes passent par `Database::fixups()` (recréation de table conditionnée à un check `pragma_table_info`).
- Schéma : `jours` (1 ligne = un créneau daté), `referentes` (1-2 par jour, `heure_fin` nullable), `inscriptions`, `modeles` (récurrents `jour_semaine` 1-7 ISO), `labels` + `jour_label`/`modele_label` (M:N), `fermetures`, `settings` (KV).

### Tests d'intégration

`tests/integration.php` est **standalone** : pas de PHPUnit, pas de Composer.
- Crée `sys_get_temp_dir()/lescreneaux-tests-<pid>/` avec config jetable.
- Bind un port aléatoire via `socket_bind(…, 0)`, lance `php -S` en sous-processus, attend que `/licence` réponde.
- Exécute les scénarios dans l'ordre, imprime `N PASS / M FAIL`, exit 0 si tout passe.
- **Ne touche jamais à `data/`** : isolation totale via `APP_CONFIG_OVERRIDE`.
- Helpers réutilisables dans `tests/lib/` (`assert.php`, `http.php`, `db.php`, `server.php`).

### CI

`.github/workflows/ci.yml` matrice PHP 8.2/8.3/8.4, quatre jobs :
1. `lint` — `php -l` sur `app/`, `www/`, `scripts/` + idempotence de la migration.
2. `smoke` — démarre le serveur builtin, vérifie 303 sur `/`, 200 sur `/mois/2026-04`, `/reglages`, `/licence`, layout sur 404, headers de sécurité.
3. `integration` — `php tests/integration.php`.
4. `phpstan` — niveau 6, **non-bloquant** tant que la baseline n'est pas régénérée localement (cf. `topublic.md`).

## Conventions

- `declare(strict_types=1);` en tête de chaque fichier PHP.
- Indentation 4 espaces (`.editorconfig`).
- Identifiants en **français** (cf. `jour`, `referente`, `verifierPost`, `couleurReferente`). Conserver cette convention.
- Requêtes SQL **toujours préparées**. Pas de concaténation dans une SQL.
- Échappement HTML systématique via `e()` (helper global) dans toutes les vues.
- Une vue est un template PHP simple. Pas de moteur, pas d'helpers de rendu complexes.
- En-tête SPDX `// SPDX-License-Identifier: AGPL-3.0-or-later` sur les fichiers PHP versionnés.

## Pièges connus

- **CSRF + login interactif** : un humain avec password manager peut soumettre en < 2 s. `/admin/login` désactive `timing_min` (`Csrf::verifierPostDetail($_POST, ['timing_min' => false])`). Ne pas généraliser ce bypass aux autres routes.
- **Cookie `csrf_session`** : `SameSite=Strict`. Un POST qui arrive d'un onglet ouvert depuis longtemps peut prendre un 400 `EXPIRE` après 2 h — c'est voulu.
- **`flock()` sur NFS / mutualisé** : le rate-limit JSON peut devenir incohérent. Pas un bug du code, un bug de l'hébergement. Réimplémenter en SQLite si ça se produit (issue à ouvrir le jour où).
- **Proxy / Cloudflare** : `REMOTE_ADDR` devient l'IP du proxy, tout le rate-limit partage un quota. Avant de déployer derrière un proxy, ajouter une résolution `X-Forwarded-For` avec whitelist (pas encore implémentée).
- **Pas de migration NNN automatique** : si on ajoute `002_xxx.sql`, l'exploitant doit l'appliquer à la main (cf. `EXPLOITATION.md §6`).
- **`Database::connect()` est un singleton** : un seul PDO partagé par requête. Ne pas tenter d'ouvrir une seconde connexion sur la même base — `BEGIN IMMEDIATE` lockera.
- **htmx polling toutes les 60 s** sur `/mois/YYYY-MM` : utilise `ETag` via `Version::etag($pdo)`. Si on touche au schéma ou à la logique de hash, vérifier qu'on n'envoie pas une réponse complète à chaque tick.

## Hors-scope (refusé par défaut)

Ne pas proposer dans une PR :
- Ajout de Composer pour autre chose que les tools de dev (PHPStan).
- Ajout d'un framework, d'un ORM, d'un templating engine.
- Ajout de JS supplémentaire côté front (htmx couvre tout — drawer, polling, swap).
- Service externe obligatoire (auth tierce, base distante, tracker).
- Build step (vite, webpack, sass compiler, etc.).

Le projet est utilisé en prod par **Le MUR XXe** (asso d'escalade, Paris 20ᵉ). Sa raison d'être : rester **trivialement auto-hébergeable** par une asso non-tech sur un mutualisé.
