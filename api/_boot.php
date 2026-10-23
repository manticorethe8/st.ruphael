<?php
/* Common bootstrap for every /api endpoint. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ethiopic.php';

sr_api_boot();
sr_session_start();

if (!sr_is_installed()) {
    json_error('The platform is not installed yet. Open install.php first.', 503, 'not_installed');
}

/** Route on ?action= / POST action, defaulting to 'list'. */
function sr_action(string $default = 'list'): string
{
    $a = input_str('action', $default);
    return $a !== '' ? $a : $default;
}

/** Reject anything that is not one of the listed HTTP methods. */
function sr_methods(array $allowed): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $allowed, true)) {
        json_error("Method $m is not allowed here.", 405, 'method');
    }
}

/** Every state-changing action must pass CSRF. */
function sr_guard_write(): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) csrf_check();
}
sr_guard_write();
