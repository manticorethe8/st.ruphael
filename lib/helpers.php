<?php
/* ============================================================
   Shared request/response helpers, validation, settings access.
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ethiopic.php';

/* ------------------------- request parsing ------------------------- */
function sr_raw_json(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $body = file_get_contents('php://input');
        $d = json_decode($body ?: '[]', true);
        $cached = is_array($d) ? $d : [];
    } else {
        $cached = [];
    }
    return $cached;
}

function input(string $key, $default = null)
{
    $json = sr_raw_json();
    if (array_key_exists($key, $json)) return $json[$key];
    if (array_key_exists($key, $_POST)) return $_POST[$key];
    if (array_key_exists($key, $_GET))  return $_GET[$key];
    return $default;
}

function input_str(string $key, string $default = ''): string
{
    $v = input($key, $default);
    if (is_array($v)) return $default;
    return trim((string)$v);
}

function input_int(string $key, ?int $default = null): ?int
{
    $v = input($key, null);
    if ($v === null || $v === '' || is_array($v)) return $default;
    return (int)$v;
}

function input_bool(string $key, bool $default = false): bool
{
    $v = input($key, null);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
}

function input_array(string $key): array
{
    $v = input($key, []);
    return is_array($v) ? $v : [];
}

/* ------------------------- responses ------------------------- */
function json_out($data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: same-origin');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok($data = [], array $extra = []): void
{
    json_out(array_merge(['ok' => true, 'data' => $data], $extra));
}

function json_error(string $message, int $code = 400, string $type = 'error', array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => ['type' => $type, 'message' => $message]], $extra), $code);
}

/* ------------------------- validation ------------------------- */
function v_required(string $value, string $field): string
{
    $value = trim($value);
    if ($value === '') json_error("$field is required.", 422, 'validation');
    return $value;
}

function v_len(string $value, string $field, int $max, int $min = 0): string
{
    $l = mb_strlen($value);
    if ($l < $min) json_error("$field must be at least $min characters.", 422, 'validation');
    if ($l > $max) json_error("$field must be at most $max characters.", 422, 'validation');
    return $value;
}

function v_in($value, array $allowed, string $field)
{
    if (!in_array($value, $allowed, true)) {
        json_error("$field must be one of: " . implode(', ', $allowed) . '.', 422, 'validation');
    }
    return $value;
}

function v_iso_date(string $d, string $field = 'Date'): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) json_error("$field must be YYYY-MM-DD.", 422, 'validation');
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    if (!checkdate($m, $dd, $y)) json_error("$field is not a real date.", 422, 'validation');
    return $d;
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ------------------------- settings ------------------------- */
function settings_all(bool $publicOnly = false): array
{
    $sql = "SELECT skey, svalue, vtype, is_public FROM settings";
    if ($publicOnly) $sql .= " WHERE is_public = 1";
    $rows = db()->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['skey']] = setting_cast($r['svalue'], $r['vtype']);
    }
    return $out;
}

function setting_cast($v, string $type)
{
    return match ($type) {
        'int'  => (int)$v,
        'bool' => (bool)(int)$v,
        'json' => json_decode((string)$v, true),
        default => (string)$v,
    };
}

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) $cache = settings_all(false);
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setting_set(string $key, $value, string $type = 'string', ?int $userId = null): void
{
    if ($type === 'json') $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($type === 'bool') $value = $value ? '1' : '0';
    $st = db()->prepare("INSERT INTO settings (skey, svalue, vtype, updated_by)
                         VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), vtype = VALUES(vtype), updated_by = VALUES(updated_by)");
    $st->execute([$key, (string)$value, $type, $userId]);
}

/* ------------------------- academic years ------------------------- */
function current_year_row(): ?array
{
    sr_year_autoswitch();
    $r = db()->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
    if ($r) return $r;
    return db()->query("SELECT * FROM academic_years ORDER BY year_ec DESC LIMIT 1")->fetch() ?: null;
}

/**
 * Move to the next academic year when its official start date arrives.
 *
 * Promotion deliberately no longer changes the working year: a school
 * promotes students weeks before the new year actually begins, and the
 * registers must keep writing into the old year until then. Instead the
 * year changes in exactly two ways:
 *
 *   1. automatically, on the date stored in the `year_start_date` setting
 *      (an Ethiopian MM-DD, default Meskerem 1), and
 *   2. manually, via Settings -> "Start the new academic year", which
 *      calls settings.php?action=set_current_year.
 *
 * Runs at most once per request and only ever moves forward, so it can
 * never drag the school back into a closed year.
 */
