-- Schéma complet, consolidation des migrations 001 → 010.
-- Une seule migration à appliquer sur installation neuve.
-- Idempotente (IF NOT EXISTS / OR IGNORE) : peut être rejouée sans dommage.

CREATE TABLE IF NOT EXISTS jours (
  id           INTEGER PRIMARY KEY,
  date         TEXT    NOT NULL,                 -- 'YYYY-MM-DD'
  heure_debut  TEXT    NOT NULL,                 -- 'HH:MM'
  heure_fin    TEXT    NOT NULL,                 -- 'HH:MM'
  capacite     INTEGER NOT NULL DEFAULT 15,
  note         TEXT,
  UNIQUE(date, heure_debut)
);
CREATE INDEX IF NOT EXISTS idx_jours_date ON jours(date);

-- 1 à 2 référentes par jour, chacune avec sa propre plage horaire
-- (reproduit "Claire 18-20h / Sophie 20-22h30" du Google Sheet).
-- heure_fin est optionnelle : une référente peut déclarer "j'arrive à 18h"
-- sans s'engager sur une heure de sortie (pas de pression).
CREATE TABLE IF NOT EXISTS referentes (
  id           INTEGER PRIMARY KEY,
  jour_id      INTEGER NOT NULL REFERENCES jours(id) ON DELETE CASCADE,
  nom          TEXT    NOT NULL,
  heure_debut  TEXT    NOT NULL,
  heure_fin    TEXT
);
CREATE INDEX IF NOT EXISTS idx_referentes_jour ON referentes(jour_id);

CREATE TABLE IF NOT EXISTS inscriptions (
  id           INTEGER PRIMARY KEY,
  jour_id      INTEGER NOT NULL REFERENCES jours(id) ON DELETE CASCADE,
  nom          TEXT    NOT NULL,
  est_voisine  INTEGER NOT NULL DEFAULT 0,
  note         TEXT
);
CREATE INDEX IF NOT EXISTS idx_inscriptions_jour ON inscriptions(jour_id);

-- Modèles récurrents utilisés par la génération automatique du mois.
CREATE TABLE IF NOT EXISTS modeles (
  id            INTEGER PRIMARY KEY,
  jour_semaine  INTEGER NOT NULL,                -- 1=lundi … 7=dimanche (ISO)
  heure_debut   TEXT    NOT NULL,
  heure_fin     TEXT    NOT NULL,
  capacite      INTEGER NOT NULL DEFAULT 15,
  note_defaut   TEXT,
  active        INTEGER NOT NULL DEFAULT 1
);

-- Étiquettes libres, renommables/recoloriables depuis /reglages.
-- Remplacent l'ancien enum etat + flags figés (caf, parents_enfants, ouvert_voisines).
CREATE TABLE IF NOT EXISTS labels (
  id                   INTEGER PRIMARY KEY,
  nom                  TEXT    NOT NULL UNIQUE,
  couleur              TEXT    NOT NULL DEFAULT '#90a4ae',
  ordre                INTEGER NOT NULL DEFAULT 0,
  bloque_inscriptions  INTEGER NOT NULL DEFAULT 0,
  ouvre_voisines       INTEGER NOT NULL DEFAULT 0,
  sans_referent        INTEGER NOT NULL DEFAULT 0   -- dispense de référent·e (AG, événement)
);

CREATE TABLE IF NOT EXISTS jour_label (
  jour_id  INTEGER NOT NULL REFERENCES jours(id)  ON DELETE CASCADE,
  label_id INTEGER NOT NULL REFERENCES labels(id) ON DELETE CASCADE,
  PRIMARY KEY (jour_id, label_id)
);
CREATE INDEX IF NOT EXISTS idx_jour_label_jour  ON jour_label(jour_id);
CREATE INDEX IF NOT EXISTS idx_jour_label_label ON jour_label(label_id);

CREATE TABLE IF NOT EXISTS modele_label (
  modele_id INTEGER NOT NULL REFERENCES modeles(id) ON DELETE CASCADE,
  label_id  INTEGER NOT NULL REFERENCES labels(id)  ON DELETE CASCADE,
  PRIMARY KEY (modele_id, label_id)
);
CREATE INDEX IF NOT EXISTS idx_modele_label_modele ON modele_label(modele_id);
CREATE INDEX IF NOT EXISTS idx_modele_label_label  ON modele_label(label_id);

-- Journées de fermeture du gymnase (pas de créneau, juste un repère visuel).
CREATE TABLE IF NOT EXISTS fermetures (
  id   INTEGER PRIMARY KEY,
  date TEXT    NOT NULL UNIQUE,                  -- 'YYYY-MM-DD'
  note TEXT                                      -- ex. "Travaux", "1er mai"
);
CREATE INDEX IF NOT EXISTS idx_fermetures_date ON fermetures(date);

-- Réglages clé/valeur (nom asso, URL logo, HTML bandeau, etc.).
CREATE TABLE IF NOT EXISTS settings (
  cle    TEXT PRIMARY KEY,
  valeur TEXT
);

-- Labels par défaut fournis avec l'app (renommables/supprimables ensuite).
INSERT OR IGNORE INTO labels (nom, couleur, bloque_inscriptions, ouvre_voisines) VALUES
  ('CAF',             '#ff8f00', 0, 0),
  ('Parents-enfants', '#7e57c2', 0, 0),
  ('Salle fermée',    '#b71c1c', 1, 0),
  ('Séance spéciale', '#0e5a7e', 1, 0),
  ('Ouvert aux voisin·es', '#0d47a1', 0, 1);
