<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Notes de créneau : les URLs http(s) deviennent des liens cliquables dans
// le drawer (lienAuto). On vérifie le rendu ET l'innocuité : pas de lien
// javascript:, HTML de la note échappé (la note est du texte libre public).

function runNoteLiens(): void
{
    $pdo = dbConnect();
    $pdo->prepare('DELETE FROM jours WHERE date = ?')->execute(['2027-01-20']);
    $pdo->prepare(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite, note)"
        . " VALUES ('2027-01-20', '10:00', '12:00', 15, ?)"
    )->execute(['Inscription : https://exemple.fr/feuille?gid=1#x']);
    $id = (int)$pdo->lastInsertId();

    $r = http('GET', "/jour/$id");
    ok($r['code'] === 200, "GET /jour/$id (drawer) → 200");
    ok(
        str_contains(
            $r['body'],
            '<a href="https://exemple.fr/feuille?gid=1#x" target="_blank" rel="noopener noreferrer nofollow">'
        ),
        'URL de note rendue en lien cliquable'
    );

    // Note piégée : aucun lien javascript:, aucun HTML interprété.
    $pdo->prepare('UPDATE jours SET note = ? WHERE id = ?')
        ->execute(['javascript:alert(1) puis <b>gras</b>', $id]);
    $r = http('GET', "/jour/$id");
    ok(!preg_match('~<a\b[^>]*javascript:~i', $r['body']), 'Aucun lien javascript:');
    ok(!str_contains($r['body'], '<b>gras</b>'), 'HTML de la note échappé (pas d’injection)');

    $clean = dbConnect();
    $clean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $clean->prepare('DELETE FROM jours WHERE id = ?')->execute([$id]);
}
