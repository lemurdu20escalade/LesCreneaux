<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && (is_file($file) || is_dir($file))) {
    return false;
}
require __DIR__ . '/index.php';
