<?php
/* ============================================================
   Sessions, roles, CSRF, audit logging, permission gates.
   THE server is the only source of truth for identity/role.
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../lib/helpers.php';

/* ---------------------------- session ---------------------------- */
function sr_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['uid'])) {
        $idle = $now - (int)($_SESSION['last_seen'] ?? $now);
        $age  = $now - (int)($_SESSION['login_at'] ?? $now);
        if ($idle > SESSION_IDLE_TIMEOUT || $age > SESSION_ABSOLUTE_TIMEOUT) {
            sr_logout(false);
            return;
        }
        $_SESSION['last_seen'] = $now;
    }
}

function sr_login_user(array $u, bool $remember = false): void
{
    sr_session_start();
    session_regenerate_id(true);
    $_SESSION['uid']       = (int)$u['id'];
    $_SESSION['username']  = $u['username'];
    $_SESSION['role']      = $u['role'];
    $_SESSION['full_name'] = $u['full_name'];
    $_SESSION['lang']      = $u['lang'] ?? 'am';
    $_SESSION['login_at']  = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf']      = bin2hex(random_bytes(32));
    if ($remember) {
        // Extend only the *cookie* lifetime; the server still enforces timeouts.
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + 60 * 60 * 24 * 14,
            'path'     => '/', 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}

function sr_logout(bool $destroyCookie = true): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION = [];
    if ($destroyCookie && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

/* ---------------------------- identity ---------------------------- */
function current_user(): ?array
{
    sr_session_start();
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null && $cache['id'] === (int)$_SESSION['uid']) return $cache;
    $st = db()->prepare("SELECT id, username, email, full_name, role, lang, numerals,
                                is_active, must_change_pw
                           FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || (int)$u['is_active'] !== 1) { sr_logout(); return null; }
    $u['id'] = (int)$u['id'];
    $cache = $u;
    return $u;
}

function is_logged_in(): bool { return current_user() !== null; }
function current_role(): string { $u = current_user(); return $u ? $u['role'] : 'guest'; }
function current_uid(): ?int { $u = current_user(); return $u ? (int)$u['id'] : null; }

/** master > admin > teacher. The retired 'editor' role ranks as a teacher. */
function role_rank(string $r): int
{
    return ['teacher' => 1, 'admin' => 2, 'master' => 3, 'editor' => 1][$r] ?? 0;
}

/** Every role that exists, weakest first. 'master' is never assignable.
    The 'editor' role was retired; any role stored in role_permissions that
    is not built-in is a custom role created on the Roles & access page. */
function sr_roles(): array
{
    $built = ['teacher', 'admin', 'master'];
    try {
        $rows = db()->query(
            "SELECT DISTINCT role FROM role_permissions
             WHERE role NOT IN ('teacher','admin','master','editor')
             ORDER BY role"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $rows = []; }
    return array_merge($built, $rows ?: []);
}

/** Roles the *current* user is allowed to hand out when creating/editing. */
function sr_assignable_roles(?string $byRole = null): array
{
    $byRole = $byRole ?? current_role();
    $custom = array_values(array_diff(sr_roles(), ['teacher', 'admin', 'master']));
    return match ($byRole) {
        'master' => array_merge(['teacher', 'admin'], $custom),  // never 'master'
        'admin'  => array_merge(['teacher', 'admin'], $custom),
        default  => [],
    };
}
function role_at_least(string $min): bool
{
    return role_rank(current_role()) >= role_rank($min);
}

/* ---------------------------- capabilities ---------------------------- */
/**
 * Every capability the application knows about, grouped for the
 * master's permission editor. Adding a capability here is all it
 * takes for it to appear in the UI.
 */
function sr_capability_catalog(): array
{
    return [
        'general' => ['dashboard.view', 'reports.view'],
        'students' => [
            'students.view', 'students.create', 'students.edit',
            'students.delete', 'students.import', 'export.students', 'idcard.view',
        ],
        'attendance' => ['attendance.view', 'attendance.edit', 'attendance.correct'],
        'academics'  => ['grades.view', 'grades.manage', 'promotion.manage', 'years.manage'],
        'people'     => [
            'teachers.view', 'teachers.manage', 'teachers.assign',
            'users.view', 'users.manage', 'users.reset_password',
        ],
        'system'     => [
            'settings.view', 'settings.manage', 'features.manage',
            'permissions.manage', 'backup.manage', 'audit.view', 'audit.purge',
        ],
    ];
}

/** Flat list of every known capability. */
function sr_all_capabilities(): array
{
    $out = [];
    foreach (sr_capability_catalog() as $caps) foreach ($caps as $c) $out[] = $c;
    return $out;
}

/**
 * Hardcoded fallback defaults. These apply when role_permissions has
 * no rows for a role, so a fresh or half-configured database still
 * behaves sensibly. The master's editor writes over them.
 */
function sr_default_capabilities(string $role): array
{
    $teacher = [
        'dashboard.view'        => true,
        'students.view'         => true,   // scoped to assigned grades
        'attendance.view'       => true,
        'attendance.edit'       => true,
        'attendance.correct'    => true,   // scoped to assigned grades
        'reports.view'          => true,
        'export.students'       => true,   // scoped
        'idcard.view'           => true,
    ];
    $admin = $teacher + [
        'students.create'       => true,
        'students.edit'         => true,
        'students.import'       => true,
        'students.delete'       => true,
        'grades.view'           => true,
        'grades.manage'         => true,
        'teachers.view'         => true,
        'teachers.manage'       => true,
        'teachers.assign'       => true,
        'promotion.manage'      => true,
        'settings.view'         => true,
        'settings.manage'       => true,
        'years.manage'          => true,
        'users.view'            => true,
        'users.manage'          => true,
        'users.reset_password'  => true,
        /* admins run the Roles & access page and may create new roles;
           the master-only powers below stay out of their reach. */
        'permissions.manage'    => true,
    ];
    /* audit + features + backup stay with master. */
    $master = $admin + [
        'backup.manage'         => true,
        'features.manage'       => true,
        'audit.view'            => true,
        'audit.purge'           => true,
    ];
    /* Custom roles start at teacher level until their manager saves a set;
       the retired 'editor' role now covers the same ground as a teacher. */
    return match ($role) {
        'master'  => $master,
        'admin'   => $admin,
        'teacher' => $teacher,
        default   => $teacher,
    };
}

/**
 * Capabilities a *role* has: DB rows when the master has saved any for
 * that role, otherwise the hardcoded defaults.
 */
function sr_capabilities(string $role): array
{
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];

    $caps = sr_default_capabilities($role);
    try {
        $st = db()->prepare("SELECT capability, allowed FROM role_permissions WHERE role = ?");
        $st->execute([$role]);
        $rows = $st->fetchAll();
        if ($rows) {
            /* An explicit saved set replaces the defaults entirely, so a
               revoked capability really disappears. */
            $caps = [];
            foreach ($rows as $r) {
                if ((int)$r['allowed'] === 1) $caps[$r['capability']] = true;
            }
        }
    } catch (Throwable $e) {
        /* table missing (pre-migration) — defaults already loaded */
    }

    /* The master can never lock itself out of the permission editor. */
    if ($role === 'master') {
        $caps['permissions.manage'] = true;
        $caps['users.view']         = true;
        $caps['users.manage']       = true;
    }

    $cache[$role] = $caps;
    return $caps;
}

/** Per-user overrides on top of the role set. */
function sr_user_overrides(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $out = [];
    try {
        $st = db()->prepare("SELECT capability, allowed FROM user_permissions WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll() as $r) $out[$r['capability']] = (int)$r['allowed'] === 1;
    } catch (Throwable $e) { /* table missing — no overrides */ }
    $cache[$userId] = $out;
    return $out;
}

/** Effective capability map for a user row (role defaults + overrides). */
function sr_effective_capabilities(array $u): array
{
    $caps = sr_capabilities((string)$u['role']);
    foreach (sr_user_overrides((int)$u['id']) as $cap => $allowed) {
        if ($allowed) $caps[$cap] = true;
        else unset($caps[$cap]);
    }
    if (($u['role'] ?? '') === 'master') {
        $caps['permissions.manage'] = true;   // never revocable
    }
    return $caps;
}

function can(string $cap): bool
{
    $u = current_user();
    if (!$u) return false;
    $caps = sr_effective_capabilities($u);
    return !empty($caps[$cap]);
}

/* ---------------------------- teacher scoping ---------------------------- */
/**
 * Grade ids the current user may touch.
 * master/admin → null (means "all"); teacher → explicit id list.
 */
function sr_teacher_grade_ids(int $userId, ?int $yearId = null): array
{
    $sql  = "SELECT DISTINCT grade_id FROM teacher_classes WHERE user_id = ?";
    $args = [$userId];
    if ($yearId) { $sql .= " AND academic_year_id = ?"; $args[] = $yearId; }
    $st = db()->prepare($sql);
    $st->execute($args);
    return array_map('intval', array_column($st->fetchAll(), 'grade_id'));
}

/** null = unrestricted (master/admin); array = allowed grade ids (teacher) */
function sr_scope_grades(?int $yearId = null): ?array
{
    $u = current_user();
    if (!$u) return [];
    if ($u['role'] === 'master' || $u['role'] === 'admin') return null;
    return sr_teacher_grade_ids($u['id'], $yearId);
}

/** Enforced check — a teacher may never reach another teacher's grade. */
function sr_assert_grade_access(int $gradeId, ?int $yearId = null): void
{
    $scope = sr_scope_grades($yearId);
    if ($scope === null) return;
    if (!in_array($gradeId, $scope, true)) {
        json_error('You are not assigned to this grade.', 403, 'forbidden_grade');
    }
}

/** Enforced check for a specific student (via their enrollment). */
function sr_assert_student_access(int $studentId, ?int $yearId = null): void
{
    $scope = sr_scope_grades($yearId);
    if ($scope === null) return;
    if (!$scope) json_error('You are not assigned to any grade.', 403, 'forbidden_grade');
    $in  = implode(',', array_fill(0, count($scope), '?'));
    $sql = "SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND grade_id IN ($in)";
    $args = array_merge([$studentId], $scope);
    if ($yearId) { $sql .= " AND academic_year_id = ?"; $args[] = $yearId; }
    $st = db()->prepare($sql);
    $st->execute($args);
    if ((int)$st->fetchColumn() === 0) {
        json_error('This student is not in your assigned classes.', 403, 'forbidden_student');
    }
}

/* ---------------------------- CSRF ---------------------------- */
function csrf_token(): string
{
    sr_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    sr_session_start();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!$sent) {
        $raw = sr_raw_json();
        $sent = $raw['csrf_token'] ?? '';
    }
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_error('Invalid or missing CSRF token.', 419, 'csrf');
    }
}

/* ---------------------------- guards ---------------------------- */
function require_login(): array
{
    $u = current_user();
    if (!$u) json_error('Authentication required.', 401, 'unauthenticated');
    return $u;
}

function require_role(string $min): array
{
    $u = require_login();
    if (!role_at_least($min)) json_error('Insufficient privileges.', 403, 'forbidden');
    return $u;
}

function require_cap(string $cap): array
{
    $u = require_login();
    if (!can($cap)) json_error('You do not have permission to perform this action.', 403, 'forbidden_cap');
    return $u;
}

/* ---------------------------- audit ---------------------------- */
function audit(string $action, ?string $entityType = null, $entityId = null, ?string $summary = null, array $meta = []): void
{
    try {
        $u = null;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) {
            $u = ['id' => (int)$_SESSION['uid'], 'username' => $_SESSION['username'] ?? null, 'role' => $_SESSION['role'] ?? null];
        }
        $st = db()->prepare("INSERT INTO audit_logs
            (user_id, username, role, action, entity_type, entity_id, summary, meta, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $u['id'] ?? null,
            $u['username'] ?? null,
            $u['role'] ?? null,
            $action,
            $entityType,
            $entityId === null ? null : (string)$entityId,
            $summary !== null ? mb_substr($summary, 0, 255) : null,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) { /* auditing must never break the request */ }
}