function sr_year_autoswitch(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!setting('year_auto_switch', '1')) return;          // opt-out
    $md = (string)setting('year_start_date', '01-01');       // Ethiopian MM-DD
    if (!preg_match('/^(\d{2})-(\d{2})$/', $md, $m)) return;

    $cur = db()->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
    if (!$cur) return;

    $today   = et_today();
    $startM  = (int)$m[1];
    $startD  = (int)$m[2];
    $curEc   = (int)$cur['year_ec'];

    /* Has the start of the year AFTER the current one already passed? */
    $reached = $today['y'] > $curEc
        && ($today['y'] > $curEc + 1
            || $today['m'] > $startM
            || ($today['m'] === $startM && $today['d'] >= $startD));
    if (!$reached) return;

    /* Only switch into a year that actually exists and is not closed. */
    $nxt = db()->prepare("SELECT * FROM academic_years
                          WHERE year_ec > ? AND is_closed = 0
                          ORDER BY year_ec ASC LIMIT 1");
    $nxt->execute([$curEc]);
    $to = $nxt->fetch();
    if (!$to) return;

    db()->prepare("UPDATE academic_years SET is_current = (id = ?)")->execute([(int)$to['id']]);
    db()->prepare("INSERT INTO settings (skey, svalue, vtype, updated_by) VALUES ('current_year_ec', ?, 'string', NULL)
                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW()")
        ->execute([(string)$to['year_ec']]);

    if (function_exists('audit')) {
        audit('year.auto_started', 'academic_year', (int)$to['id'],
              "Academic year {$to['year_ec']} started automatically on its configured start date",
              ['from' => $curEc, 'to' => (int)$to['year_ec'], 'start' => $md]);
    }
}

function year_by_id(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM academic_years WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function year_by_ec(int $ec): ?array
{
    $st = db()->prepare("SELECT * FROM academic_years WHERE year_ec = ?");
    $st->execute([$ec]);
    return $st->fetch() ?: null;
}

/** Create an academic year if missing; returns the row. */
function ensure_year(int $ec): array
{
    $r = year_by_ec($ec);
    if ($r) return $r;
    $st = db()->prepare("INSERT INTO academic_years (year_ec, label_am, label_en) VALUES (?,?,?)");
    $st->execute([$ec, to_geez($ec) . ' ዓ.ም', $ec]);
    return year_by_ec($ec);
}

/** Resolve the year the request is talking about (id → ec → current). */
function resolve_year(): array
{
    $id = input_int('academic_year_id');
    if ($id) { $r = year_by_id($id); if ($r) return $r; }
    $ec = input_int('year_ec');
    if ($ec) { $r = year_by_ec($ec); if ($r) return $r; }
    $r = current_year_row();
    if (!$r) json_error('No academic year is configured. Run install.php.', 500, 'no_year');
    return $r;
}

/* ------------------------- misc ------------------------- */
function sr_uuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function sr_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
}

/* ====================================================================
   AUTOMATIC IDENTIFIERS
   --------------------------------------------------------------------
   Student IDs and roll numbers are generated by the SERVER, never typed
   by a user. Two people adding students at the same time on different
   computers used to invent the same ID; now the database decides and
   collisions are impossible.

   The registrar sees both fields on the form, greyed out and reading
   "auto", so it is obvious they exist and obvious they are not editable.

   TO ALLOW MANUAL ENTRY AGAIN: flip SR_MANUAL_IDENTIFIERS to true in
   config/config.php. That single switch re-enables the text inputs in
   the browser AND makes the API honour a submitted value (falling back
   to auto-generation when the box is left empty). Nothing else to change.
   ==================================================================== */

/* ====================================================================
   STUDENT ID FORMAT — defined here and nowhere else.

   A code looks like  18-0042
                      ^^ ^^^^
                      |  \___ sequence, 4 digits, restarts every year
                      \______ Ethiopian year, last 2 digits (2018 -> 18)

   Short enough to write on a form by hand or read down a phone, and it
   still sorts correctly: because both parts are zero-padded and fixed
   width, a plain alphabetical sort is also chronological order.

   TO CHANGE THE FORMAT edit sr_code_prefix() and SR_CODE_SEQ_WIDTH
   below. Everything else — generation, import repair, validation and
   the sample spreadsheet — is derived from these two, so no other file
   needs touching.
   ==================================================================== */

/** Digits in the running sequence (4 -> 0042, allows 9,999 per year). */
const SR_CODE_SEQ_WIDTH = 4;

/** The fixed part of a code for a year, including the separator. */
function sr_code_prefix(int $yearEc): string
{
    return substr((string)$yearEc, -2) . '-';        /* 2018 -> "18-" */
}

/** True when $code is a well-formed auto-generated code for ANY year. */
function sr_is_auto_code(string $code): bool
{
    return (bool)preg_match('/^\d{2}-\d{' . SR_CODE_SEQ_WIDTH . '}$/', $code);
}

/**
 * Next free student code for an academic year, e.g. 18-0042.
 *
 * Skips numbers that are already taken rather than trusting MAX(), because
 * a spreadsheet import may have parked codes in the middle of the range.
 */
function next_student_code(int $yearEc): string
{
    $prefix = sr_code_prefix($yearEc);
    $st = db()->prepare("SELECT student_code FROM students
                          WHERE student_code LIKE ?
                       ORDER BY student_code DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $n = $last ? ((int)substr((string)$last, strlen($prefix))) + 1 : 1;

    /* Deleted students keep their codes (soft delete), so a code freed by
       a deletion is deliberately NOT reused — history stays unambiguous. */
    while (true) {
        $code = $prefix . str_pad((string)$n, SR_CODE_SEQ_WIDTH, '0', STR_PAD_LEFT);
        $c = db()->prepare("SELECT COUNT(*) FROM students WHERE student_code = ?");
        $c->execute([$code]);
        if ((int)$c->fetchColumn() === 0) return $code;
        $n++;
    }
}

/**
 * Next roll number within one grade for one academic year.
 *
 * Roll numbers are per-class, not global: every grade starts again at 1,
 * which is how the register books have always worked. A student who
 * moves to another grade mid-year is given a fresh roll number there.
 *
 * @param int $gradeId  grades.id
 * @param int $yearId   academic_years.id (the row id, NOT the EC year)
 */
function next_roll_no(int $gradeId, int $yearId): int
{
    $st = db()->prepare("SELECT COALESCE(MAX(roll_no), 0) + 1
                           FROM enrollments
                          WHERE grade_id = ? AND academic_year_id = ?");
    $st->execute([$gradeId, $yearId]);
    return max(1, (int)$st->fetchColumn());
}

/* ====================================================================
   STUDENT PHOTOS
   --------------------------------------------------------------------
   Photos arrive as a base64 data URL, not as a multipart file upload.
   Reason: this server has no `gd` extension, so PHP cannot resize an
   image. Instead the browser draws the chosen picture onto a <canvas>,
   scales it to at most 600x600 and re-encodes it as JPEG before sending
   (see photoPick() in js/admin.js). A 4 MB phone photo becomes ~60 KB,
   which keeps uploads fast on a slow connection and keeps the folder
   small enough to sit inside a normal backup.

   The server still validates everything — a browser can be bypassed:
     · the string must really be a JPEG/PNG/WebP data URL
     · the decoded bytes must be under SR_PHOTO_MAX_BYTES
     · getimagesizefromstring() must agree it is a real image
       (this rejects a PHP script renamed .jpg)
     · the filename is generated here, never taken from the client
   Files land in uploads/photos/, where uploads/.htaccess has already
   switched the PHP engine off, so even a crafted file cannot execute.
   ==================================================================== */

/** Image types we accept, mapped to the extension we store them under. */
const SR_PHOTO_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/**
 * Decode + validate a data-URL photo and write it into uploads/photos/.
 *
 * @param  string $dataUrl  "data:image/jpeg;base64,...."
 * @param  int    $studentId  used only to make the filename readable
 * @return string  path relative to the app root, e.g. "uploads/photos/s12-a1b2c3.jpg"
 * @throws never   calls json_error() and exits on any problem
 */
function sr_save_photo(string $dataUrl, int $studentId): string
{
    if (!preg_match('~^data:([a-z/+-]+);base64,(.+)$~is', trim($dataUrl), $m)) {
        json_error('That photo could not be read. Please choose the picture again.', 422, 'photo');
    }
    $mime = strtolower($m[1]);
    if (!isset(SR_PHOTO_TYPES[$mime])) {
        json_error('Photos must be a JPG, PNG or WebP image.', 422, 'photo');
    }

    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') {
        json_error('That photo could not be read. Please choose the picture again.', 422, 'photo');
    }
    if (strlen($bin) > SR_PHOTO_MAX_BYTES) {
        json_error('That photo is too large. Please choose a smaller picture.', 422, 'photo');
    }

    /* Confirm the bytes really are an image of the type claimed — the
       cheapest defence against an executable uploaded under an image
       MIME type. */
    $info = @getimagesizefromstring($bin);
    if ($info === false || empty($info['mime']) || strtolower($info['mime']) !== $mime) {
        json_error('That file is not a valid image.', 422, 'photo');
    }

    $dir = UPLOAD_DIR . '/photos';
    sr_ensure_dir(UPLOAD_DIR);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    /* Random suffix, so replacing a photo produces a NEW url and no
       browser or service worker can show the previous one from cache. */
    $name = 's' . $studentId . '-' . bin2hex(random_bytes(4)) . '.' . SR_PHOTO_TYPES[$mime];
    if (@file_put_contents($dir . '/' . $name, $bin) === false) {
        json_error('The photo could not be saved. Check that uploads/photos is writable.', 500, 'photo');
    }
    return 'uploads/photos/' . $name;
}

/**
 * Delete a stored photo. Silently ignores anything that is missing or
 * that points outside uploads/photos/ — a stale row must never let a
 * caller delete arbitrary files.
 */
function sr_delete_photo(?string $rel): void
{
    if (!$rel) return;
    $rel = ltrim($rel, '/');
    if (!str_starts_with($rel, 'uploads/photos/')) return;
    if (str_contains($rel, '..')) return;
    $abs = SR_ROOT . '/' . $rel;
    if (is_file($abs)) @unlink($abs);
}

/** Global exception guard for API endpoints */
function sr_api_boot(): void
{
    set_exception_handler(function (Throwable $e) {
        error_log('[SR] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $debug = (bool)(getenv('SR_DEBUG') ?: 0);
        json_error($debug ? $e->getMessage() : 'A server error occurred.', 500, 'server');
    });
    set_error_handler(function ($no, $str, $file, $line) {
        if (!(error_reporting() & $no)) return false;
        throw new ErrorException($str, 0, $no, $file, $line);
    });
}

/**
 * Consecutive-absence streak per student, counting backwards from the most
 * recent recorded session. Used for the configurable red alert.
 *
 * Always computed live from the attendance table, never cached, so an
 * attendance correction moves the streak in both directions: changing an
 * absence to present/excused shortens or clears it, and changing a
 * present/excused day back to absent lengthens it again.
 */
function sr_absence_streaks(array $studentIds, int $yearId): array
{
    $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
    if (!$studentIds) return [];
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $st = db()->prepare("SELECT a.student_id, a.session_date, a.status FROM attendance a
                         JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                         WHERE a.academic_year_id = ? AND a.student_id IN ($in)
                         ORDER BY a.student_id, a.session_date DESC");
    $st->execute(array_merge([$yearId], $studentIds));

    $streaks = [];
    $stopped = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['student_id'];
        if (!empty($stopped[$sid])) continue;
        if ($r['status'] === 'absent') {
            $streaks[$sid] = ($streaks[$sid] ?? 0) + 1;
        } else {
            $stopped[$sid] = true;
            $streaks[$sid] = $streaks[$sid] ?? 0;
        }
    }
    return $streaks;
}

/**
 * Build a forgiving search condition.
 *
 * The plain "%q%" LIKE could not find a student from an abbreviation:
 * typing "a s" for "Aron Shinles" matched nothing, because no single
 * field contains the literal string "a s".
 *
 * The query is split on whitespace and EVERY token must match somewhere
 * in the row (AND across tokens, OR across fields). A token matches a
 * field when it appears at the start of the value or at the start of any
 * word inside it, and a token of two characters or more also matches
 * anywhere inside the value. So:
 *
 *   "a s"        -> word starting with "a"  AND word starting with "s"
 *   "aron shin"  -> the same, with longer prefixes
 *   "shinles a"  -> order does not matter
 *   "0042"       -> matches inside the student code
 *
 * Word-prefix matching is done with LIKE rather than a regular
 * expression so the comparison stays on the column collation and keeps
 * working for Amharic text.
 *
 * @param string   $q      raw user input
 * @param string[] $fields SQL column expressions to search
 * @return array{0:string,1:array} [sql, params]; sql is '' when there is nothing to match
 */
function sr_search_clause(string $q, array $fields): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q));
    if ($q === '' || !$fields) return ['', []];

    /* Cap the token count: a pasted paragraph should not build a
       thousand-term query. */
    $tokens = array_slice(array_filter(explode(' ', $q), 'strlen'), 0, 8);
    if (!$tokens) return ['', []];

    $andParts = [];
    $params   = [];

    foreach ($tokens as $tok) {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tok);
        $ors = [];
        foreach ($fields as $f) {
            /* starts with the token */
            $ors[] = "$f LIKE ?";           $params[] = $esc . '%';
            /* a later word starts with the token */
            $ors[] = "$f LIKE ?";           $params[] = '% ' . $esc . '%';
            /* one-letter tokens stay anchored to word starts, otherwise
               "a" would match nearly every row and rank nothing */
            if (mb_strlen($tok) >= 2) {
                $ors[] = "$f LIKE ?";       $params[] = '%' . $esc . '%';
            }
        }
        /* hyphenated identifiers: "18 0042" should still find 18-0042 */
        $andParts[] = '(' . implode(' OR ', $ors) . ')';
    }

    return ['(' . implode(' AND ', $andParts) . ')', $params];
}

