<?php
/* api/auth.php — login, logout, session probe, self password change. */
require_once __DIR__ . '/_boot.php';

$action = sr_action('me');

switch ($action) {

/* ---------------------------------------------------------------- */
case 'me': {
    sr_methods(['GET', 'POST']);
    $u = current_user();
    if (!$u) json_ok(['authenticated' => false, 'csrf_token' => csrf_token()]);
    json_ok([
        'authenticated' => true,
        'csrf_token'    => csrf_token(),
        'user'          => sr_public_user($u),
        'capabilities'  => sr_effective_capabilities($u),
        'grade_ids'     => sr_scope_grades(),
        'settings'      => settings_all(true),
        'year'          => current_year_row(),
        'today'         => ['iso' => date('Y-m-d'), 'et' => et_today(), 'kind' => iso_day_kind(date('Y-m-d'))],
    ]);
}

/* ---------------------------------------------------------------- */
case 'login': {
    sr_methods(['POST']);
    $username = input_str('username');
    $password = (string)input('password', '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($username === '' || $password === '') {
        json_error('Enter both your username and your password.', 422, 'validation');
    }

    /* throttle: too many failures for this username+ip */
    $th = db()->prepare("SELECT COUNT(*) FROM login_attempts
                         WHERE username = ? AND ip_address = ? AND success = 0
                           AND created_at > (NOW() - INTERVAL ? SECOND)");
    $th->execute([$username, $ip, LOGIN_LOCKOUT_SECONDS]);
    if ((int)$th->fetchColumn() >= LOGIN_MAX_ATTEMPTS) {
        audit('auth.locked_out', 'user', null, "Lockout for $username");
        json_error('Too many failed attempts. Please wait 15 minutes and try again.', 429, 'locked_out');
    }

    $st = db()->prepare("SELECT * FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();

    $ok = $u && password_verify($password, $u['password_hash']);

    $log = db()->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?,?,?)");
    $log->execute([$username, $ip, $ok ? 1 : 0]);

    if (!$ok) {
        audit('auth.login_failed', 'user', $u['id'] ?? null, "Failed login for $username");
        json_error('Incorrect username or password.', 401, 'bad_credentials');
    }
    if (!(int)$u['is_active']) {
        audit('auth.login_blocked', 'user', $u['id'], "Disabled account $username");
        json_error('This account has been disabled. Contact your administrator.', 403, 'disabled');
    }

    /* upgrade the hash if PHP's default cost changed */
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $rh = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $rh->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }

    sr_login_user($u);
    db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);
    db()->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0")
        ->execute([$username, $ip]);

    audit('auth.login', 'user', $u['id'], "$username signed in", ['role' => $u['role']]);

    json_ok([
        'user'          => sr_public_user($u),
        'capabilities'  => sr_effective_capabilities($u),
        'grade_ids'     => sr_scope_grades(),
        'must_change_pw'=> (bool)$u['must_change_pw'],
        'csrf_token'    => csrf_token(),
        /* A temporary password lands on the dedicated first-run screen
           instead of the app. index.php enforces the same rule. */
        'redirect'      => (int)$u['must_change_pw'] === 1 ? 'new-password.php' : '../index.php',
    ]);
}

/* ----------------------------------------------------------------
   MASTER RECOVERY (v2.4, item 15)
   ----------------------------------------------------------------
   Two public actions, both unauthenticated by necessity — the whole
   point is that the Master cannot sign in. They are safe because:

     - recover only ever works against a role='master' account,
     - the key is verified against a stored hash, never a raw bypass,
     - a key is single use and dies the moment it is spent,
     - the same username+IP throttle that guards login guards this,
       so keys cannot be brute-forced,
     - success does NOT sign anybody in. It sets a temporary password
       and forces the change-password screen, so possession of a key is
       worth exactly one password reset and nothing more.
   ---------------------------------------------------------------- */
