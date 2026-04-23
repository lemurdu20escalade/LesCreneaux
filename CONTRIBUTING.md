# Contribuer

Contributions bienvenues : issues, pull requests, corrections de typo. Projet volontairement minimaliste — garder cette orientation.

## Environnement

- PHP 8.2+ (voir `.php-version`)
- SQLite 3.35+ (pour `INSERT … RETURNING`)
- Pas de Composer requis, pas de build step

## Lancer en local

```sh
git clone https://github.com/lemurdu20escalade/LesCreneaux.git
cd LesCreneaux
cp app/config.php.example app/config.php
php -r "echo bin2hex(random_bytes(32));"      # à copier dans SECRET_CSRF
php -S localhost:8080 -t www www/_router.php
```

La base SQLite se crée au premier accès. Pour des données de démo :

```sh
php scripts/seed-modeles.php
php scripts/seed-avril-2026.php
```

## Style

- `declare(strict_types=1);` en tête de chaque fichier PHP
- Indentation 4 espaces (voir `.editorconfig`)
- Pas de framework, garder les dépendances à zéro
- Requêtes SQL préparées uniquement
- Les vues dans `app/views/` restent des templates PHP simples

## Before you commit

```sh
find app www scripts -name '*.php' -print0 | xargs -0 -n1 php -l
sqlite3 /tmp/test.sqlite < app/migrations/001_init.sql
```

La CI GitHub Actions fait la même chose (`.github/workflows/ci.yml`).

## Scope

Le projet sert des associations qui partagent un lieu et doit rester simple à auto-héberger. Refus par défaut des propositions qui ajoutent :

- un framework (Laravel, Symfony, etc.)
- une dépendance JS côté front au-delà d'htmx
- un build step (webpack, vite, npm, etc.)
- un service externe obligatoire (base distante, tracker, auth tierce)

## Signaler un bug de sécurité

Ouvrir un advisory privé via l'onglet Security du dépôt GitHub, ou contacter les mainteneur·ices de l'asso qui héberge ce fork plutôt que d'ouvrir une issue publique. Réponse visée sous 1 semaine.

## Licence des contributions

Le projet est sous [AGPL v3](./LICENSE). Toute contribution (PR, patch, issue avec code joint) est soumise à cette même licence.

Rappel AGPL : si tu forkes et héberges ta version modifiée (sur ton serveur, en SaaS, en mutualisé…), tu **dois** rendre tes modifications publiques sous AGPL v3. Le simple fait de les faire tourner côté serveur déclenche l'obligation, pas besoin de les redistribuer en binaire.