/* ---------------------------------------------------------------------
   Brand name resolution (v2.4, item 11)
   ---------------------------------------------------------------------
   The school name is a proper noun, so by default it stays Amharic
   everywhere; the English field is only used on printed output when the
   toggle is off and the print language is English. The
   `brand_always_amharic` setting (default 1) controls it: ON = the
   Amharic name is shown on screen and in printed reports whatever the
   language; OFF = the name follows the interface language.

   Every surface that prints the school name must go through here, so
   the toggle cannot end up half-applied. The English name falls back to
   the Amharic one when it has been left blank, which keeps the header
   from rendering empty on a half-configured install.
   --------------------------------------------------------------------- */
/* The school-name policy. Settings → Identity → "Always use the Amharic
   name" (default ON): the school's name is a proper noun, so the header,
   the sign-in screen and printed reports keep the Amharic name whatever
   the interface language. Turned OFF, the name follows the interface
   language instead. */
function sr_brand_always_amharic(): bool
{
    return (bool)setting('brand_always_amharic', 1);
}

/* The name to display. $lang is the UI language; the toggle is the single
   authority: when it is ON the Amharic name wins everywhere, when it is
   OFF the name follows $lang. ID cards print both names by design and do
   not go through here. */
function sr_brand_name(string $lang = 'am'): string
{
    $am = trim((string)setting('school_name_am', APP_NAME_AM));
    $en = trim((string)setting('school_name_en', APP_NAME_EN));

    if (sr_brand_always_amharic()) return $am !== '' ? $am : $en;
    if ($lang === 'en')            return $en !== '' ? $en : $am;
    return $am !== '' ? $am : $en;
}