case 'recovery_status': {
    /* Does this installation have recovery set up at all? The sign-in
       page uses it to decide whether to offer the link. It reveals
       nothing beyond "a master exists and holds >=1 unused key". */
    sr_methods(['GET', 'POST']);
    $q = db()->query("SELECT COUNT(*) FROM recovery_keys rk
                      JOIN users u ON u.id = rk.user_id
                      WHERE rk.used_at IS NULL AND u.role = 'master'
                        AND u.is_active = 1 AND u.deleted_at IS NULL");
    json_ok(['available' => (int)$q->fetchColumn() > 0]);
}

case 'recover': {
    sr_methods(['POST']);
    $username = input_str('username');
    $key      = (string)input('key', '');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($username === '' || trim($key) === '') {
        json_error('Enter the Master username and the recovery key.', 422, 'validation');
    }

    /* Same throttle as login, keyed the same way, checked before the
       attempt — otherwise this endpoint would be a way around it. */
    $th = db()->prepare("SELECT COUNT(*) FROM login_attempts
                         WHERE username = ? AND ip_address = ? AND success = 0
                           AND created_at > (NOW() - INTERVAL ? SECOND)");
    $th->execute([$username, $ip, LOGIN_LOCKOUT_SECONDS]);
    if ((int)$th->fetchColumn() >= LOGIN_MAX_ATTEMPTS) {
        audit('auth.recovery_locked', 'user', null, "Recovery lockout for $username");
        json_error('Too many failed attempts. Please wait 15 minutes and try again.', 429, 'locked_out');
    }

    $st = db()->prepare("SELECT * FROM users
                         WHERE username = ? AND role = 'master'
                           AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();

    /* Spend the key only when the account is a real, active Master.
       A wrong username and a wrong key give the identical reply so the
       endpoint cannot be used to discover the Master's username. */
    $row = $u ? sr_recovery_key_spend((int)$u['id'], $key, $ip) : null;

    db()->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?,?,?)")
        ->execute([$username, $ip, $row ? 1 : 0]);

    if (!$row) {
        audit('auth.recovery_failed', 'user', $u['id'] ?? null, "Failed recovery for $username");
        json_error('That recovery key is not valid.', 401, 'bad_key');
    }

    /* Issue a temporary password and force the change screen. */
    $tmp = sr_temp_password();
    db()->prepare("UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?")
        ->execute([password_hash($tmp, PASSWORD_DEFAULT), $u['id']]);
    db()->prepare("DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND success = 0")
        ->execute([$username, $ip]);

    audit('auth.recovered', 'user', $u['id'], "Master $username recovered with a recovery key",
          ['key_id' => (int)$row['id']]);

    $left = sr_recovery_key_count((int)$u['id']);
    json_ok([
        'recovered'      => true,
        'username'       => $u['username'],
        'temp_password'  => $tmp,
        'keys_remaining' => $left,
    ], ['message' => 'Recovery accepted. Sign in with the temporary password below, then choose a new one.']);
}

/* ---------------------------------------------------------------- */
case 'logout': {
    sr_methods(['POST']);
    $u = current_user();
    if ($u) audit('auth.logout', 'user', $u['id'], $u['username'] . ' signed out');
    sr_logout();
    json_ok(['redirect' => 'auth/login.php']);
}

/* ---------------------------------------------------------------- */
case 'change_password': {
    sr_methods(['POST']);
    $u = require_login();
    $cur = (string)input('current_password', '');
    $new = (string)input('new_password', '');
    $rep = (string)input('confirm_password', '');

    /* current_user() intentionally never selects the hash — fetch it here. */
    $hs = db()->prepare("SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL");
    $hs->execute([$u['id']]);
    $hash = (string)$hs->fetchColumn();

    /* First-run flow: the user arrived with a temporary password issued
       by an admin and is on new-password.php. They have already proved
       who they are by signing in, so only the new pair is required. */
    $firstRun = (int)($u['must_change_pw'] ?? 0) === 1;

    if (!$firstRun || $cur !== '') {
        if ($hash === '' || !password_verify($cur, $hash)) {
            audit('auth.password_change_failed', 'user', $u['id'], 'Wrong current password');
            json_error('Your current password is not correct.', 401, 'bad_credentials');
        }
    }
    if (mb_strlen($new) < 8)  json_error('The new password must be at least 8 characters.', 422, 'validation');
    if ($new !== $rep)        json_error('The two new passwords do not match.', 422, 'validation');
    if ($hash !== '' && password_verify($new, $hash)) {
        json_error('The new password must be different from the current one.', 422, 'validation');
    }

    $st = db()->prepare("UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE id = ?");
    $st->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);

    audit('auth.password_changed', 'user', $u['id'],
          $u['username'] . ($firstRun ? ' set their first password' : ' changed their own password'));
    json_ok(['changed' => true, 'first_run' => $firstRun, 'redirect' => $firstRun ? '../index.php' : null]);
}

/* ---------------------------------------------------------------- */
case 'update_profile': {
    sr_methods(['POST']);
    $u = require_login();
    $name  = v_len(v_required(input_str('full_name'), 'Full name'), 'Full name', 160);
    $phone = input_str('phone');
    $lang  = v_in(input_str('lang', $u['lang']) ?: 'am', ['am', 'en'], 'Language');
    $nums  = v_in(input_str('numerals', $u['numerals'] ?? 'mix') ?: 'mix', ['geez','arabic','mix'], 'Numerals');
    $email = input_str('email');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('That email address is not valid.', 422, 'validation');
    }
    $st = db()->prepare("UPDATE users SET full_name = ?, phone = ?, lang = ?, numerals = ?, email = ? WHERE id = ?");
    $st->execute([$name, $phone ?: null, $lang, $nums, $email ?: null, $u['id']]);
    audit('user.profile_updated', 'user', $u['id'], 'Profile updated');
    json_ok(['user' => sr_public_user(array_merge($u,
        ['full_name' => $name, 'phone' => $phone, 'lang' => $lang, 'numerals' => $nums, 'email' => $email]))]);
}

default:
    json_error('Unknown auth action: ' . $action, 404, 'unknown_action');
}

/** Strip anything sensitive before a user object crosses the wire. */
function sr_public_user(array $u): array
{
    return [
        'id'        => (int)$u['id'],
        'username'  => $u['username'],
        'full_name' => $u['full_name'],
        'email'     => $u['email'] ?? null,
        'phone'     => $u['phone'] ?? null,
        'role'      => $u['role'],
        'lang'      => $u['lang'] ?? 'am',
        'numerals'  => $u['numerals'] ?? 'mix',
        'must_change_pw' => (bool)($u['must_change_pw'] ?? false),
        'last_login_at' => $u['last_login_at'] ?? null,
    ];
}
