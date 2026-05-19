<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Rate-limit : 10 inscriptions / 10 min par IP, 30 désinscriptions / 30 min,
// compteur d'erreurs CSRF (alerte sans blocage), normalisation IPv4/IPv6 dans
// la clé du store rate-limit.json.

function runRateLimit(): void
{
    // --- Cas 1 : rate-limit inscription (bloquant à 10) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-01', '18:00', '22:30', 100)"
    );
    $idJour1 = (int)$pdo->lastInsertId();
    unset($pdo);

    $cookieInscr = bin2hex(random_bytes(16));

    for ($i = 1; $i <= 10; $i++) {
        $tokens = csrfTokens($cookieInscr);
        $r = http(
            'POST',
            "/jour/$idJour1/inscrire",
            array_merge(['nom' => "TestRL$i"], $tokens),
            ['csrf_session' => $cookieInscr]
        );
        ok($r['code'] === 303, "Rate-limit inscription — POST $i/10 → 303");
    }

    $tokens = csrfTokens($cookieInscr);
    $r = http(
        'POST',
        "/jour/$idJour1/inscrire",
        array_merge(['nom' => 'TestRL11'], $tokens),
        ['csrf_session' => $cookieInscr]
    );
    ok($r['code'] === 429, 'Rate-limit inscription — POST 11 → 429');

    $pdo  = dbConnect();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE nom LIKE 'TestRL%'");
    $stmt->execute();
    ok((int)$stmt->fetchColumn() === 10, 'Rate-limit inscription — 10 inscrits en DB (pas 11)');

    resetEtat();
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour1]);
    unset($pdoClean);

    // --- Cas 2 : rate-limit désinscription (bloquant à 30) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-02', '18:00', '22:30', 100)"
    );
    $idJour2 = (int)$pdo->lastInsertId();

    $inscrIds = [];
    for ($i = 1; $i <= 31; $i++) {
        $stmt = $pdo->prepare(
            "INSERT INTO inscriptions (jour_id, nom, est_voisine) VALUES (?, ?, 0)"
        );
        $stmt->execute([$idJour2, "TestDes$i"]);
        $inscrIds[] = (int)$pdo->lastInsertId();
    }
    unset($pdo, $stmt);

    $cookieDesinscr = bin2hex(random_bytes(16));

    for ($i = 0; $i < 30; $i++) {
        $tokens = csrfTokens($cookieDesinscr);
        $r = http(
            'POST',
            "/jour/$idJour2/desinscrire",
            array_merge(['inscription_id' => $inscrIds[$i]], $tokens),
            ['csrf_session' => $cookieDesinscr]
        );
        ok($r['code'] === 303, 'Rate-limit désinscription — POST ' . ($i + 1) . '/30 → 303');
    }

    $tokens = csrfTokens($cookieDesinscr);
    $r = http(
        'POST',
        "/jour/$idJour2/desinscrire",
        array_merge(['inscription_id' => $inscrIds[30]], $tokens),
        ['csrf_session' => $cookieDesinscr]
    );
    ok($r['code'] === 429, 'Rate-limit désinscription — POST 31 → 429');

    $pdo  = dbConnect();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE nom LIKE 'TestDes%'");
    $stmt->execute();
    ok((int)$stmt->fetchColumn() === 1, 'Rate-limit désinscription — 1 inscription restante en DB');

    resetEtat();
    unset($pdo, $stmt);
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour2]);
    unset($pdoClean);

    // --- Cas 3 : compteur erreurs CSRF (alerte, pas blocage) ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-03', '18:00', '22:30', 100)"
    );
    $idJour3 = (int)$pdo->lastInsertId();
    unset($pdo);

    for ($i = 1; $i <= 51; $i++) {
        $r = http(
            'POST',
            "/jour/$idJour3/inscrire",
            ['_csrf' => 'bad', '_ts' => '0', '_ts_sig' => 'bad', 'website' => '', 'nom' => 'Test'],
            []
        );
        ok($r['code'] === 400, "Rate-limit CSRF — POST $i/51 → 400 (pas bloqué)");
    }

    global $ratePath;
    $rateData = file_exists($ratePath)
        ? json_decode((string)file_get_contents($ratePath), true)
        : null;

    $cleCsrfTrouvee = false;
    $nbTimestamps   = 0;
    if (is_array($rateData)) {
        foreach (array_keys($rateData) as $cle) {
            if (str_starts_with((string)$cle, 'csrf:')) {
                $cleCsrfTrouvee = true;
                $nbTimestamps   = count($rateData[$cle]);
                break;
            }
        }
    }
    ok($cleCsrfTrouvee, 'Rate-limit CSRF — clé csrf:* présente dans rate-limit.json');
    ok($nbTimestamps >= 50, "Rate-limit CSRF — >= 50 timestamps dans la clé csrf:* ($nbTimestamps)");

    resetEtat();
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour3]);
    unset($pdoClean);

    // --- Cas 4 : clé IPv4/IPv6 normalisée dans le store ---

    resetEtat();

    $pdo = dbConnect();
    $pdo->exec(
        "INSERT INTO jours (date, heure_debut, heure_fin, capacite)"
        . " VALUES ('2026-06-04', '18:00', '22:30', 100)"
    );
    $idJour4 = (int)$pdo->lastInsertId();
    unset($pdo);

    $cookieIp = bin2hex(random_bytes(16));
    $tokens   = csrfTokens($cookieIp);
    $r = http(
        'POST',
        "/jour/$idJour4/inscrire",
        array_merge(['nom' => 'TestIPv4'], $tokens),
        ['csrf_session' => $cookieIp]
    );
    ok($r['code'] === 303, 'Rate-limit clé IP — POST inscription → 303');

    $rateData2 = file_exists($ratePath)
        ? json_decode((string)file_get_contents($ratePath), true)
        : null;

    $cleIpTrouvee = false;
    if (is_array($rateData2)) {
        foreach (array_keys($rateData2) as $cle) {
            $cleStr = (string)$cle;
            if ($cleStr === '127.0.0.1' || str_ends_with($cleStr, '/64')) {
                $cleIpTrouvee = true;
                break;
            }
        }
    }
    ok($cleIpTrouvee, 'Rate-limit clé IP — clé 127.0.0.1 ou ::/64 dans rate-limit.json');

    resetEtat();
    $pdoClean = dbConnect();
    $pdoClean->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdoClean->prepare('DELETE FROM jours WHERE id = ?')->execute([$idJour4]);
    unset($pdoClean);
}
