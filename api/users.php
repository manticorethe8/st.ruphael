<?php
/* api/users.php — Master-level user administration. */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------ */
case 'list': {
    sr_methods(['GET', 'POST']);
    require_cap('users.view');
    $q    = input_str('q');
    $role = input_str('role');
    $where = ['1=1']; $args = [];
    if (!input_bool('include_deleted')) $where[] = 'u.deleted_at IS NULL';
    if ($q !== '') {
        [$qSql, $qArgs] = sr_search_clause($q, ['u.username', 'u.full_name', 'u.email']);
        if ($qSql !== '') { $where[] = $qSql; $args = array_merge($args, $qArgs); }
    }
    if ($role !== '') { $where[] = 'u.role = ?'; $args[] = $role; }
    /* Item 16: only a Master may see Master accounts. Filtering in SQL
       (rather than after the fetch) also keeps the role filter honest. */
    if (!sr_is_master()) $where[] = "u.role <> 'master'";

    $st = db()->prepare("SELECT u.*, (SELECT COUNT(*) FROM teacher_classes tc WHERE tc.user_id = u.id) assigned_classes
                         FROM users u WHERE " . implode(' AND ', $where) . "
                         ORDER BY FIELD(u.role,'master','admin','teacher'), u.full_name");
    $st->execute($args);
    json_ok(['users' => array_map('sr_user_out', $st->fetchAll())]);
}

/* ------------------------------------------------------------------ */
case 'get': {
    sr_methods(['GET', 'POST']);
    require_cap('users.view');
    $id = (int)input_int('id', 0);
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) json_error('That user does not exist.', 404, 'not_found');
    sr_assert_user_target($u);

    $tc = db()->prepare("SELECT tc.*, g.name_am, g.name_en, g.code, y.year_ec
                         FROM teacher_classes tc
                         JOIN grades g ON g.id = tc.grade_id
                         JOIN academic_years y ON y.id = tc.academic_year_id
                         WHERE tc.user_id = ? ORDER BY y.year_ec DESC, g.sort_order");
    $tc->execute([$id]);
    json_ok(['user' => sr_user_out($u), 'classes' => $tc->fetchAll()]);
}

/* ------------------------------------------------------------------ */
case 'create': {
    sr_methods(['POST']);
    require_cap('users.manage');
    $username = strtolower(v_required(input_str('username'), 'Username'));
    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        json_error('Username must be 3–64 characters: letters, digits, dot, underscore or hyphen.', 422, 'validation');
    }
    $fullName = v_len(v_required(input_str('full_name'), 'Full name'), 'Full name', 160);

    /* Server-side role gate. 'master' is never assignable through the API;
       an admin may only create admin/teacher accounts. The dropdown is a
       convenience — this is the rule that actually holds. */
    $allowedRoles = sr_assignable_roles($user['role']);
    if (!$allowedRoles) json_error('Your account cannot create users.', 403, 'forbidden');
    $role = input_str('role', 'teacher') ?: 'teacher';
    if ($role === 'master') {
        json_error('Master accounts cannot be created here. Use install.php.', 403, 'forbidden_role');
    }
    if (!in_array($role, $allowedRoles, true)) {
        json_error('You may only create these roles: ' . implode(', ', $allowedRoles) . '.', 403, 'forbidden_role');
    }

    $email    = input_str('email');
    $phone    = input_str('phone');
    $pw       = (string)input('password', '');
    $lang     = v_in(input_str('lang', 'am') ?: 'am', ['am','en'], 'Language');
    $numerals = v_in(input_str('numerals', 'mix') ?: 'mix', ['geez','arabic','mix'], 'Numerals');

    /* No password supplied → issue a temporary one and force a change. */
    $temp = false;
    if ($pw === '') { $pw = sr_temp_password(); $temp = true; }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('That email address is not valid.', 422, 'validation');
    if (mb_strlen($pw) < 8) json_error('The password must be at least 8 characters.', 422, 'validation');

    $d = db()->prepare("SELECT id FROM users WHERE username = ?");
    $d->execute([$username]);
    if ($d->fetchColumn()) json_error("The username “{$username}” is already taken.", 409, 'duplicate');
    if ($email !== '') {
        $d = db()->prepare("SELECT id FROM users WHERE email = ?");
        $d->execute([$email]);
        if ($d->fetchColumn()) json_error('That email address is already registered.', 409, 'duplicate');
    }

    /* A generated password defaults to "must change", but the creator may
       deliberately hand out a standing one. */
    $mustChange = input_bool('must_change_pw', $temp ? true : true);
    $active     = input_bool('is_active', true);
    $st = db()->prepare("INSERT INTO users (username,email,password_hash,full_name,role,phone,lang,numerals,must_change_pw,is_active,created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$username, $email ?: null, password_hash($pw, PASSWORD_DEFAULT), $fullName, $role,
                  $phone ?: null, $lang, $numerals, $mustChange ? 1 : 0, $active ? 1 : 0, $user['id']]);
    $id = (int)db()->lastInsertId();

    /* optional immediate grade assignment for teachers */
    $grades = array_map('intval', input_array('grade_ids'));
    if ($role === 'teacher' && $grades) {
        $year = resolve_year();
        $ins = db()->prepare("INSERT IGNORE INTO teacher_classes (user_id, grade_id, academic_year_id, assigned_by) VALUES (?,?,?,?)");
        foreach ($grades as $g) $ins->execute([$id, $g, $year['id'], $user['id']]);
    }

    audit('user.created', 'user', $id, "User $username ($role) created", ['role' => $role, 'grades' => $grades]);
    json_ok([
        'id' => $id,
        'temp_password' => $temp ? $pw : null,
        'must_change_pw' => $mustChange,
    ], ['message' => "User $username created."]);
}

