#!/bin/sh
# Backup quotidien de la base SQLite.
# À configurer dans le cron du mutualisé :
#   0 4 * * * /chemin/vers/LesCreneaux/scripts/backup.sh
#
# Sans dépendance : utilise sqlite3 (présent sur tous les mutualisés).
# Rétention : 90 jours.
#
# Compatibilité : détecte l'ancien nom grimpe.sqlite si creneaux.sqlite
# n'existe pas (pour les instances antérieures au renommage du projet).

set -eu

# Répertoire de l'app (résolu depuis l'emplacement du script).
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB="$APP_ROOT/data/creneaux.sqlite"
if [ ! -f "$DB" ] && [ -f "$APP_ROOT/data/grimpe.sqlite" ]; then
    DB="$APP_ROOT/data/grimpe.sqlite"
fi
BACKUPS="$APP_ROOT/data/backups"

if [ ! -f "$DB" ]; then
    echo "Base introuvable : $DB" >&2
    exit 1
fi

mkdir -p "$BACKUPS"
chmod 750 "$BACKUPS"

DATE="$(date +%F)"
OUT="$BACKUPS/creneaux-$DATE.sqlite"

# .backup garantit un dump cohérent même si WAL actif.
sqlite3 "$DB" ".backup '$OUT'"
gzip -f "$OUT"

# Purge > 90 jours.
find "$BACKUPS" \( -name 'creneaux-*.sqlite.gz' -o -name 'grimpe-*.sqlite.gz' \) \
     -type f -mtime +90 -delete

echo "OK : $OUT.gz"
