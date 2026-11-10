<?php
/* Sign-out requires a deliberate POST with a valid CSRF token. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
csrf_check();
$u = current_user();
if ($u) audit('auth.logout', 'user', (int)$u['id'], $u['username'] . ' signed out');
sr_logout();
header('Location: login.php?bye=1', true, 303);
exit;