/* ------------------------------------------------------------------ */
case 'update': {
    sr_methods(['POST']);
    require_cap('users.manage');
    $id = (int)input_int('id', 0);
    if (!$id) json_error('A user id is required.', 422, 'validation');

    $t = db()->prepare("SELECT * FROM users WHERE id = ?");
    $t->execute([$id]);
    $target = $t->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    sr_assert_user_target($target);

    $fullName = v_len(v_required(input_str('full_name', $target['full_name']), 'Full name'), 'Full name', 160);

    $role = input_str('role', $target['role']) ?: $target['role'];
    if ($role !== $target['role']) {
        /* Changing a role follows the same gate as creating one. */
        $allowedRoles = sr_assignable_roles($user['role']);
        if ($role === 'master') json_error('Master cannot be granted through this screen.', 403, 'forbidden_role');
        if (!in_array($role, $allowedRoles, true)) {
            json_error('You may only assign these roles: ' . implode(', ', $allowedRoles) . '.', 403, 'forbidden_role');
        }
    }
    /* An admin may not edit a master account at all. */
    if ($target['role'] === 'master' && $user['role'] !== 'master') {
        json_error('Only a Master can edit a Master account.', 403, 'forbidden');
    }

    $email    = input_str('email', (string)$target['email']);
    $phone    = input_str('phone', (string)$target['phone']);
    $active   = input_bool('is_active', (bool)$target['is_active']);
    /* Language and numerals belong to the user, not to whoever edits them.
       They are not part of this form; keep whatever the user chose in their
       own Settings even if a stale client still posts the fields. */
    $lang     = v_in((string)$target['lang'] ?: 'am', ['am','en'], 'Language');
    $numerals = v_in((string)($target['numerals'] ?? 'mix') ?: 'mix', ['geez','arabic','mix'], 'Numerals');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('That email address is not valid.', 422, 'validation');

    /* never let the last active Master be demoted or switched off */
    if ($target['role'] === 'master' && ($role !== 'master' || !$active)) {
        $c = db()->query("SELECT COUNT(*) FROM users WHERE role='master' AND is_active=1 AND deleted_at IS NULL")->fetchColumn();
        if ((int)$c <= 1) json_error('This is the only active Master account — it cannot be demoted or disabled.', 409, 'last_master');
    }

    $st = db()->prepare("UPDATE users SET full_name=?, role=?, email=?, phone=?, is_active=?, lang=?, numerals=? WHERE id = ?");
    $st->execute([$fullName, $role, $email ?: null, $phone ?: null, $active ? 1 : 0, $lang, $numerals, $id]);

    audit('user.updated', 'user', $id, "User {$target['username']} updated",
          ['role_from' => $target['role'], 'role_to' => $role, 'active' => $active]);
    json_ok(['id' => $id], ['message' => 'User updated.']);
}

/* ------------------------------------------------------------------ */
case 'reset_password': {
    sr_methods(['POST']);
    require_cap('users.reset_password');
    $id = (int)input_int('id', 0);

    $t = db()->prepare("SELECT username, role FROM users WHERE id = ? AND deleted_at IS NULL");
    $t->execute([$id]);
    $target = $t->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    $uname = $target['username'];

    /* An admin cannot reset a Master's password, and does not get told a
       Master account exists at all (see sr_assert_user_target). */
    sr_assert_user_target($target);

    /* A reset always issues a system-generated temporary password and
       always forces the change-password screen on next sign-in. */
    $pw = sr_temp_password();

    db()->prepare("UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?")
        ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);

    audit('user.password_reset', 'user', $id, "Temporary password issued for $uname");
    json_ok([
        'reset'          => true,
        'username'       => $uname,
        'temp_password'  => $pw,
        'must_change_pw' => true,
    ], ['message' => "Temporary password issued for $uname."]);
}

