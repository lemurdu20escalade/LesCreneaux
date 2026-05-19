<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Accès SQLite pour le harnais. La base est créée par le serveur builtin
// au premier hit ; ces helpers n'ouvrent jamais avant.

function dbConnect(): PDO
{
    global $dbPath;

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Purge l'état entre deux scénarios : inscriptions de test + fichier
// rate-limit.json. Ne supprime PAS les jours créés par les tests — chaque
// scénario en est responsable parce que les dates utilisées varient.
function resetEtat(): void
{
    global $ratePath;

    $pdo = dbConnect();
    $pdo->exec("DELETE FROM inscriptions WHERE nom LIKE 'Test%'");

    if (file_exists($ratePath)) {
        unlink($ratePath);
    }
}
