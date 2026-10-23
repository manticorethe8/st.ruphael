<?php
/* ============================================================
   config/config.local.example.php

   Per-machine settings that must NOT be committed.

   HOW TO USE
     1. Copy this file to  config/config.local.php
     2. Edit the values below.
     3. Done — config/config.php loads it automatically, before
        its own defaults, and every default is guarded with
        sr_define() so whatever you set here wins.

   config/config.local.php is listed in .gitignore, so a server
   password never travels with the code. On a stock XAMPP box
   you do not need this file at all.
   ============================================================ */

if (!defined('SR_APP')) define('SR_APP', true);

/* -------- Database -------- */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'st_rufael');
define('DB_USER', 'root');
define('DB_PASS', '');          // <- your MySQL root password

/* -------- Optional overrides --------
   Uncomment only what you actually want to change.       */

// define('SESSION_IDLE_TIMEOUT', 60 * 60 * 4);  // 4 h instead of 2
// define('LOGIN_MAX_ATTEMPTS', 5);
// define('APP_NAME_EN', 'St. Rufael Church School — Bole Branch');
