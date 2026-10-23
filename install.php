<?php
/* ============================================================
   install.php — creates the database, the schema, the base
   grade/academic-year/settings rows and the initial Master
   account. Safe to re-run: everything is idempotent and it
   refuses to silently overwrite an existing Master.
   ============================================================ */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/schema.php';
require_once __DIR__ . '/lib/ethiopic.php';
/* Needed for the Master recovery key minted in step 8. helpers.php only
   declares functions, so including it here has no side effects. */
require_once __DIR__ . '/lib/helpers.php';

function e_html($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$messages = [];   // [type, text]
$done     = false;
$alreadyInstalled = false;

function msg(array &$m, string $type, string $text): void { $m[] = ['type' => $type, 'text' => $text]; }

/* ------------ probe current state ------------ */
$dbReachable = false; $probeError = '';
try { db(false); $dbReachable = true; }
catch (Throwable $e) { $probeError = $e->getMessage(); }

if ($dbReachable) {
    try { $alreadyInstalled = sr_is_installed(); } catch (Throwable $e) { $alreadyInstalled = false; }
}

/* ------------ handle submit ------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReachable) {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $pw       = (string)($_POST['password'] ?? '');
    $pw2      = (string)($_POST['password2'] ?? '');
    $yearEc   = (int)($_POST['year_ec'] ?? et_today()['y']);
    $schoolAm = trim($_POST['school_am'] ?? APP_NAME_AM);
    $schoolEn = trim($_POST['school_en'] ?? APP_NAME_EN);

    $errors = [];
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        $errors[] = 'Username must be 3–64 characters (letters, digits, dot, underscore, hyphen).';
    }
    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if (mb_strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';
    if ($yearEc < 1900 || $yearEc > 2200) $errors[] = 'Academic year looks wrong.';

    if ($errors) {
        foreach ($errors as $er) msg($messages, 'err', $er);
    } else {
        try {
            /* 1. database */
            $root = db(false);
            $root->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            msg($messages, 'ok', 'Database <code>' . htmlspecialchars(DB_NAME) . '</code> is ready.');

            $pdo = db();

            /* 2. schema */
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            foreach (sr_schema_statements() as $table => $sql) {
                $pdo->exec($sql);
            }
            msg($messages, 'ok', 'Created / verified ' . count(sr_schema_statements()) . ' tables.');

            /* 3. grades (structure, not sample data) */
            $ins = $pdo->prepare("INSERT INTO grades (code, name_am, name_en, badge_am, badge_en, saint_am, saint_en, tone, level_no, sort_order)
                                  VALUES (?,?,?,?,?,?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE name_am=VALUES(name_am), name_en=VALUES(name_en),
                                    badge_am=VALUES(badge_am), badge_en=VALUES(badge_en),
                                    saint_am=VALUES(saint_am), saint_en=VALUES(saint_en),
                                    tone=VALUES(tone), level_no=VALUES(level_no), sort_order=VALUES(sort_order)");
            foreach (sr_default_grades() as $g) $ins->execute($g);

            /* promotion chain: kirkos → minas → g1 → g2 … → g12 */
            $codes = array_column(sr_default_grades(), 0);
            $idBy  = [];
            foreach ($pdo->query("SELECT id, code FROM grades")->fetchAll() as $r) $idBy[$r['code']] = (int)$r['id'];
            $upd = $pdo->prepare("UPDATE grades SET next_grade_id = ? WHERE code = ?");
            for ($i = 0; $i < count($codes) - 1; $i++) {
                if (isset($idBy[$codes[$i]], $idBy[$codes[$i + 1]])) $upd->execute([$idBy[$codes[$i + 1]], $codes[$i]]);
            }
            msg($messages, 'ok', 'Grade structure installed (ቂርቆስ → ሚናስ → ክፍል ፩ … ፲፪) with the promotion chain.');

            /* 4. academic years — previous + current */
            $mk = $pdo->prepare("INSERT INTO academic_years (year_ec, label_am, label_en, is_current)
                                 VALUES (?,?,?,?)
                                 ON DUPLICATE KEY UPDATE label_am=VALUES(label_am), label_en=VALUES(label_en)");
            $mk->execute([$yearEc - 1, to_geez($yearEc - 1) . ' ዓ.ም', ($yearEc - 1), 0]);
            $mk->execute([$yearEc,     to_geez($yearEc) . ' ዓ.ም',     $yearEc,     1]);
            $pdo->prepare("UPDATE academic_years SET is_current = (year_ec = ?)")->execute([$yearEc]);
            msg($messages, 'ok', 'Academic years ' . ($yearEc - 1) . ' and ' . $yearEc . ' created (current: ' . $yearEc . ').');

            /* 5. settings */
            $sset = $pdo->prepare("INSERT INTO settings (skey, svalue, vtype, is_public) VALUES (?,?,?,?)
                                   ON DUPLICATE KEY UPDATE skey = skey");
            foreach (sr_default_settings() as $s) $sset->execute($s);
            $ov = $pdo->prepare("INSERT INTO settings (skey, svalue, vtype, is_public) VALUES (?,?,'string',1)
                                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
            $ov->execute(['school_name_am', $schoolAm]);
            $ov->execute(['school_name_en', $schoolEn]);
            $ov->execute(['current_year_ec', (string)$yearEc]);
            msg($messages, 'ok', 'Default settings written (alert threshold, school name, messages).');

            /* 6. master account */
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            $existing = $chk->fetchColumn();

            $hash = password_hash($pw, PASSWORD_DEFAULT);   // never stored in plain text
            if ($existing) {
                $up = $pdo->prepare("UPDATE users SET password_hash = ?, full_name = ?, email = ?, role='master',
                                     is_active = 1, deleted_at = NULL WHERE id = ?");
                $up->execute([$hash, $fullName, $email !== '' ? $email : null, $existing]);
                msg($messages, 'warn', 'An account named <code>' . htmlspecialchars($username) . '</code> already existed — it was updated and promoted to Master.');
            } else {
                $iu = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                                     VALUES (?,?,?,?,'master',1)");
                $iu->execute([$username, $email !== '' ? $email : null, $hash, $fullName]);
                msg($messages, 'ok', 'Master account <code>' . htmlspecialchars($username) . '</code> created with a <code>password_hash()</code> hash.');
            }

            /* 7. directories */
            foreach ([UPLOAD_DIR, EXPORT_DIR, BACKUP_DIR, UPLOAD_DIR . '/photos'] as $d) {
                if (!is_dir($d)) @mkdir($d, 0775, true);
            }
            @file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents(EXPORT_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents(UPLOAD_DIR . '/.htaccess', "php_flag engine off\nRequire all granted\n");
            msg($messages, 'ok', 'Storage folders prepared (uploads / exports / backups).');

            /* 8. first Master recovery key (v2.4 item 15).
               Minted here so the Master leaves the installer already
               holding a way back in. Shown once on the next screen and
               stored only as a hash — if it is lost, the Master makes a
               replacement from Settings while still signed in. */
            $recoveryKey = null;
            try {
                $mid = (int)$pdo->query("SELECT id FROM users WHERE username = "
                                        . $pdo->quote($username) . " LIMIT 1")->fetchColumn();
                if ($mid) {
                    $recoveryKey = sr_recovery_key_generate();
                    $pdo->prepare("INSERT INTO recovery_keys (user_id, key_hash, label, created_by)
                                   VALUES (?,?,?,?)")
                        ->execute([$mid, password_hash(sr_recovery_key_normalise($recoveryKey),
                                   PASSWORD_DEFAULT), 'Created during installation', $mid]);
                    msg($messages, 'ok', 'Master recovery key generated.');
                }
            } catch (Throwable $e) {
                /* Never fail an install over this — the Master can mint one
                   from Settings later. */
                msg($messages, 'warn', 'Could not generate a recovery key: '
                    . htmlspecialchars($e->getMessage()) . ' You can create one from Settings.');
            }

            /* 9. audit + lock */
            $al = $pdo->prepare("INSERT INTO audit_logs (username, role, action, entity_type, summary, ip_address)
                                 VALUES (?,'master','install.completed','system',?,?)");
            $al->execute([$username, 'Installation completed for ' . DB_NAME, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
            @file_put_contents(INSTALL_LOCK, json_encode([
                'installed_at' => date('c'), 'version' => APP_VERSION, 'db' => DB_NAME, 'year_ec' => $yearEc,
            ], JSON_PRETTY_PRINT));

            $done = true;
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            msg($messages, 'err', 'Installation failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
}

$defYear = et_today()['y'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · <?= e_html(APP_NAME_EN) ?></title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="css/fonts.min.css">
<style>
:root{
  --ink:#1d2433; --muted:#6b7280; --line:#e6e8ef; --bg:#f4f6fb;
  --gold:#b8862b; --gold-2:#8f6a1f; --green:#0f7b4f; --red:#b42318; --amber:#b25e09;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font-family:'Noto Sans Ethiopic',Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  line-height:1.55;padding:24px 16px 60px}
.wrap{max-width:820px;margin:0 auto}
.head{display:flex;align-items:center;gap:14px;margin-bottom:22px}
.head img{width:56px;height:56px;object-fit:contain}
.head h1{margin:0;font-size:1.35rem}
.head p{margin:2px 0 0;color:var(--muted);font-size:.9rem}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;margin-bottom:18px;
  box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -18px rgba(16,24,40,.35)}
.card h2{margin:0 0 4px;font-size:1.05rem}
.card .sub{color:var(--muted);font-size:.88rem;margin:0 0 18px}
label{display:block;font-weight:600;font-size:.85rem;margin:0 0 6px}
input[type=text],input[type=email],input[type=password],input[type=number]{
  width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:10px;font:inherit;background:#fcfcfd}
input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(184,134,43,.15)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{margin-bottom:14px}
.hint{color:var(--muted);font-size:.78rem;margin-top:5px}
.btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(180deg,var(--gold),var(--gold-2));
  color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer}
.btn:hover{filter:brightness(1.05)}
.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--line)}
.msg{display:flex;gap:10px;padding:11px 14px;border-radius:10px;margin-bottom:9px;font-size:.9rem;align-items:flex-start}
.msg.ok{background:#e9f7f0;color:#0b5c3b;border:1px solid #bde5d2}
.msg.err{background:#fdeceb;color:#8c1c14;border:1px solid #f6c9c5}
.msg.warn{background:#fdf3e6;color:#8a4a06;border:1px solid #f6dcb9}
.msg code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px}
.steps{margin:0;padding-left:20px;color:var(--muted);font-size:.88rem}
.steps li{margin-bottom:5px}
.done{text-align:center;padding:10px 0 4px}

.reckey{border:2px solid #c9a227;background:#fffdf5;border-radius:12px;padding:16px 18px;margin:18px 0}
.reckey-h{font-weight:700;color:#8a6d1f;margin-bottom:10px}
.reckey code{display:block;font:700 1.1rem/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;
  letter-spacing:2px;background:#fff;border:1px solid #e6dcc0;border-radius:8px;
  padding:12px 14px;margin-bottom:10px;user-select:all;text-align:center}
.reckey p{font-size:.86rem;color:#6b5a2c;line-height:1.6;margin:0}
.done .tick{width:58px;height:58px;border-radius:50%;background:#e9f7f0;color:var(--green);
  display:inline-flex;align-items:center;justify-content:center;font-size:1.9rem;margin-bottom:10px}
.tbl{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:8px}
.tbl td{padding:6px 8px;border-bottom:1px solid var(--line)}
.tbl td:first-child{color:var(--muted);width:42%}
@media(max-width:640px){.grid{grid-template-columns:1fr}}

</style></head>
<body>
<div class="wrap">

  <div class="head">
    <img src="assets/logo.png" alt="">
    <div>
      <h1><?= e_html(APP_NAME_AM) ?></h1>
      <p><?= e_html(APP_NAME_EN) ?> · Installation &amp; Setup (v<?= e_html(APP_VERSION) ?>)</p>
    </div>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="msg <?= $m['type'] ?>"><span><?= $m['text'] ?></span></div>
  <?php endforeach; ?>

  <?php if (!$dbReachable): ?>
    <div class="card">
      <h2>Cannot reach MySQL</h2>
      <p class="sub">Start MySQL in the XAMPP Control Panel, then reload this page.</p>
      <div class="msg err"><span><?= e_html($probeError) ?></span></div>
      <table class="tbl">
        <tr><td>Host</td><td><code><?= e_html(DB_HOST . ':' . DB_PORT) ?></code></td></tr>
        <tr><td>Database</td><td><code><?= e_html(DB_NAME) ?></code></td></tr>
        <tr><td>User</td><td><code><?= e_html(DB_USER) ?></code></td></tr>
      </table>
      <p class="hint">These values live in <code>config/config.php</code>. Edit them if your MySQL uses a different user or password.</p>
    </div>

  <?php elseif ($done): ?>
    <div class="card">
      <div class="done">
        <div class="tick">✓</div>
        <h2>Installation complete</h2>
        <p class="sub">The platform is connected to MySQL and ready to use.</p>
      </div>
      <?php if (!empty($recoveryKey)): ?>
        <div class="reckey">
          <div class="reckey-h">Master recovery key — copy this now</div>
          <code><?= htmlspecialchars($recoveryKey) ?></code>
          <p>This is the only time it will ever be shown. Print it or write it down and keep it
             somewhere safe. If the Master password is ever forgotten, this key is what gets you
             back in — it works exactly once, and you can create more from
             <strong>Settings → Recovery keys</strong>.</p>
        </div>
      <?php endif; ?>

      <ol class="steps">
        <li>Sign in at <a href="auth/login.php">auth/login.php</a> with the Master account you just created.</li>
        <li>Create your Admin and Teacher users under <strong>Users</strong>.</li>
        <li>Assign each teacher to their grade under <strong>Teachers → Assignments</strong>.</li>
        <li>Add students one at a time under <strong>Students → Add Student</strong>, or load a whole roster from Excel/CSV under <strong>Students → Import</strong>.</li>
        <li>Want to explore a fully populated system first? Run <code>seed.php</code> to load demo data, then delete that file.</li>
        <li><strong>Required on a production server:</strong> delete <code>install.php</code> (and <code>seed.php</code>) once setup is done.</li>
      </ol>
      <p style="margin-top:16px"><a class="btn" href="auth/login.php">Go to login →</a></p>
    </div>

  <?php else: ?>
    <?php if ($alreadyInstalled): ?>
      <div class="msg warn"><span>This platform is <strong>already installed</strong> — a Master account exists.
        Re-running the installer will re-verify the schema. It will only touch a user account if you enter that exact username.</span></div>
    <?php endif; ?>

    <form method="post" class="card" autocomplete="off">
      <h2>1 · Master account</h2>
      <p class="sub">The single highest-privilege account. The password is stored only as a <code>password_hash()</code> hash — never in plain text, and never sent to the browser.</p>

      <div class="grid">
        <div class="field">
          <label for="username">Master username *</label>
          <input id="username" name="username" type="text" required minlength="3" maxlength="64"
                 pattern="[A-Za-z0-9._\-]{3,64}" value="<?= e_html($_POST['username'] ?? 'master') ?>">
        </div>
        <div class="field">
          <label for="full_name">Full name *</label>
          <input id="full_name" name="full_name" type="text" required maxlength="160"
                 value="<?= e_html($_POST['full_name'] ?? '') ?>" placeholder="e.g. Memhir Tesfaye Kebede">
        </div>
      </div>

      <div class="field">
        <label for="email">Email (optional)</label>
        <input id="email" name="email" type="email" maxlength="190" value="<?= e_html($_POST['email'] ?? '') ?>">
      </div>

      <div class="grid">
        <div class="field">
          <label for="password">Password * (min 8 characters)</label>
          <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password2">Repeat password *</label>
          <input id="password2" name="password2" type="password" required minlength="8" autocomplete="new-password">
        </div>
      </div>

      <h2 style="margin-top:22px">2 · School &amp; academic year</h2>
      <p class="sub">The Ethiopian academic year. The previous year is created too, so promotion history has somewhere to live.</p>
      <div class="grid">
        <div class="field">
          <label for="school_am">School name (አማርኛ)</label>
          <input id="school_am" name="school_am" type="text" value="<?= e_html($_POST['school_am'] ?? APP_NAME_AM) ?>">
        </div>
        <div class="field">
          <label for="school_en">School name (English)</label>
          <input id="school_en" name="school_en" type="text" value="<?= e_html($_POST['school_en'] ?? APP_NAME_EN) ?>">
        </div>
      </div>
      <div class="field" style="max-width:260px">
        <label for="year_ec">Current academic year</label>
        <input id="year_ec" name="year_ec" type="number" min="1900" max="2200" value="<?= e_html($_POST['year_ec'] ?? $defYear) ?>">
        <p class="hint">Today is <?= e_html(et_label(date('Y-m-d'), 'en')) ?>.</p>
      </div>

      <h2 style="margin-top:22px">3 · What will be created</h2>
      <ul class="steps">
        <li>Database <code><?= e_html(DB_NAME) ?></code> (utf8mb4) if it does not exist</li>
        <li><?= count(sr_schema_statements()) ?> tables: users, grades, students, enrollments, attendance, teacher_classes, promotions, settings, audit_logs, alert_reads, login_attempts, import_batches, academic_years</li>
        <li>The 14 grades used by the existing UI, with the promotion chain</li>
        <li>Default settings — including the configurable absence-alert threshold</li>
        <li><strong>No sample or dummy students.</strong> The roster starts empty.</li>
      </ul>

      <p style="margin-top:20px"><button class="btn" type="submit">Install now</button></p>
    </form>
  <?php endif; ?>

  <p style="text-align:center;color:var(--muted);font-size:.8rem">
    <?= e_html(APP_NAME_EN) ?> · attendance platform v<?= e_html(APP_VERSION) ?>
  </p>
</div>
</body>
</html>
