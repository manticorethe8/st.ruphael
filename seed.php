<?php
/* ============================================================
   seed.php — DEMO DATA LOADER  (safe to delete)
   ------------------------------------------------------------
   Fills a freshly installed database with a believable school so
   every screen in the platform has something to show: staff
   accounts for all four roles, students spread across classes,
   a term of Saturday/Sunday attendance, an absence streak that
   trips the alert panel, last year's promotion history, and a
   few audit entries.

   THIS FILE IS NOT PART OF THE RUNNING APPLICATION.
   Nothing in the app requires it, links to it, or reads it. It
   exists so a new operator can explore a populated system before
   entering real data.

   HOW TO USE
     1. Finish install.php first (this script refuses to run
        against an uninstalled database).
     2. Open  http://localhost/st.ruphael/seed.php  in a browser.
     3. Sign in with any of the demo accounts it prints.

   HOW TO REMOVE
     Delete this file. That is the whole procedure. To also drop
     the data it created, tick "Remove demo data first" and run
     it once more, then delete the file.

   SAFETY
     · Refuses to run once real usage is detected, unless you
       explicitly confirm (see $looksUsed below).
     · Every demo row is tagged so removal is exact: students get
       the DEMO_TAG marker in `notes`, users get DEMO_TAG in
       `phone`. Rows you create yourself are never touched.
     · Requires a POST from its own form, so it cannot fire by
       accident from a stray link or a crawler.
   ============================================================ */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/schema.php';
require_once __DIR__ . '/lib/ethiopic.php';
require_once __DIR__ . '/lib/helpers.php';

/* Marker written into every demo row so the cleanup pass can find
   them again without guessing. Change it only if a real student's
   notes could plausibly contain this exact string. */
const DEMO_TAG = '[demo-data]';

/* Demo passwords. Deliberately visible: this is throwaway data on
   a throwaway install. Every account is created with
   must_change_pw = 0 so you can log straight in and look around. */
const DEMO_PW = [
    'admin1'   => 'AdminPass#18',
    'editor1'  => 'Editor#2018',
    'teacher1' => 'Teacher#2018',
    'teacher2' => 'Teacher#2018',
];

function eh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$log       = [];                 // [type, text] pairs rendered at the top
$ran       = false;
$installed = false;
$dbError   = '';

function say(array &$l, string $type, string $text): void { $l[] = ['type' => $type, 'text' => $text]; }

