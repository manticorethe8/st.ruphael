<?php
/* ============================================================
   PDO connection factory — prepared statements only.
   Credentials never leave the server.
   ============================================================ */
require_once __DIR__ . '/config.php';

function db(bool $withDatabase = true): PDO
{
    static $conn = null;
    static $connNoDb = null;

    if ($withDatabase && $conn instanceof PDO) return $conn;
    if (!$withDatabase && $connNoDb instanceof PDO) return $connNoDb;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
         . ($withDatabase ? ';dbname=' . DB_NAME : '')
         . ';charset=' . DB_CHARSET;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    $pdo->exec("SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    /* Every timestamp written by NOW() must be Addis Ababa time (EAT, UTC+3,
       no daylight saving), the same zone config.php sets for PHP. Using a
       fixed offset works without MySQL's tz tables. */
    $pdo->exec("SET time_zone = '+03:00'");

    if ($withDatabase) $conn = $pdo; else $connNoDb = $pdo;
    return $pdo;
}

/** Is the platform installed (schema present + master user)? */
function sr_is_installed(): bool
{
    try {
        $pdo = db();
        $st  = $pdo->query("SHOW TABLES LIKE 'users'");
        if (!$st->fetch()) return false;
        $n = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='master'")->fetchColumn();
        return $n > 0;
    } catch (Throwable $e) {
        return false;
    }
}
