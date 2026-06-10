<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Note multi-lignes du créneau : un <textarea> soumet du CRLF. La route
// /jour/{id}/update doit normaliser en LF avant stockage (comptage de
// longueur exact, rendu pre-line propre). On vérifie le round-trip.

function runNoteMultiligne(): void
{
    resetEtat();
    $pdo = dbConnect();

    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-08-13', '18:00', '22:30', 15)"
    );
    $id = (int)$pdo->lastInsertId();

    $cookieCsrf = bin2hex(random_bytes(16));
    $tokens = csrfTokens($cookieCsrf);
    $r = http(
        'POST',
        "/jour/$id/update",
        array_merge([
            'heure_debut' => '18:00',
            'heure_fin'   => '22:30',
            'capacite'    => '15',
            'note'        => "Viens avec :\r\n- ta licence\r\n- ton certificat",
        ], $tokens),
        ['csrf_session' => $cookieCsrf]
    );
    ok($r['code'] === 303, "POST /jour/$id/update note CRLF → 303");

    $stmt = $pdo->prepare('SELECT note FROM jours WHERE id = ?');
    $stmt->execute([$id]);
    $note = (string)$stmt->fetchColumn();

    ok(!str_contains($note, "\r"), 'Note stockée sans CR (CRLF normalisé)');
    ok(substr_count($note, "\n") === 2, 'Note stockée avec 2 sauts LF préservés');
    ok(
        $note === "Viens avec :\n- ta licence\n- ton certificat",
        'Note stockée exactement en LF'
    );

    unset($pdo, $stmt);
    $clean = dbConnect();
    $clean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $clean->prepare('DELETE FROM jours WHERE id = ?')->execute([$id]);
}
