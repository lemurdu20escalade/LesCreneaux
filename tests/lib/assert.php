<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Compteurs PASS/FAIL globaux, lus en fin de run par integration.php
// pour décider de l'exit code.
$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void
{
    global $pass, $fail;

    if ($cond) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label\n";
    }
}