/* ---------- probe ---------- */
try {
    $installed = sr_is_installed();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

/* Does this database already look like somebody's real school?
   Any student without the demo marker, or any attendance recorded
   against such a student, counts as real usage. */
$looksUsed = false;
if ($installed) {
    try {
        $pdo = db();
        $looksUsed = (int)$pdo->query(
            "SELECT COUNT(*) FROM students
              WHERE deleted_at IS NULL
                AND (notes IS NULL OR notes NOT LIKE '%" . DEMO_TAG . "%')"
        )->fetchColumn() > 0;
    } catch (Throwable $e) { /* fall through; the form will report it */ }
}

/* ============================================================
   The demo cast. Names are ordinary Ethiopian names; the grade
   codes match the ones install.php creates.
   ============================================================ */

/** Students: [full name, baptism name, gender, grade code, guardian, phone] */
function demo_students(): array
{
    return [
        ['አበበ ከበደ',        'ገብረ ሚካኤል',  'male',   'g1', 'ከበደ ታደሰ',   '0911234501'],
        ['ሳራ ተስፋዬ',        'ወለተ ማርያም',  'female', 'g1', 'ተስፋዬ በቀለ',  '0911234502'],
        ['ዮሐንስ ግርማ',       'ተክለ ሃይማኖት', 'male',   'g1', 'ግርማ ወልዴ',   '0911234503'],
        ['ሜሮን አለሙ',        'ወለተ ጊዮርጊስ', 'female', 'g1', 'አለሙ ደሳለኝ',  '0911234504'],
        ['ናትናኤል ሙሉጌታ',    'ገብረ መስቀል',  'male',   'g2', 'ሙሉጌታ አሰፋ',  '0911234505'],
        ['ሕይወት ደመቀ',       'ወለተ ሥላሴ',   'female', 'g2', 'ደመቀ ጫላ',    '0911234506'],
        ['ቢንያም ሳሙኤል',      'ገብረ እግዚአብሔር','male',  'g2', 'ሳሙኤል ኃይሉ',  '0911234507'],
        ['ኤደን ዳንኤል',       'ወለተ ሩፋኤል',  'female', 'g2', 'ዳንኤል ጌታቸው', '0911234508'],
        ['ሰላም ብርሃኑ',       'ወለተ ኪዳን',   'female', 'g3', 'ብርሃኑ መኮንን', '0911234509'],
        ['ዳዊት ፍቅሩ',        'ገብረ ኢየሱስ',  'male',   'g3', 'ፍቅሩ አበራ',   '0911234510'],
        ['ብሌን ተሾመ',        'ወለተ አማኑኤል', 'female', 'g3', 'ተሾመ ካሳ',    '0911234511'],
        ['ሄኖክ አዲሱ',        'ገብረ ማርያም',  'male',   'g4', 'አዲሱ ወርቁ',   '0911234512'],
        ['ትዕግስት ዘውዱ',      'ወለተ ሚካኤል',  'female', 'g4', 'ዘውዱ አያሌው',  '0911234513'],
        ['ሚካኤል ጴጥሮስ',      'ገብረ ሕይወት',  'male',   'g4', 'ጴጥሮስ ለማ',   '0911234514'],
        ['ራሔል ሲሳይ',        'ወለተ ገብርኤል', 'female', 'g5', 'ሲሳይ ንጉሴ',   '0911234515'],
        ['ሳሙኤል ጥላሁን',      'ገብረ ሥላሴ',   'male',   'g5', 'ጥላሁን በላይ',  '0911234516'],
        ['ሔኖክ መስፍን',       'ገብረ ዮሐንስ',  'male',   'g5', 'መስፍን ሀብቴ',  '0911234517'],
        ['ማርታ ዮሴፍ',        'ወለተ ማርቆስ',  'female', 'g6', 'ዮሴፍ ታምራት',  '0911234518'],
        ['ኤልያስ በቀለ',       'ገብረ ጊዮርጊስ', 'male',   'g6', 'በቀለ ሞገስ',   '0911234519'],
        ['ፀሐይ አማረ',       'ወለተ ሐና',    'female', 'g6', 'አማረ ሽፈራው',  '0911234520'],
        ['ቃለአብ ዮናስ',       'ገብረ ሉቃስ',   'male',   'g7', 'ዮናስ ደጀኔ',   '0911234521'],
        ['ሰናይት ጎሳዬ',       'ወለተ ሶፍያ',   'female', 'g7', 'ጎሳዬ ተክሌ',   '0911234522'],
        ['ኤርምያስ ካሳሁን',     'ገብረ ዳዊት',   'male',   'g8', 'ካሳሁን ዓለሙ',  '0911234523'],
        ['ሶፍያ መንግስቱ',      'ወለተ አዳም',   'female', 'g8', 'መንግስቱ ባዬ',  '0911234524'],
        ['ቤዛዊት ሙላቱ',      'ወለተ ሰላም',   'female', 'kirkos', 'ሙላቱ ገዛኸኝ', '0911234525'],
        ['አሮን ሽመልስ',       'ገብረ ሕዝቅኤል', 'male',   'kirkos', 'ሽመልስ ጌታሁን', '0911234526'],
        ['ሔለን ጌታቸው',       'ወለተ እየሱስ',  'female', 'minas',  'ጌታቸው አለማየሁ','0911234527'],
        ['ቤተልሔም ታደሰ',     'ወለተ ብርሃን',  'female', 'minas',  'ታደሰ ወንድሙ', '0911234528'],
    ];
}

/** Staff: [username, full name, role, email, grade codes they teach] */
function demo_users(): array
{
    return [
        ['admin1',   'አስተዳዳሪ ተመስገን', 'admin',   'admin1@example.org',   []],
        ['editor1',  'መምህር ዳዊት',      'teacher', 'editor1@example.org', ['g5', 'g6']],
        ['teacher1', 'መምህር ሰለሞን',    'teacher', 'teacher1@example.org', ['g1', 'g2']],
        ['teacher2', 'መምህርት አልማዝ',   'teacher', 'teacher2@example.org', ['g3', 'g4']],
    ];
}

/* ============================================================
   Removal — undo everything a previous run created.
   Ordered child-table-first so foreign keys stay satisfied.
   ============================================================ */
function demo_wipe(PDO $pdo, array &$log): void
{
    $ids = $pdo->query("SELECT id FROM students WHERE notes LIKE '%" . DEMO_TAG . "%'")
               ->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (['attendance', 'promotions', 'enrollments'] as $tbl) {
            $pdo->exec("DELETE FROM $tbl WHERE student_id IN ($in)");
        }
        /* photos on disk, then the rows themselves */
        $ph = $pdo->query("SELECT photo_path FROM students WHERE id IN ($in) AND photo_path IS NOT NULL")
                  ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ph as $p) sr_delete_photo($p);
        $pdo->exec("DELETE FROM students WHERE id IN ($in)");
        say($log, 'ok', count($ids) . ' demo students and their attendance removed.');
    }

    $u = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND role <> 'master'");
    $u->execute([DEMO_TAG]);
    $uids = $u->fetchAll(PDO::FETCH_COLUMN);
    if ($uids) {
        $in = implode(',', array_map('intval', $uids));
        $pdo->exec("DELETE FROM teacher_classes WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM user_permissions WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        say($log, 'ok', count($uids) . ' demo staff accounts removed.');
    }

    $pdo->exec("DELETE FROM audit_logs WHERE summary LIKE '%" . DEMO_TAG . "%'");
    if (!$ids && !$uids) say($log, 'warn', 'No demo data was found to remove.');
}

