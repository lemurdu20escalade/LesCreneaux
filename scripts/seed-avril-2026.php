<?php
// Jeu de données de démo pour avril 2026 — prénoms fictifs.
// Structure calquée sur le Google Sheet d'origine mais anonymisée.
declare(strict_types=1);

$appDir = dirname(__DIR__) . '/app';
require $appDir . '/config.php';
require $appDir . '/src/helpers.php';
require $appDir . '/src/Database.php';
require $appDir . '/src/LabelRepo.php';

$pdo = Database::connect(DB_PATH);
$pdo->query("DELETE FROM jours WHERE substr(date,1,7) = '2026-04'");

// Labels seedés par la migration init : on récupère leurs ids pour les attacher.
$labelId = [];
foreach ($pdo->query("SELECT id, nom FROM labels") as $r) {
    $labelId[$r['nom']] = (int)$r['id'];
}
$CAF     = $labelId['CAF']             ?? null;
$PE      = $labelId['Parents-enfants'] ?? null;
$FERME   = $labelId['Salle fermée']    ?? null;
$SPE     = $labelId['Séance spéciale'] ?? null;
$VOISINS = $labelId['Ouvert aux voisin·es'] ?? null;

// [date, hd, hf, cap, note_jour, labels[], referentes[[nom,hd,hf], …], inscriptions[[nom, voisine, note], …]]
$data = [
    ['2026-04-02', '18:00', '22:30', 15, null, [$VOISINS],
        [['Alice', '18:00', '20:00']], []
    ],
    ['2026-04-04', '12:00', '14:00', 15, null, [],
        [['Bob', '12:00', '14:00']],
        [['Camille', 0, null], ['Diane', 0, null], ['Eva', 0, null]]
    ],
    ['2026-04-04', '16:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE],
        [['Farid', '16:00', '18:00']],
        [['Gaspard', 0, '6 ans']]
    ],
    ['2026-04-04', '18:00', '22:00', 15, 'Partagé CAF', [$CAF], [], []],
    ['2026-04-05', '14:00', '18:00', 15, null, [$FERME], [], []],
    ['2026-04-06', '18:00', '22:30', 15, null, [$FERME], [], []],
    ['2026-04-07', '18:00', '22:30', 15, null, [$VOISINS],
        [
            ['Hugo',   '18:30', '22:30'],
            ['Inès',   '18:00', '22:30'],
            ['Jean',   '18:30', '22:30'],
            ['Karim',  '19:00', '22:30'],
            ['Louise', '18:00', '22:30'],
        ],
        [
            ['Marc', 0, 'EPM'], ['Nina', 0, null], ['Olga', 0, null],
            ['Paul', 0, null], ['Quentin', 0, '+1'], ['Rania', 0, null], ['Sam', 0, null],
        ]
    ],
    ['2026-04-09', '18:00', '22:30', 15, null, [$VOISINS],
        [['Alice', '18:00', '20:00'], ['Tomi', '19:30', '22:30']],
        [['Uma', 0, null], ['Victor', 0, null], ['Wren', 0, null], ['Xavier', 0, null], ['Yann', 0, null], ['Zoé', 0, null]]
    ],
    ['2026-04-11', '12:00', '14:00', 15, null, [],
        [['Bob', '12:00', '14:00'], ['Camille', '12:00', '14:00']],
        [['Diane', 0, null], ['Eva', 0, null], ['Farid', 0, null], ['Gaspard', 0, null]]
    ],
    ['2026-04-11', '16:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE], [], []],
    ['2026-04-11', '18:00', '22:00', 15, 'Partagé CAF', [$CAF],
        [['Bob', '18:00', '22:00']], []
    ],
    ['2026-04-12', '14:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE], [], []],
    ['2026-04-13', '18:00', '22:30', 15, null, [$VOISINS],
        [['Tomi', '19:30', '22:30']],
        [['Hugo', 0, null]]
    ],
    ['2026-04-14', '18:00', '22:30', 15, null, [$VOISINS],
        [['Hugo', '18:00', '22:30'], ['Inès', '19:00', '22:30'], ['Jean', '19:30', '22:30']],
        [
            ['Karim', 0, null], ['Louise', 0, null], ['Marc', 0, '18h'],
            ['Nina', 0, '20h'], ['Olga', 0, null], ['Paul', 0, '20h30'],
        ]
    ],
    ['2026-04-16', '18:00', '22:30', 15, null, [$VOISINS],
        [['Quentin', '19:15', '22:30'], ['Rania', '19:00', '22:30']],
        [['Sam', 0, null], ['Tomi', 0, null]]
    ],
    ['2026-04-18', '12:00', '14:00', 15, null, [],
        [['Uma', '12:00', '14:00'], ['Victor', '12:00', '14:00'], ['Wren', '12:00', '14:00']],
        [['Xavier', 0, null], ['Yann', 0, null]]
    ],
    ['2026-04-18', '16:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE],
        [['Zoé', '16:00', '18:00'], ['Alice', '16:00', '18:00']], []
    ],
    ['2026-04-18', '18:00', '22:00', 15, 'Partagé CAF', [$CAF], [], []],
    ['2026-04-19', '14:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE],
        [['Bob', '14:00', '18:00'], ['Camille', '15:30', '18:00']], []
    ],
    ['2026-04-20', '18:00', '22:30', 15, null, [$VOISINS],
        [['Diane', '19:00', '22:30'], ['Eva', '19:00', '22:30'], ['Farid', '18:00', '22:30']],
        [
            ['Gaspard', 0, null], ['Hugo', 0, null], ['Inès', 0, null], ['Jean', 0, null],
            ['Karim', 0, null], ['Louise', 0, null], ['Marc', 0, null], ['Nina', 0, null],
            ['Olga', 0, null], ['Paul', 0, null], ['Quentin', 0, 'FLM'], ['Rania', 0, 'FLM'],
        ]
    ],
    ['2026-04-21', '18:00', '22:30', 15, null, [$VOISINS],
        [['Sam', '19:00', '22:30'], ['Tomi', '19:30', '22:30'], ['Uma', '20:00', '22:30']],
        [['Victor', 0, null], ['Wren', 0, null], ['Xavier', 0, null], ['Yann', 0, null], ['Zoé', 0, 'ASGB'], ['Alice', 0, 'ASGB']]
    ],
    ['2026-04-23', '18:00', '22:30', 15, null, [],
        [], [['Bob', 0, 'seul-tout, 19h30']]
    ],
    ['2026-04-25', '12:00', '14:00', 15, null, [], [], []],
    ['2026-04-25', '16:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE], [], []],
    ['2026-04-25', '18:00', '22:00', 15, 'Partagé CAF', [$CAF], [], []],
    ['2026-04-26', '14:00', '18:00', 15, 'Parents-enfants partagé CAF', [$CAF, $PE], [], []],
    ['2026-04-27', '19:00', '22:00', 15, 'Séance progression vol, salle réservée', [$SPE], [], []],
    ['2026-04-28', '18:00', '22:30', 15, null, [$VOISINS], [], []],
    ['2026-04-30', '18:00', '22:30', 15, null, [$FERME], [], []],
];

$insJour = $pdo->prepare(
    'INSERT INTO jours (date, heure_debut, heure_fin, capacite, note)
     VALUES (?, ?, ?, ?, ?)'
);
$insRef = $pdo->prepare('INSERT INTO referentes (jour_id, nom, heure_debut, heure_fin) VALUES (?, ?, ?, ?)');
$insIns = $pdo->prepare('INSERT INTO inscriptions (jour_id, nom, est_voisine, note) VALUES (?, ?, ?, ?)');

$pdo->query('BEGIN IMMEDIATE');
$nbJ = $nbR = $nbI = 0;
foreach ($data as [$date, $hd, $hf, $cap, $note, $labels, $refs, $inscrits]) {
    $insJour->execute([$date, $hd, $hf, $cap, $note]);
    $jourId = (int)$pdo->lastInsertId();
    $nbJ++;
    LabelRepo::syncJour($pdo, $jourId, array_values(array_filter($labels)));
    foreach ($refs as [$nom, $rhd, $rhf]) {
        $insRef->execute([$jourId, $nom, $rhd, $rhf]);
        $nbR++;
    }
    foreach ($inscrits as [$nom, $estV, $noteIns]) {
        $insIns->execute([$jourId, $nom, $estV, $noteIns]);
        $nbI++;
    }
}
$pdo->query('COMMIT');
printf("Avril 2026 chargé : %d jours · %d référentes · %d inscriptions%s", $nbJ, $nbR, $nbI, PHP_EOL);
