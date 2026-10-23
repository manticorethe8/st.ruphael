<?php
/* ============================================================
   api/permissions.php — the Master's role & access editor.

   Two layers, both editable here:
     · role_permissions — the default capability set for a role
     · user_permissions — per-user grants/revokes on top of it

   Guarded by 'permissions.manage', which is pinned to master in
   config/auth.php and cannot be revoked.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('matrix');
$user   = require_login();
require_cap('permissions.manage');

/* Built-in roles this editor may touch besides the custom ones it created.
   'master' is never editable: it always has everything, and letting it be
   edited is how you lock yourself out. */
const SR_EDITABLE_ROLES = ['admin', 'teacher'];

/** Any role except 'master' — built-in or custom — may be edited here. */
function sr_editable_role(string $role): bool
{
    return $role !== 'master' && in_array($role, sr_roles(), true);
}

switch ($action) {

/* ------------------------------------------------------------------
   matrix — the full picture: catalog, per-role effective values and
   which of them are stored overrides vs. inherited defaults.
   ------------------------------------------------------------------ */
case 'matrix': {
    sr_methods(['GET', 'POST']);

    $stored = [];
    $st = db()->query("SELECT role, capability, allowed FROM role_permissions");
    foreach ($st->fetchAll() as $r) $stored[$r['role']][$r['capability']] = (int)$r['allowed'] === 1;

    $roles = [];
    foreach (sr_roles() as $role) {
        $roles[$role] = [
            'role'       => $role,
            'editable'   => sr_editable_role($role),
            'customised' => !empty($stored[$role]),
            'caps'       => array_keys(array_filter(sr_capabilities($role))),
            'defaults'   => array_keys(array_filter(sr_default_capabilities($role))),
        ];
    }

    json_ok([
        'catalog'  => sr_capability_catalog(),
        'labels'   => sr_capability_labels(),
        'roles'    => array_values($roles),
        'editable' => SR_EDITABLE_ROLES,
    ]);
}

/* ------------------------------------------------------------------
   save_role — replace one role's capability set outright.
   Body: {role, capabilities:[...]}
   ------------------------------------------------------------------ */
case 'save_role': {
    sr_methods(['POST']);
    $role = strtolower((string)input_str('role', ''));
    if (!sr_editable_role($role)) json_error('Only custom and built-in (non-master) roles can be edited.', 403, 'forbidden_role');
    $caps = array_values(array_unique(array_map('strval', input_array('capabilities'))));

    $known = sr_all_capabilities();
    foreach ($caps as $c) {
        if (!in_array($c, $known, true)) json_error("Unknown capability: {$c}", 422, 'validation');
    }

    /* Guard rails: a role that can reach the app at all needs the
       dashboard, and only master may hold the master-only powers. */
    $reserved = ['permissions.manage', 'audit.view', 'audit.purge', 'backup.manage', 'features.manage'];
    foreach ($reserved as $r) {
        if (in_array($r, $caps, true)) {
            json_error("“{$r}” is reserved for the Master account.", 403, 'forbidden_cap');
        }
    }

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM role_permissions WHERE role = ?")->execute([$role]);
        $ins = $db->prepare("INSERT INTO role_permissions (role, capability, allowed, updated_by) VALUES (?,?,1,?)");
        foreach ($caps as $c) $ins->execute([$role, $c, $user['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_error('Could not save the role permissions.', 500, 'server');
    }

    audit('permissions.role_saved', 'role', $role,
          "Permissions for role “{$role}” updated (" . count($caps) . ' capabilities)',
          ['capabilities' => $caps]);
    json_ok(['role' => $role, 'count' => count($caps)], ['message' => "Permissions for {$role} saved."]);
}

/* ------------------------------------------------------------------
   reset_role — drop the stored set so the role falls back to defaults.
   ------------------------------------------------------------------ */
case 'reset_role': {
    sr_methods(['POST']);
    $role = strtolower((string)input_str('role', ''));
    if (!sr_editable_role($role)) json_error('Only custom and built-in (non-master) roles can be reset.', 403, 'forbidden_role');

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM role_permissions WHERE role = ?")->execute([$role]);
        /* A custom role must keep at least one row, or it would vanish from
           the role lists; it falls back to the safe teacher-level defaults. */
        if (!in_array($role, SR_EDITABLE_ROLES, true)) {
            $ins = $db->prepare("INSERT IGNORE INTO role_permissions (role, capability, allowed, updated_by)
                                 VALUES (?,?,1,?)");
            foreach (array_keys(array_filter(sr_default_capabilities('teacher'))) as $c) {
                $ins->execute([$role, $c, $user['id']]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_error('Could not reset the role.', 500, 'server');
    }

    audit('permissions.role_reset', 'role', $role, "Role “{$role}” reset to built-in defaults");
    json_ok(['role' => $role], ['message' => "{$role} reset to the built-in defaults."]);
}

/* ------------------------------------------------------------------
   create_role — make a brand-new role. Admin and master reach this
   page (permissions.manage); a young role is seeded with the safe
   teacher-level defaults and can never hold master-only powers.
   ------------------------------------------------------------------ */
case 'create_role': {
    sr_methods(['POST']);
    $name = strtolower(trim((string)input_str('name', '')));
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) {
        json_error('Role names: 2–32 letters, start with a letter; use a-z, 0-9, _ or - only.', 422, 'validation');
    }
    if (in_array($name, ['master', 'admin', 'teacher', 'editor'], true)) {
        json_error('That is a built-in role name.', 422, 'validation');
    }
    if (in_array($name, sr_roles(), true)) json_error("Role “{$name}” already exists.", 409, 'duplicate');

    $defaults = array_keys(array_filter(sr_default_capabilities('teacher')));
    $db = db();
    $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT IGNORE INTO role_permissions (role, capability, allowed, updated_by)
                             VALUES (?,?,1,?)");
        foreach ($defaults as $c) $ins->execute([$name, $c, $user['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_error('Could not create the role.', 500, 'server');
    }

    audit('permissions.role_created', 'role', $name,
          "New role “{$name}” created with default capabilities", ['capabilities' => $defaults]);
    json_ok(['role' => $name, 'count' => count($defaults)],
            ['message' => "Role “{$name}” created. Set its permissions now."]);
}

/* ------------------------------------------------------------------
   user — one user's effective map plus their stored overrides.
   ------------------------------------------------------------------ */
case 'user': {
    sr_methods(['GET', 'POST']);
    $id = (int)input_int('id', 0);
    $st = db()->prepare("SELECT id, username, full_name, role FROM users WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    $target['id'] = (int)$target['id'];

    $ov = [];
    $o = db()->prepare("SELECT capability, allowed FROM user_permissions WHERE user_id = ?");
    $o->execute([$id]);
    foreach ($o->fetchAll() as $r) $ov[$r['capability']] = (int)$r['allowed'] === 1;

    json_ok([
        'user'      => $target,
        'catalog'   => sr_capability_catalog(),
        'labels'    => sr_capability_labels(),
        'role_caps' => array_keys(array_filter(sr_capabilities($target['role']))),
        'overrides' => $ov,
        'effective' => array_keys(array_filter(sr_effective_capabilities($target))),
    ]);
}

/* ------------------------------------------------------------------
   save_user — store per-user overrides.
   Body: {id, overrides:{cap: true|false|null}}  (null clears one)
   ------------------------------------------------------------------ */
case 'save_user': {
    sr_methods(['POST']);
    $id = (int)input_int('id', 0);
    $st = db()->prepare("SELECT id, username, role FROM users WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    if ($target['role'] === 'master') {
        json_error('A Master account already holds every permission.', 409, 'forbidden');
    }

    $raw   = input('overrides', []);
    if (!is_array($raw)) $raw = [];
    $known = sr_all_capabilities();

    $db = db();
    $db->beginTransaction();
    try {
        $del = $db->prepare("DELETE FROM user_permissions WHERE user_id = ? AND capability = ?");
        $ins = $db->prepare("INSERT INTO user_permissions (user_id, capability, allowed, updated_by)
                             VALUES (?,?,?,?)
                             ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by)");
        $n = 0;
        foreach ($raw as $cap => $val) {
            $cap = (string)$cap;
            if (!in_array($cap, $known, true)) continue;
            if ($val === null || $val === '' || $val === 'inherit') { $del->execute([$id, $cap]); continue; }
            $allowed = in_array(strtolower((string)$val), ['1','true','yes','on','allow'], true) || $val === true;
            /* master-only powers are never grantable to anyone else */
            if ($allowed && in_array($cap, ['permissions.manage','audit.view','audit.purge','backup.manage','features.manage'], true)) {
                continue;
            }
            $ins->execute([$id, $cap, $allowed ? 1 : 0, $user['id']]);
            $n++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        json_error('Could not save the overrides.', 500, 'server');
    }

    audit('permissions.user_saved', 'user', $id,
          "Permission overrides updated for {$target['username']}", ['overrides' => $raw]);
    json_ok(['id' => $id], ['message' => 'Permissions saved.']);
}

/* ------------------------------------------------------------------
   clear_user — remove every override for one user.
   ------------------------------------------------------------------ */
case 'clear_user': {
    sr_methods(['POST']);
    $id = (int)input_int('id', 0);
    db()->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$id]);
    audit('permissions.user_cleared', 'user', $id, 'Permission overrides cleared');
    json_ok(['id' => $id], ['message' => 'Overrides cleared — this user now follows their role.']);
}

default:
    json_error('Unknown permissions action: ' . $action, 404, 'unknown_action');
}

/** Bilingual labels for every capability, for the editor UI. */
function sr_capability_labels(): array
{
    return [
        'dashboard.view'       => ['am' => 'ዳሽቦርድ ማየት',            'en' => 'View dashboard'],
        'reports.view'         => ['am' => 'ሪፖርቶችን ማየት',          'en' => 'View reports'],
        'students.view'        => ['am' => 'ተማሪዎችን ማየት',          'en' => 'View students'],
        'students.create'      => ['am' => 'ተማሪ መጨመር',            'en' => 'Add students'],
        'students.edit'        => ['am' => 'ተማሪ ማረም',              'en' => 'Edit students'],
        'students.delete'      => ['am' => 'ተማሪ ማጥፋት',            'en' => 'Delete students'],
        'students.import'      => ['am' => 'ተማሪዎችን ማስገባት',        'en' => 'Import students'],
        'export.students'      => ['am' => 'ተማሪዎችን ማውጣት',        'en' => 'Export students'],
        'idcard.view'          => ['am' => 'መታወቂያ ማተም',           'en' => 'Print ID cards'],
        'attendance.view'      => ['am' => 'መገኘት ማየት',             'en' => 'View attendance'],
        'attendance.edit'      => ['am' => 'መገኘት መመዝገብ',          'en' => 'Record attendance'],
        'attendance.correct'   => ['am' => 'መገኘት ማስተካከል',        'en' => 'Correct attendance'],
        'grades.view'          => ['am' => 'ክፍሎችን ማየት',           'en' => 'View grades'],
        'grades.manage'        => ['am' => 'ክፍሎችን ማስተዳደር',        'en' => 'Manage grades'],
        'promotion.manage'     => ['am' => 'ማሳደግ ማስኬድ',           'en' => 'Run promotion'],
        'years.manage'         => ['am' => 'የትምህርት ዓመት ማስተዳደር',  'en' => 'Manage academic years'],
        'teachers.view'        => ['am' => 'መምህራንን ማየት',          'en' => 'View teachers'],
        'teachers.manage'      => ['am' => 'መምህራንን ማስተዳደር',      'en' => 'Manage teachers'],
        'teachers.assign'      => ['am' => 'መምህር መመደብ',            'en' => 'Assign teachers'],
        'users.view'           => ['am' => 'ተጠቃሚዎችን ማየት',        'en' => 'View users'],
        'users.manage'         => ['am' => 'ተጠቃሚ ማስተዳደር',        'en' => 'Manage users'],
        'users.reset_password' => ['am' => 'የይለፍ ቃል ዳግም ማስጀመር',  'en' => 'Reset passwords'],
        'settings.view'        => ['am' => 'ቅንብሮችን ማየት',          'en' => 'View settings'],
        'settings.manage'      => ['am' => 'ቅንብሮችን ማረም',          'en' => 'Change settings'],
        'features.manage'      => ['am' => 'የባህሪ ማብሪያዎች',         'en' => 'Feature switches'],
        'permissions.manage'   => ['am' => 'ፈቃዶችን ማስተዳደር',       'en' => 'Manage permissions'],
        'backup.manage'        => ['am' => 'ምትኬ ማስተዳደር',          'en' => 'Backup & restore'],
        'audit.view'           => ['am' => 'የሥራ መዝገብ ማየት',        'en' => 'View audit trail'],
        'audit.purge'          => ['am' => 'የሥራ መዝገብ ማጽዳት',      'en' => 'Purge audit trail'],
    ];
}
