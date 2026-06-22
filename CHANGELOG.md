# Changelog

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Ce projet suit [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Ajouté
- Notification de mise à jour : bandeau sur `/reglages` quand une release
  GitHub plus récente existe (vérification passive, cache 24 h, fail-open,
  désactivable via `MAJ_CHECK`). Aucune écriture sur le code.

## [0.1.0] — 2026-04-23

Première publication open source.

### Ajouté
- Front controller PHP avec routeur maison (`app/src/Router.php`).
- Connexion SQLite en mode WAL, migrations idempotentes (`app/migrations/001_init.sql`).
- Modèles de créneaux récurrents + génération automatique mois par mois.
- Inscription et désinscription via htmx (sans JS requis en fallback).
- Étiquettes libres renommables/recoloriables (remplacent les anciens enums figés).
- Fermetures du gymnase : ajout unitaire, import lot (ICS), suppression en lot, multi-sélection avec Maj+clic.
- Réglages : identité de l'asso, bandeau HTML, modèles, étiquettes, fermetures.
- Protection CSRF (HMAC double-submit + honeypot + délai minimum 2 s).
- Sanitizer HTML pour le bandeau (DOMDocument, liste blanche de tags).
- Surveillance des modifications de réglages avec alertes Discord optionnelles.
- ETag pour polling htmx 30 s (304 si la base n'a pas changé).
- Script de backup quotidien (`scripts/backup.sh`, purge > 90 jours).
- Configuration Apache (`.htaccess`) : CSP strict, cache assets, blocage SQLite.

### Sécurité
- Cookie CSRF `HttpOnly` + `SameSite=Strict` + `Secure` si HTTPS.
- Cookie `prenom` `HttpOnly` + `Secure` si HTTPS.
- Transactions atomiques (`Database::tx`) avec retry sur `SQLITE_BUSY`.
- Foreign keys activées (`PRAGMA foreign_keys = ON`).