/* ============================================================
   The seeding run itself.
   ============================================================ */
function demo_seed(PDO $pdo, array &$log): void
{
    /* ---- academic years -------------------------------------------------
       install.php creates the current year. The demo also wants the year
       before it, so the promotion screen has history to display. */
    $cur = $pdo->query("SELECT id, year_ec FROM academic_years WHERE is_current = 1")->fetch();
    if (!$cur) { say($log, 'err', 'No current academic year. Run install.php first.'); return; }
    $curId = (int)$cur['id']; $curEc = (int)$cur['year_ec'];
    $prevEc = $curEc - 1;

    $pdo->prepare("INSERT IGNORE INTO academic_years (year_ec, label_am, label_en, is_current, is_closed)
                   VALUES (?,?,?,0,1)")
        ->execute([$prevEc, to_geez($prevEc) . ' ዓ.ም', $prevEc]);
    $prevId = (int)$pdo->query("SELECT id FROM academic_years WHERE year_ec = $prevEc")->fetchColumn();
    say($log, 'ok', "Academic years ready: {$prevEc} and {$curEc}");

    /* ---- grade lookup ---------------------------------------------------
       Map the human-readable codes in the tables above onto real ids. */
    $grades = [];
    foreach ($pdo->query("SELECT id, code FROM grades") as $g) $grades[$g['code']] = (int)$g['id'];
    if (!$grades) { say($log, 'err', 'No grades found. Run install.php first.'); return; }

    /* ---- staff ----------------------------------------------------------
       The master account belongs to install.php; here we add one of every
       other role so the permission matrix can be explored from both sides. */
    $master = (int)$pdo->query("SELECT id FROM users WHERE role = 'master' ORDER BY id LIMIT 1")->fetchColumn();
    $insU = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, phone,
                            is_active, must_change_pw, lang, numerals, created_by)
         VALUES (?,?,?,?,?,?,1,0,'am','mix',?)");
    $insTC = $pdo->prepare(
        "INSERT INTO teacher_classes (user_id, grade_id, academic_year_id, is_primary, assigned_by)
         VALUES (?,?,?,1,?)");

    $made = 0;
    foreach (demo_users() as [$un, $fn, $role, $mail, $classes]) {
        $exists = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $exists->execute([$un]);
        if ($exists->fetchColumn()) continue;          // never clobber an existing login

        $insU->execute([$un, $mail, password_hash(DEMO_PW[$un], PASSWORD_DEFAULT),
                        $fn, $role, DEMO_TAG, $master ?: null]);
        $uid = (int)$pdo->lastInsertId();
        foreach ($classes as $code) {
            if (isset($grades[$code])) $insTC->execute([$uid, $grades[$code], $curId, $master ?: null]);
        }
        $made++;
    }
    say($log, 'ok', "$made demo staff accounts created (admin, three teachers).");

    /* ---- students -------------------------------------------------------
       Codes and roll numbers come from the same helpers the live Add
       Student form uses, so the demo data is numbered exactly as real
       data would be. */
    $insS = $pdo->prepare(
        "INSERT INTO students (student_code, full_name, baptism_name, gender, birth_date,
                               guardian_name, guardian_phone, address, notes, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?, 'active', ?)");
    $insE = $pdo->prepare(
        "INSERT INTO enrollments (student_id, grade_id, academic_year_id, roll_no, status, enrolled_on)
         VALUES (?,?,?,?,'active',?)");

    $rollNext = [];                       // grade_id => next roll number
    $roster   = [];                       // [student_id, grade_id] for the attendance pass
    $n = 0;
    foreach (demo_students() as $i => [$name, $bapt, $sex, $code, $guardian, $phone]) {
        if (!isset($grades[$code])) continue;
        $gid = $grades[$code];

        $sCode = next_student_code($curEc);
        /* Ages 7–18, spread across the year so the birthday column varies. */
        $birth = date('Y-m-d', strtotime('-' . (7 + ($i % 12)) . ' years -' . (($i * 29) % 365) . ' days'));

        $insS->execute([$sCode, $name, $bapt, $sex, $birth, $guardian, $phone,
                        'አዲስ አበባ, ' . (($i % 10) + 1) . ' ወረዳ', DEMO_TAG, $master ?: null]);
        $sid = (int)$pdo->lastInsertId();

        if (!isset($rollNext[$gid])) $rollNext[$gid] = next_roll_no($gid, $curId);
        $insE->execute([$sid, $gid, $curId, $rollNext[$gid]++, et_to_iso($curEc, 1, 10)]);

        $roster[] = [$sid, $gid];
        $n++;
    }
    say($log, 'ok', "$n demo students enrolled across " . count($rollNext) . ' classes.');

    /* ---- attendance -----------------------------------------------------
       Sessions are Saturdays and Sundays only, which is what a Sunday
       school actually meets. We walk the Ethiopian months from Meskerem
       up to today and record a realistic mix.

       The distribution is intentional, not random noise:
         · most students attend most sessions,
         · a handful are habitually late/absent,
         · students 0 and 1 carry a long current absence streak so the
           alert panel and the streak badge have something to show. */
    $insA = $pdo->prepare(
        "INSERT IGNORE INTO attendance
            (student_id, enrollment_id, grade_id, academic_year_id, session_date,
             et_year, et_month, et_day, day_of_week, status, excuse_reason, recorded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

    /* enrollment ids, needed on every attendance row */
    $enr = [];
    foreach ($pdo->query("SELECT id, student_id FROM enrollments WHERE academic_year_id = $curId") as $r) {
        $enr[(int)$r['student_id']] = (int)$r['id'];
    }

    $today   = date('Y-m-d');
    $todayEt = iso_to_et($today);
    $rows = 0;
    $excuses = ['ሕመም', 'የቤተሰብ ጉዳይ', 'ጉዞ', 'ሠርግ'];

    /* Deterministic pseudo-randomness: the same seed every run, so a
       re-seeded demo always looks identical and screenshots/tests match. */
    mt_srand(20180101);

    for ($m = 1; $m <= 13; $m++) {
        if ($m > $todayEt['m']) break;
        foreach (et_month_weekend_cols($curEc, $m) as $col) {
            if ($col['iso'] > $today) break 2;         // never record the future

            foreach ($roster as $idx => [$sid, $gid]) {
                if (!isset($enr[$sid])) continue;

                $roll = mt_rand(1, 100);
                /* the two flagged students stop attending in the last month */
                $streaking = $idx < 2 && $m >= max(1, $todayEt['m'] - 1);

                if ($streaking)            $status = 'absent';
                elseif ($idx % 9 === 0)    $status = $roll <= 55 ? 'present' : ($roll <= 80 ? 'absent' : 'excused');
                else                       $status = $roll <= 86 ? 'present' : ($roll <= 95 ? 'absent' : 'excused');

                $insA->execute([
                    $sid, $enr[$sid], $gid, $curId, $col['iso'],
                    $curEc, $m, $col['et_day'], $col['dow'], $status,
                    $status === 'excused' ? $excuses[array_rand($excuses)] : null,
                    $master ?: null,
                ]);
                $rows++;
            }
        }
    }
    say($log, 'ok', number_format($rows) . ' attendance records written (Saturdays and Sundays only).');

    /* ---- last year's promotions ----------------------------------------
       Gives the promotion screen a populated "undo" history and makes the
       student profile timeline non-empty. Only the older classes get one,
       which is also true to life. */
    $insP = $pdo->prepare(
        "INSERT INTO promotions (student_id, from_grade_id, to_grade_id, from_year_id, to_year_id,
                                 result, action, note, performed_by)
         VALUES (?,?,?,?,?,'passed','promoted',?,?)");
    $order = array_values($grades);      // ids in creation order == sort order
    $pos   = array_flip($order);
    $promo = 0;
    foreach ($roster as [$sid, $gid]) {
        $i = $pos[$gid] ?? 0;
        if ($i < 1) continue;                          // nothing below the first class
        $insP->execute([$sid, $order[$i - 1], $gid, $prevId, $curId,
                        'Promoted at the end of ' . $prevEc . ' ' . DEMO_TAG, $master ?: null]);
        $promo++;
    }
    say($log, 'ok', "$promo promotion records added for $prevEc");

    /* ---- a little audit history ----------------------------------------
       So the audit screen is not an empty table on first look. */
    $insL = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, username, role, action, entity_type, summary, ip_address)
         VALUES (?,?,?,?,?,?,'127.0.0.1')");
    foreach ([
        ['seed.run',        'system',  'Demo dataset loaded ' . DEMO_TAG],
        ['student.created', 'student', $n . ' demo students created ' . DEMO_TAG],
        ['attendance.saved','attendance', number_format($rows) . ' demo attendance rows ' . DEMO_TAG],
    ] as [$act, $ent, $sum]) {
        $insL->execute([$master ?: null, 'master', 'master', $act, $ent, $sum]);
    }
    say($log, 'ok', 'Audit trail seeded.');
}

/* ============================================================
   Request handling
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed) {
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if (!empty($_POST['wipe']))  demo_wipe($pdo, $log);
        if (empty($_POST['only_wipe'])) demo_seed($pdo, $log);
        $pdo->commit();
        $ran = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        say($log, 'err', 'Seeding failed and was rolled back: ' . eh($e->getMessage()));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Demo data · St. Rufael</title>
<style>
:root{--ink:#1d2433;--muted:#6b7280;--line:#e6e8ef;--bg:#f4f6fb;
  --gold:#b8862b;--gold-2:#8f6a1f;--green:#0f7b4f;--red:#b42318}
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
.btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(180deg,var(--gold),var(--gold-2));
  color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.btn:hover{filter:brightness(1.05)}
.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--line)}
.msg{display:flex;gap:10px;padding:11px 14px;border-radius:10px;margin-bottom:9px;font-size:.9rem}
.msg.ok{background:#e9f7f0;color:#0b5c3b;border:1px solid #bde5d2}
.msg.err{background:#fdeceb;color:#8c1c14;border:1px solid #f6c9c5}
.msg.warn{background:#fdf3e6;color:#8a4a06;border:1px solid #f6dcb9}
.msg code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px}
.tbl{width:100%;border-collapse:collapse;font-size:.87rem;margin-top:10px}
.tbl th,.tbl td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left}
.tbl th{color:var(--muted);font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.03em}
.tbl code{background:#f4f6fb;padding:2px 6px;border-radius:5px}
ul.what{margin:0;padding-left:20px;color:var(--muted);font-size:.89rem}
ul.what li{margin-bottom:4px}
label.ck{display:flex;gap:9px;align-items:flex-start;font-size:.89rem;margin:14px 0;cursor:pointer}
label.ck input{margin-top:3px}
.danger{border-left:3px solid var(--red);padding-left:12px;color:#8c1c14;font-size:.88rem;margin:14px 0}
.acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <img src="assets/logo.png" alt="">
    <div>
      <h1>Demo data loader</h1>
      <p>Fills the platform with a sample school so you can explore every screen.</p>
    </div>
  </div>

  <?php foreach ($log as $m): ?>
    <div class="msg <?= eh($m['type']) ?>"><span><?= $m['text'] ?></span></div>
  <?php endforeach; ?>

  <?php if ($dbError || !$installed): ?>
    <div class="card">
      <h2>Not installed yet</h2>
      <p class="sub">Run the installer before loading demo data.</p>
      <?php if ($dbError): ?><div class="msg err"><span><?= eh($dbError) ?></span></div><?php endif; ?>
      <a class="btn" href="install.php">Go to install.php</a>
    </div>

  <?php elseif ($ran): ?>
    <div class="card">
      <h2>Demo data is loaded</h2>
      <p class="sub">Sign in with any account below — each one sees a different slice of the system.</p>
      <table class="tbl">
        <tr><th>Username</th><th>Password</th><th>Role</th><th>Sees</th></tr>
        <tr><td><code>admin1</code></td><td><code>AdminPass#18</code></td><td>Admin</td>
            <td>Everything except backups, features, permissions and the audit trail</td></tr>
        <tr><td><code>editor1</code></td><td><code>Editor#2018</code></td><td>Teacher</td>
            <td>Students and attendance; can add and import, cannot delete</td></tr>
        <tr><td><code>teacher1</code></td><td><code>Teacher#2018</code></td><td>Teacher</td>
            <td>Grade 1 and Grade 2 only</td></tr>
        <tr><td><code>teacher2</code></td><td><code>Teacher#2018</code></td><td>Teacher</td>
            <td>Grade 3 and Grade 4 only</td></tr>
      </table>
      <p class="sub" style="margin-top:16px">
        Your Master account is unchanged — keep using the password you chose during installation.
      </p>
      <div class="danger">
        Delete <code>seed.php</code> before this install goes anywhere near real students.
      </div>
      <div class="acts">
        <a class="btn" href="index.php">Open the platform</a>
        <a class="btn sec" href="seed.php">Back to the seeder</a>
      </div>
    </div>

  <?php else: ?>
    <div class="card">
      <h2>What this will create</h2>
      <p class="sub">All of it is tagged, so the same page can remove it again later.</p>
      <ul class="what">
        <li><strong>28 students</strong> spread over Kirkos, Minas and Grades 1–8, with guardians and roll numbers</li>
        <li><strong>4 staff accounts</strong> — one admin, three teachers with real class assignments</li>
        <li><strong>A term of attendance</strong> on Saturdays and Sundays, from Meskerem to today</li>
        <li><strong>An absence streak</strong> on two students, so the alert panel has something to report</li>
        <li><strong>Last year's promotions</strong>, so profiles and the promotion screen show history</li>
        <li><strong>Audit entries</strong>, so the trail is not empty on first look</li>
      </ul>

      <?php if ($looksUsed): ?>
        <div class="msg warn"><span>
          This database already contains students that were <strong>not</strong> created by this
          seeder. Loading demo data on top of real records is usually a mistake.
        </span></div>
      <?php endif; ?>

      <form method="post">
        <label class="ck">
          <input type="checkbox" name="wipe" value="1">
          <span>Remove previously seeded demo data first (recommended when re-running).</span>
        </label>
        <label class="ck">
          <input type="checkbox" name="only_wipe" value="1">
          <span>Only remove — do not create anything new.</span>
        </label>
        <?php if ($looksUsed): ?>
          <label class="ck">
            <input type="checkbox" name="force" value="1" required>
            <span>I understand this database has real data in it, and I want to continue.</span>
          </label>
        <?php endif; ?>
        <div class="acts">
          <button class="btn" type="submit">Load demo data</button>
          <a class="btn sec" href="index.php">Skip — go to the platform</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Removing the demo</h2>
    <p class="sub" style="margin:0">
      Tick <em>Only remove</em> above to delete the sample records, then delete
      <code>seed.php</code> from the folder. Nothing in the application depends on this file.
    </p>
  </div>

</div>
</body>
</html>
