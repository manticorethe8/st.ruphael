<?php
/* ============================================================
   auth/logout.php — GET fallback sign-out.
   The SPA signs out through api/auth.php?action=logout (POST +
   CSRF). This page exists so a plain link, a bookmark or a
   JS-less browser can still end the session safely.
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$u = current_user();
if ($u) {
    audit('auth.logout', 'user', (int)$u['id'], $u['username'] . ' signed out');
}
sr_logout();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: login.php?bye=1');
exit;