/* --------------------------------------------------------------------
 * MASTER VISIBILITY (v2.4, item 16)
 * --------------------------------------------------------------------
 * The Master is the account that can rewrite everyone else's
 * permissions, so lower roles should not be able to see it, enumerate
 * it, edit it, delete it or restore it. Previously only the *password
 * reset* and *edit* paths said so, which meant an admin could still
 * list the Master and soft-delete it whenever a second Master existed.
 *
 * These two helpers are the single place that answers "is the caller a
 * Master?" and "may the caller act on this row?", so every users.php
 * branch enforces the same rule.
 * ------------------------------------------------------------------ */

/** True when the signed-in user is a Master. */
function sr_is_master(): bool
{
    $u = current_user();
    return $u && ($u['role'] ?? '') === 'master';
}

/**
 * Guard a users.php action that targets one row.
 *
 * Non-Masters get "does not exist" rather than "forbidden" for Master
 * rows on purpose: a 403 would confirm the account is there, which is
 * exactly the enumeration we are trying to prevent.
 */
function sr_assert_user_target(array $target): void
{
    if (($target['role'] ?? '') === 'master' && !sr_is_master()) {
        json_error('That user does not exist.', 404, 'not_found');
    }
}

/* --------------------------------------------------------------------
 * MASTER RECOVERY KEYS (v2.4, item 15)
 * --------------------------------------------------------------------
 * The Master is the only account nobody else can reset, so without a
 * recovery path a forgotten Master password means editing the database
 * by hand. That is exactly the raw bypass we do not want, so recovery
 * works with a printed key instead:
 *
 *   - install.php mints the first key and shows it once.
 *   - A signed-in Master can mint replacements from Settings.
 *   - Only the HASH is stored, so the table is useless to a reader.
 *   - A key is single use, belongs to one Master account, and spending
 *     one forces the new-password screen on the next sign-in.
 *
 * The key is deliberately readable over the phone: five groups of four
 * from Crockford's base32 alphabet, which drops the letters I, L, O and
 * U. Dropping U keeps accidental words out of a key; dropping I/L/O
 * means every remaining glyph is unambiguous, and the ones people still
 * mistype fold onto a character that IS in the alphabet (I and L onto 1,
 * O onto 0) — see sr_recovery_key_normalise.
 * ------------------------------------------------------------------ */

