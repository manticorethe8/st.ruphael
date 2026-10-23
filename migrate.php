<?php
/* ============================================================
   migrate.php — CLI upgrade for an existing installation.

       cd  C:\xampp\htdocs\st.ruphael
       php migrate.php

   Safe to run repeatedly; each step checks before it changes.
   ============================================================ */
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    exit("migrate.php runs from the command line only:  php migrate.php\n");
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/migrations.php';

echo "St. Rufael — schema upgrade\n";
echo str_repeat('-', 44), "\n";

try {
    $log = sr_run_migrations();
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$log) {
    echo "Already up to date. Nothing to do.\n";
} else {
    foreach ($log as $line) echo "  * $line\n";
    echo "\nDone — " . count($log) . " change(s) applied.\n";
}
