<?php
// Seed des modèles de créneaux récurrents — table "Paramètres" du Sheet.
declare(strict_types=1);

$appDir = dirname(__DIR__) . '/app';
require $appDir . '/config.php';
require $appDir . '/src/helpers.php';
require $appDir . '/src/Database.php';
require $appDir . '/src/LabelRepo.php';

$pdo = Database::connect(DB_PATH);
$pdo->query('DELETE FROM modeles');

// Ids des labels seedés par la migration init.
$labelId = [];
foreach ($pdo->query("SELECT id, nom FROM labels") as $r) {
    $labelId[$r['nom']] = (int)$r['id'];
}
$CAF     = $labelId['CAF']             ?? null;
$PE      = $labelId['Parents-enfants'] ?? null;
$VOISINS = $labelId['Ouvert aux voisin·es'] ?? null;

// [jour_semaine, hd, hf, capa, labels[], commentaire]
$modeles = [
    [1, '18:00', '22:30', 15, [$VOISINS],    null],  // lundi soir
    [2, '18:00', '22:30', 15, [$VOISINS],    null],  // mardi soir
    [4, '18:00', '22:30', 15, [$VOISINS],    null],  // jeudi soir
    [6, '12:00', '14:00', 15, [],            null],  // samedi midi
    [6, '16:00', '18:00', 15, [$CAF, $PE],   null],  // samedi aprem — CAF + parents-enfants
    [6, '18:00', '22:00', 15, [$CAF],        null],  // samedi soir — CAF seulement
    [7, '14:00', '18:00', 15, [$CAF, $PE],   null],  // dimanche aprem — CAF + parents-enfants
];

$ins = $pdo->prepare(
    'INSERT INTO modeles (jour_semaine, heure_debut, heure_fin, capacite, note_defaut, active)
     VALUES (?, ?, ?, ?, ?, 1)'
);
foreach ($modeles as [$js, $hd, $hf, $cap, $labels, $note]) {
    $ins->execute([$js, $hd, $hf, $cap, $note]);
    $mid = (int)$pdo->lastInsertId();
    LabelRepo::syncModele($pdo, $mid, array_values(array_filter($labels)));
}
printf("Modèles réinitialisés : %d entrées%s", count($modeles), PHP_EOL);