/* ------------------------------------------------------------------ */
case 'delete': {
    sr_methods(['POST']);
    require_cap('users.manage');
    $id = (int)input_int('id', 0);
    if ($id === (int)$user['id']) json_error('You cannot delete your own account.', 409, 'self_delete');

    $t = db()->prepare("SELECT username, role FROM users WHERE id = ? AND deleted_at IS NULL");
    $t->execute([$id]);
    $target = $t->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    sr_assert_user_target($target);
    if ($target['role'] === 'master') {
        $c = db()->query("SELECT COUNT(*) FROM users WHERE role='master' AND is_active=1 AND deleted_at IS NULL")->fetchColumn();
        if ((int)$c <= 1) json_error('The only Master account cannot be deleted.', 409, 'last_master');
    }

    db()->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE id = ?")->execute([$id]);
    db()->prepare("DELETE FROM teacher_classes WHERE user_id = ?")->execute([$id]);
    audit('user.deleted', 'user', $id, "User {$target['username']} removed (soft delete)");
    json_ok(['deleted' => true], ['message' => 'User removed.']);
}

/* ------------------------------------------------------------------ */
case 'restore': {
    sr_methods(['POST']);
    require_cap('users.manage');
    $id = (int)input_int('id', 0);
    /* Read the row first: restoring blind by id let a non-Master revive a
       soft-deleted Master account. */
    $t = db()->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $t->execute([$id]);
    $target = $t->fetch();
    if (!$target) json_error('That user does not exist.', 404, 'not_found');
    sr_assert_user_target($target);
    db()->prepare("UPDATE users SET deleted_at = NULL, is_active = 1 WHERE id = ?")->execute([$id]);
    audit('user.restored', 'user', $id, 'User restored');
    json_ok(['restored' => true]);
}

/* ------------------------------------------------------------------
   RECOVERY KEYS (v2.4, item 15) — Master only.
   A Master mints keys for their own account, prints them, and keeps
   them somewhere safe. Nobody else may see or touch them, so these two
   actions check the role directly rather than a capability: no
   permission edit should ever be able to hand this to an admin. */
case 'recovery_keys': {
    sr_methods(['GET', 'POST']);
    if (!sr_is_master()) json_error('Only a Master can manage recovery keys.', 403, 'forbidden');
    $q = db()->prepare("SELECT id, label, created_at, used_at, used_ip
                        FROM recovery_keys WHERE user_id = ? ORDER BY id DESC");
    $q->execute([(int)$user['id']]);
    $rows = $q->fetchAll();
    json_ok([
        'keys'   => $rows,
        'unused' => sr_recovery_key_count((int)$user['id']),
    ]);
}

case 'recovery_key_create': {
    sr_methods(['POST']);
    if (!sr_is_master()) json_error('Only a Master can create recovery keys.', 403, 'forbidden');
    /* A small cap keeps the spend loop cheap (it hash-compares every
       unused key) and discourages printing a stack of live keys. */
    if (sr_recovery_key_count((int)$user['id']) >= 5) {
        json_error('You already hold 5 unused keys. Use or revoke one first.', 409, 'too_many');
    }
    $label = input_str('label') ?: null;
    $plain = sr_recovery_key_issue((int)$user['id'], $label, (int)$user['id']);
    audit('user.recovery_key_created', 'user', (int)$user['id'], 'Master recovery key issued');
    /* The only time the plaintext ever leaves the server. */
    json_ok(['key' => $plain, 'unused' => sr_recovery_key_count((int)$user['id'])],
            ['message' => 'Recovery key created. Copy it now — it cannot be shown again.']);
}

case 'recovery_key_revoke': {
    sr_methods(['POST']);
    if (!sr_is_master()) json_error('Only a Master can revoke recovery keys.', 403, 'forbidden');
    $id = (int)input_int('id', 0);
    /* Scoped to the caller's own rows, so one Master cannot revoke
       another Master's keys. */
    $d = db()->prepare("DELETE FROM recovery_keys WHERE id = ? AND user_id = ?");
    $d->execute([$id, (int)$user['id']]);
    if (!$d->rowCount()) json_error('That key does not exist.', 404, 'not_found');
    audit('user.recovery_key_revoked', 'user', (int)$user['id'], "Recovery key #$id revoked");
    json_ok(['revoked' => true, 'unused' => sr_recovery_key_count((int)$user['id'])],
            ['message' => 'Recovery key revoked.']);
}

/* ------------------------------------------------------------------ */
default:
    json_error('Unknown users action: ' . $action, 404, 'unknown_action');
}


function sr_user_out(array $u): array
{
    return [
        'id'        => (int)$u['id'],
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'email'     => $u['email'],
        'phone'     => $u['phone'],
        'role'      => $u['role'],
        'lang'      => $u['lang'],
        'numerals'  => $u['numerals'] ?? 'mix',
        'is_active' => (bool)$u['is_active'],
        'must_change_pw' => (bool)$u['must_change_pw'],
        'assigned_classes' => isset($u['assigned_classes']) ? (int)$u['assigned_classes'] : null,
        'last_login_at' => $u['last_login_at'],
        'created_at'=> $u['created_at'],
        'deleted'   => !empty($u['deleted_at']),
    ];
}
