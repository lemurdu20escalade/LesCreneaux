# Les Créneaux

Outil web de gestion de créneaux partagés pour associations et collectifs : un lieu, des créneaux, un·e référent·e qui ouvre, des inscrit·es libres.

Pas de compte utilisateur, pas de tracker, pas de JavaScript requis pour les actions de base. Tout fonctionne en fallback HTML pur ; htmx est un confort, pas une dépendance fonctionnelle.

> Utilisé en prod par l'association **Le MUR XXe** (escalade, Paris 20ᵉ), qui héberge aussi ce dépôt. Version actuelle : `0.1.0`.

[![CI](https://github.com/lemurdu20escalade/LesCreneaux/actions/workflows/ci.yml/badge.svg)](https://github.com/lemurdu20escalade/LesCreneaux/actions/workflows/ci.yml)

## Cas d'usage

- Salle d'escalade : créneaux du soir avec référent·e (qui « ouvre »), inscriptions libres jusqu'à capacité
- AMAP : permanences de distribution avec bénévoles identifié·es
- Atelier partagé / fablab : réservation de plages machine sans créer de compte
- Salle de répète, tiers-lieu : planning hebdomadaire récurrent avec fermetures ponctuelles

## Stack

- PHP 8.2+, PDO SQLite en mode WAL, migrations idempotentes
- htmx 2 auto-hébergé (~50 Ko) — polling 60 s + drawer détail par jour
- CSS custom Material Design, zéro framework
- Zéro build, pas de Composer obligatoire en prod

## Install

```sh
git clone https://github.com/lemurdu20escalade/LesCreneaux.git
cd LesCreneaux
cp app/config.php.example app/config.php
# Éditer app/config.php (voir section Config)
mkdir -p data && chmod 750 data/
```

Pointer le `DocumentRoot` de l'hôte virtuel sur `www/`. La base SQLite et les tables se créent à la première visite.

## Config

Copier `app/config.php.example` → `app/config.php` et renseigner :

| Constante              | Rôle                                                              |
|------------------------|-------------------------------------------------------------------|
| `BASE_URL`             | URL publique sans `/` final                                       |
| `DATA_DIR`             | Chemin absolu vers le dossier runtime (hors docroot, writable)    |
| `DB_PATH`              | Chemin du fichier SQLite (dérivé de `DATA_DIR` par défaut)        |
| `SECRET_CSRF`          | Secret HMAC 64 chars hex — **obligatoire**                        |
| `MAIL_FROM`            | Expéditeur des mails (notifications, phase ultérieure)            |
| `SSE_ENABLED`          | Flux SSE en plus du polling 60 s (off par défaut)                 |
| `DISCORD_WEBHOOK_URL`  | Webhook Discord pour les alertes réglages (vide = désactivé)      |
| `ASSO_NOM_DEFAUT`      | Nom affiché tant que l'admin n'a rien réglé via `/reglages`       |
| `ASSO_LOGO_URL_DEFAUT` | URL publique du logo (vide = pas de logo)                         |

Générer `SECRET_CSRF` :

```sh
php -r "echo bin2hex(random_bytes(32));"
```

Une fois installé, ouvrir `/reglages` pour configurer l'identité de l'asso, le bandeau d'accueil, les créneaux récurrents et les fermetures.

## Dev local

```sh
php -S localhost:8080 -t www www/_router.php
```

`_router.php` laisse le serveur builtin servir les fichiers statiques (`/assets/…`) et renvoie tout le reste vers `index.php`.

## Prod

Voir [EXPLOITATION.md](./EXPLOITATION.md) pour l'installation Apache, les sauvegardes, la restauration et le dépannage.

## Licence

[AGPL v3](./LICENSE) — toute réutilisation hébergée doit publier ses modifications.

## Contribuer

Voir [CONTRIBUTING.md](./CONTRIBUTING.md).