const SR_RECOVERY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const SR_RECOVERY_GROUPS   = 5;
const SR_RECOVERY_GROUPLEN = 4;

/** Generate a fresh plaintext recovery key, e.g. 4KTQ-9PXM-... */
function sr_recovery_key_generate(): string
{
    $n = strlen(SR_RECOVERY_ALPHABET) - 1;
    $groups = [];
    for ($g = 0; $g < SR_RECOVERY_GROUPS; $g++) {
        $s = '';
        for ($i = 0; $i < SR_RECOVERY_GROUPLEN; $i++) {
            $s .= SR_RECOVERY_ALPHABET[random_int(0, $n)];   // CSPRNG
        }
        $groups[] = $s;
    }
    return implode('-', $groups);
}

/**
 * Normalise what the user typed: drop anything that is not a letter or a
 * digit (so spaces, dashes and stray punctuation are all fine),
 * uppercase it, then fold the three glyphs people habitually mistype.
 * Someone reading a key off paper should not be locked out because they
 * wrote O for zero. The fold targets are all in SR_RECOVERY_ALPHABET, so
 * a correctly-typed key always normalises to itself.
 */
function sr_recovery_key_normalise(string $key): string
{
    $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    return strtr($k, ['O' => '0', 'I' => '1', 'L' => '1']);
}

