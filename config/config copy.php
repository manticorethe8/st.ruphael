<?php
/* ============================================================
   St. Rufael Church School — Platform configuration
   Edit the DB_* values to match your XAMPP installation.
   Default values are the XAMPP factory defaults.
   ============================================================ */

if (!defined('SR_APP')) define('SR_APP', true);

/* -------- Local overrides -------------------------------------------
   If config/config.local.php exists it is loaded FIRST and whatever it
   defines wins — every define() below is guarded. Copy
   config/config.local.example.php to config/config.local.php on a real
   server to keep production credentials out of version control
   (.gitignore already excludes the local file).
   -------------------------------------------------------------------- */
if (is_file(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';

/** define() that never fights a local override or a re-include. */
if (!function_exists('sr_define')) {
    function sr_define(string $k, $v): void { if (!defined($k)) define($k, $v); }
}

/* -------- Database (XAMPP defaults) -------- */
sr_define('DB_HOST', getenv('SR_DB_HOST') ?: 'sql210.infinityfree.com');
sr_define('DB_PORT', getenv('SR_DB_PORT') ?: '3306');
sr_define('DB_NAME', getenv('SR_DB_NAME') ?: 'if0_42452540_st_rufael');
sr_define('DB_USER', getenv('SR_DB_USER') ?: 'if0_42452540');
sr_define('DB_PASS', getenv('SR_DB_PASS') ?: 'tT9MWJRsPAStpe');
sr_define('DB_CHARSET', 'utf8mb4');

/* -------- Session / security -------- */
sr_define('SESSION_NAME', 'SR_SESSION');
sr_define('SESSION_IDLE_TIMEOUT', 60 * 60 * 2);   // 2 hours of inactivity
sr_define('SESSION_ABSOLUTE_TIMEOUT', 60 * 60 * 12);
sr_define('LOGIN_MAX_ATTEMPTS', 8);               // per username+ip / 15 min
sr_define('LOGIN_LOCKOUT_SECONDS', 900);

/* -------- Paths -------- */
sr_define('SR_ROOT', dirname(__DIR__));
sr_define('UPLOAD_DIR', SR_ROOT . '/uploads');
sr_define('EXPORT_DIR', SR_ROOT . '/exports');
sr_define('BACKUP_DIR', SR_ROOT . '/backups');

/* -------- Behaviour switches --------
   Flags that change how the app behaves. Each one is read in several
   places, so change it HERE and nowhere else.                        */

/* Student IDs (18-0042) and roll numbers.
     false (default) — the server generates both. The form still shows
                       the fields, greyed out and reading "auto", so the
                       registrar can see them but cannot mistype them.
     true            — the fields become editable and a value typed by
                       the user is accepted; blank still auto-generates.

   The value is published to the browser in index.php as
   SR_BOOT.manual_ids and enforced server-side in api/students.php —
   turning it on in one place is enough. */
sr_define('SR_MANUAL_IDENTIFIERS', false);

/* Largest student photo accepted, in bytes. The browser downscales
   pictures to 600x600 on a canvas before uploading, so real uploads land
   well under this; the limit only stops abuse. */
sr_define('SR_PHOTO_MAX_BYTES', 2 * 1024 * 1024);   // 2 MB

/* -------- Misc -------- */
sr_define('APP_NAME_AM', 'ቅዱስ ሩፋኤል የሰንበት ትምህርት ቤት');
sr_define('APP_NAME_EN', 'St. Rufael Church School');
sr_define('APP_VERSION', '2.4.0');

/* Marker file written by install.php once the schema exists */
sr_define('INSTALL_LOCK', SR_ROOT . '/config/installed.lock');

date_default_timezone_set(getenv('SR_TZ') ?: 'Africa/Addis_Ababa');
mb_internal_encoding('UTF-8');