/** Store a key for a user. Returns the plaintext ONCE — it is never recoverable after this. */
function sr_recovery_key_issue(int $userId, ?string $label = null, ?int $by = null): string
{
    $plain = sr_recovery_key_generate();
    db()->prepare("INSERT INTO recovery_keys (user_id, key_hash, label, created_by) VALUES (?,?,?,?)")
        ->execute([
            $userId,
            password_hash(sr_recovery_key_normalise($plain), PASSWORD_DEFAULT),
            $label,
            $by,
        ]);
    return $plain;
}

/** How many unused keys a user still holds. */
function sr_recovery_key_count(int $userId): int
{
    $q = db()->prepare("SELECT COUNT(*) FROM recovery_keys WHERE user_id = ? AND used_at IS NULL");
    $q->execute([$userId]);
    return (int)$q->fetchColumn();
}

/**
 * Spend a key. Returns the recovery_keys row on success, null otherwise.
 *
 * Every unused key for the account is hashed-compared, because the
 * plaintext is not stored and there is no other way to find the match.
 * The row is stamped inside the same statement that claims it, with
 * `used_at IS NULL` in the WHERE, so two simultaneous requests cannot
 * both spend the same key.
 */
function sr_recovery_key_spend(int $userId, string $typed, string $ip): ?array
{
    $norm = sr_recovery_key_normalise($typed);
    if ($norm === '') return null;

    $q = db()->prepare("SELECT * FROM recovery_keys WHERE user_id = ? AND used_at IS NULL ORDER BY id");
    $q->execute([$userId]);

    foreach ($q->fetchAll() as $row) {
        if (!password_verify($norm, $row['key_hash'])) continue;
        $claim = db()->prepare("UPDATE recovery_keys SET used_at = NOW(), used_ip = ?
                                WHERE id = ? AND used_at IS NULL");
        $claim->execute([$ip, $row['id']]);
        if ($claim->rowCount() !== 1) return null;   // lost the race
        return $row;
    }
    return null;
}

/**
 * A readable temporary password. Lives here rather than in api/users.php
 * because both the admin reset and the Master recovery flow mint one: two short words, a separator and a
 * number. Long enough to pass validation, easy to read aloud to the
 * person on the other end of a phone. Ambiguous glyphs are excluded.
 */
function sr_temp_password(): string
{
    $words = [
        'Meskerem','Tikemet','Hidar','Tahsas','Tir','Yekatit','Megabit',
        'Miyazya','Ginbot','Sene','Hamle','Nehase','Pagume',
        'Cedar','Harbor','Lantern','Marble','Orchid','Pillar','Summit','Willow',
    ];
    $a = $words[random_int(0, count($words) - 1)];
    $b = $words[random_int(0, count($words) - 1)];
    return $a . '-' . $b . '-' . random_int(100, 999);
}
