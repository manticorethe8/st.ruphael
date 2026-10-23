<?php
/* ============================================================
   api/import.php
   Two-step student import: upload+validate returns a preview with
   every bad row flagged; nothing touches the database until the
   admin posts back with confirm. Accepts CSV, TSV and .xlsx.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('upload');
$user   = require_login();

const SR_IMPORT_MAX_BYTES = 6291456;   /* 6 MB  */
const SR_IMPORT_MAX_ROWS  = 5000;

switch ($action) {

/* ------------------------------------------------------------------
   upload — parse + validate, store the batch, return the preview
   ------------------------------------------------------------------ */
case 'upload': {
    sr_methods(['POST']);
    require_cap('students.import');

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('Choose a CSV or Excel file to upload.', 422, 'validation');
    }
    $f = $_FILES['file'];
    switch ($f['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: json_error('That file is larger than the server upload limit.', 413, 'too_large');
        case UPLOAD_ERR_NO_FILE:   json_error('No file was received.', 422, 'validation');
        default:                   json_error('The upload failed (code ' . (int)$f['error'] . ').', 400, 'upload_failed');
    }
    if ($f['size'] > SR_IMPORT_MAX_BYTES) {
        json_error('The file is too large. The limit is ' . round(SR_IMPORT_MAX_BYTES / 1048576) . ' MB.', 413, 'too_large');
    }
    if (!is_uploaded_file($f['tmp_name'])) json_error('Invalid upload.', 400, 'upload_failed');

    $name = (string)$f['name'];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'tsv', 'xlsx'], true)) {
        json_error('Only .csv, .tsv and .xlsx files can be imported. Please save your sheet in one of those formats.', 422, 'bad_format');
    }

    $rows = $ext === 'xlsx' ? sr_read_xlsx($f['tmp_name']) : sr_read_csv($f['tmp_name']);
    if (count($rows) < 2) json_error('The file has a header row but no student rows.', 422, 'empty_file');
    if (count($rows) - 1 > SR_IMPORT_MAX_ROWS) {
        json_error('That file holds ' . (count($rows) - 1) . ' rows. Please split it into files of ' . SR_IMPORT_MAX_ROWS . ' rows or fewer.', 413, 'too_large');
    }

    $report = sr_validate_rows($rows);

    $token = sr_uuid();
    $st = db()->prepare("INSERT INTO import_batches (token, filename, total_rows, valid_rows, invalid_rows, payload, status, created_by)
                         VALUES (?,?,?,?,?,?,'preview',?)");
    $st->execute([$token, mb_substr($name, 0, 190), $report['total'], $report['valid_count'], $report['invalid_count'],
                  json_encode($report['rows'], JSON_UNESCAPED_UNICODE), $user['id']]);

    audit('import.preview', 'import', null, "Previewed $name: {$report['valid_count']} valid / {$report['invalid_count']} invalid",
          ['filename' => $name, 'total' => $report['total']]);

    json_ok([
        'token'         => $token,
        'filename'      => $name,
        'total'         => $report['total'],
        'valid_count'   => $report['valid_count'],
        'invalid_count' => $report['invalid_count'],
        'duplicate_count' => $report['duplicate_count'],
        'columns'       => $report['columns'],
        'unknown_columns' => $report['unknown_columns'],
        'rows'          => array_slice($report['rows'], 0, 500),
        'truncated'     => count($report['rows']) > 500,
    ], ['message' => "{$report['valid_count']} row(s) ready to import, {$report['invalid_count']} need attention."]);
}

/* ------------------------------------------------------------------
   confirm — insert the valid rows of a previously previewed batch
   ------------------------------------------------------------------ */
case 'confirm': {
    sr_methods(['POST']);
    require_cap('students.import');

    $token = input_str('token');
    if ($token === '') json_error('Missing import token — upload the file again.', 422, 'validation');

    $st = db()->prepare("SELECT * FROM import_batches WHERE token = ?");
    $st->execute([$token]);
    $batch = $st->fetch();
    if (!$batch) json_error('That import session has expired. Please upload the file again.', 404, 'not_found');
    if ($batch['status'] === 'committed') json_error('This file has already been imported.', 409, 'already_done');

    $rows = json_decode((string)$batch['payload'], true) ?: [];
    /* the admin may untick rows in the preview grid */
    $skip = array_flip(array_map('intval', input_array('skip_rows')));

    $year = resolve_year();
    $yid  = (int)$year['id'];
    $ec   = (int)$year['year_ec'];
    $pdo  = db();

    $inserted = 0; $skipped = 0; $failed = [];
    $pdo->beginTransaction();
    try {
        $insS = $pdo->prepare("INSERT INTO students
            (student_code, full_name, baptism_name, gender, birth_date, phone, guardian_name, guardian_phone, address, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,'active',?)");
        $insE = $pdo->prepare("INSERT INTO enrollments (student_id, grade_id, academic_year_id, roll_no, status, enrolled_on)
                               VALUES (?,?,?,?,'active',CURDATE())");

        /* Roll numbers continue from whatever each grade already holds.
           Counters are cached per grade so a 200-row import does not run
           a MAX() query per student; the file is processed in one
           transaction, so nobody else can interleave. */
        $rollNext = [];

        /* Codes handed out during THIS commit. next_student_code() only
           sees committed rows, and we are inside a transaction, so
           without this two rows in the same file could be given the same
           code. Seeded with nothing: the DB check inside the helper
           covers everything already stored. */
        $usedCodes = [];

        foreach ($rows as $r) {
            if (!$r['valid'] || isset($skip[$r['row']])) { $skipped++; continue; }
            $d = $r['data'];
            try {
                /* ---- Student ID: the house format always wins ----------
                   A spreadsheet from another school (or an older version
                   of this one) usually carries IDs in a different scheme
                   — "STU/0012", "SR-2018-0100", a bare row number, or a
                   duplicate. Rejecting the file for that would be
                   useless to the registrar, so instead:

                     * a code already in OUR format and still free is kept
                       (re-importing a corrected file does not renumber
                       everyone);
                     * anything else is replaced with the next free code,
                       so the roster stays one unbroken sequence.

                   The original is never thrown away — it is appended to
                   the student's notes, because that number is what is
                   written on the school's paper records.

                   sr_import_resolve_code() is shared with the validation
                   pass, so the preview shows exactly what will be saved. */
                $orig  = $d['student_code'];
                $code  = sr_import_resolve_code($orig, $ec, $usedCodes);
                $usedCodes[mb_strtolower($code)] = true;

                $notes = $d['notes'] ?: '';
                if ($orig !== '' && $orig !== $code) {
                    $notes = trim($notes . ' [imported ID: ' . $orig . ']');
                }

                $insS->execute([$code, $d['full_name'], $d['baptism_name'] ?: null, $d['gender'],
                                $d['birth_date'] ?: null, $d['phone'] ?: null, $d['guardian_name'] ?: null,
                                $d['guardian_phone'] ?: null, $d['address'] ?: null, $notes ?: null, $user['id']]);
                $sid = (int)$pdo->lastInsertId();

                $gid = (int)$d['grade_id'];
                if (!isset($rollNext[$gid])) $rollNext[$gid] = next_roll_no($gid, $yid);
                $insE->execute([$sid, $gid, $yid, $rollNext[$gid]++]);
                $inserted++;
            } catch (PDOException $e) {
                $failed[] = ['row' => $r['row'], 'name' => $d['full_name'],
                             'error' => str_contains($e->getMessage(), '1062') ? 'Duplicate student ID' : 'Database rejected this row'];
            }
        }
        $pdo->prepare("UPDATE import_batches SET status='committed', imported_rows=?, committed_at=NOW() WHERE id=?")
            ->execute([$inserted, $batch['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('import.committed', 'import', (int)$batch['id'],
          "$inserted student(s) imported from {$batch['filename']}",
          ['inserted' => $inserted, 'skipped' => $skipped, 'failed' => count($failed)]);

    json_ok(['inserted' => $inserted, 'skipped' => $skipped, 'failed' => $failed],
            ['message' => "$inserted student(s) imported." . ($skipped ? " $skipped skipped." : '') . (count($failed) ? ' ' . count($failed) . ' failed.' : '')]);
}

/* ------------------------------------------------------------------
   batches — recent import history
   ------------------------------------------------------------------ */
case 'batches': {
    sr_methods(['GET']);
    require_cap('students.import');
    $st = db()->query("SELECT b.id, b.token, b.filename, b.total_rows, b.valid_rows, b.invalid_rows,
                              b.imported_rows, b.status, b.created_at, b.committed_at, u.full_name created_by_name
                       FROM import_batches b LEFT JOIN users u ON u.id = b.created_by
                       ORDER BY b.id DESC LIMIT 50");
    json_ok(['batches' => $st->fetchAll()]);
}

default:
    json_error('Unknown import action: ' . $action, 404, 'unknown_action');
}


/* ==================================================================
   Readers
   ================================================================== */

/** Detect encoding + delimiter, return a matrix of trimmed strings. */
function sr_read_csv(string $path): array
{
    $raw = (string)file_get_contents($path);

    /* strip a UTF-8 BOM, transcode UTF-16/Latin-1 to UTF-8 */
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    /* delimiter vote on the header line */
    $first = strtok($raw, "\n") ?: '';
    $best = ','; $bestN = substr_count($first, ',');
    foreach ([";" => substr_count($first, ';'), "\t" => substr_count($first, "\t"), "|" => substr_count($first, '|')] as $d => $n) {
        if ($n > $bestN) { $best = $d; $bestN = $n; }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $best, '"', '\\')) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        $rows[] = array_map(fn($v) => trim(preg_replace('/^\'/', '', (string)$v)), $r);
    }
    fclose($fh);
    return $rows;
}

/** Minimal .xlsx reader: first worksheet, shared strings resolved. */
function sr_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) json_error('This server cannot read .xlsx files. Please save the sheet as CSV.', 422, 'bad_format');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) json_error('That .xlsx file could not be opened — it may be corrupt.', 422, 'bad_format');

    $shared = [];
    if (($s = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($s);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }

    /* first sheet target from the workbook rels */
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $x = @simplexml_load_string($wb);
        if ($x) foreach ($x->Relationship as $rel) {
            if (str_ends_with((string)$rel['Type'], '/worksheet')) {
                $t = ltrim((string)$rel['Target'], '/');
                $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                break;
            }
        }
    }
    $sx = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sx === false) json_error('No worksheet was found in that file.', 422, 'bad_format');

    $x = @simplexml_load_string($sx);
    if (!$x) json_error('That .xlsx file could not be parsed.', 422, 'bad_format');

    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
            } else $col = count($cells);

            $t = (string)$c['t'];
            if ($t === 's')            $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr')$v = (string)($c->is->t ?? '');
            else                       $v = (string)($c->v ?? '');
            $cells[$col] = trim($v);
        }
        if (!$cells) continue;
        $max = max(array_keys($cells));
        $out = [];
        for ($i = 0; $i <= $max; $i++) $out[] = $cells[$i] ?? '';
        if (implode('', $out) === '') continue;
        $rows[] = $out;
    }
    return $rows;
}


/* ==================================================================
   Validation
   ================================================================== */

/**
 * Decide the student code a row will actually be saved under.
 *
 * Keeps the spreadsheet's own code only when it is already in the house
 * format AND nobody else holds it; otherwise mints the next free code.
 * This is what lets a file full of foreign IDs ("STU/0012") or repeated
 * IDs import cleanly instead of erroring out row by row.
 *
 * @param string $code   raw value from the sheet ('' when the cell is blank)
 * @param int    $yearEc current Ethiopian year
 * @param array  $taken  lowercase code => true, for codes claimed earlier
 *                       in this same file (passed by reference by callers
 *                       that need to reserve as they go)
 */
function sr_import_resolve_code(string $code, int $yearEc, array $taken = []): string
{
    $code = trim($code);
    if ($code !== '' && sr_is_auto_code($code) && !isset($taken[mb_strtolower($code)])) {
        $st = db()->prepare("SELECT COUNT(*) FROM students WHERE student_code = ?");
        $st->execute([$code]);
        if ((int)$st->fetchColumn() === 0) return $code;      /* ours, and free */
    }
    /* Blank, foreign format, duplicate, or already used above → renumber. */
    $next = next_student_code($yearEc);
    while (isset($taken[mb_strtolower($next)])) {
        /* next_student_code() cannot see codes reserved earlier in this
           file (they are not committed yet), so step past them. */
        $taken[mb_strtolower($next)] = true;
        $n = (int)substr($next, strlen(sr_code_prefix($yearEc))) + 1;
        $next = sr_code_prefix($yearEc) . str_pad((string)$n, SR_CODE_SEQ_WIDTH, '0', STR_PAD_LEFT);
    }
    return $next;
}

function sr_validate_rows(array $matrix): array
{
    $aliases = [
        'student_code'  => ['student_code','studentid','student id','id','code','መለያ','መለያ ቁጥር','ተ.ቁ'],
        'full_name'     => ['full_name','fullname','name','student name','ሙሉ ስም','ስም'],
        'baptism_name'  => ['baptism_name','baptismname','baptism','christian name','የክርስትና ስም','ክርስትና'],
        'grade'         => ['grade','class','grade/class','ክፍል','ደረጃ'],
        'gender'        => ['gender','sex','ጾታ'],
        'birth_date'    => ['birth_date','birthdate','dob','date of birth','የልደት ቀን'],
        'phone'         => ['phone','mobile','tel','ስልክ'],
        'guardian_name' => ['guardian_name','guardian','parent','የወላጅ ስም','ወላጅ'],
        'guardian_phone'=> ['guardian_phone','parent phone','የወላጅ ስልክ'],
        'address'       => ['address','አድራሻ'],
        'notes'         => ['notes','note','remark','ማስታወሻ'],
    ];

    /* canonical form: lowercase, no spaces / underscores / dots / dashes,
       so "Full Name", "full_name" and "FULLNAME" all collapse together */
    $canon = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        return preg_replace('/[\s_\-\.\/]+/u', '', $s) ?? $s;
    };
    $lookup = [];
    foreach ($aliases as $field => $names) {
        foreach ($names as $n) $lookup[$canon($n)] = $field;
    }

    $header = array_shift($matrix);
    $map = [];            /* field => column index */
    $unknown = [];
    foreach ($header as $i => $h) {
        $hit = $lookup[$canon((string)$h)] ?? null;
        if ($hit && !isset($map[$hit])) $map[$hit] = $i;
        elseif (!$hit && trim((string)$h) !== '') $unknown[] = (string)$h;
    }

    if (!isset($map['full_name'])) {
        json_error('No "full_name" column was found. Download the template to see the expected headings.', 422, 'bad_header');
    }
    if (!isset($map['grade'])) {
        json_error('No "grade" column was found. Download the template to see the expected headings.', 422, 'bad_header');
    }

    /* grade lookup */
    $gmap = [];
    foreach (db()->query("SELECT id, code, name_am, name_en FROM grades WHERE deleted_at IS NULL") as $g) {
        foreach ([$g['code'], $g['name_am'], $g['name_en']] as $k) {
            $kk = mb_strtolower(trim((string)$k));
            if ($kk !== '') $gmap[$kk] = ['id' => (int)$g['id'], 'label' => $g['name_am']];
        }
    }
    /* existing codes */
    $codes = [];
    foreach (db()->query("SELECT student_code FROM students") as $c) $codes[mb_strtolower($c['student_code'])] = true;

    $seenCode = []; $seenName = [];
    $rows = []; $valid = 0; $dupes = 0;

    foreach ($matrix as $n => $r) {
        $rowNo = $n + 2;                       /* 1-based, header is row 1 */
        $get = fn(string $f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';

        $errors = []; $warnings = [];
        $code = $get('student_code');
        $name = preg_replace('/\s+/u', ' ', $get('full_name'));
        $grd  = $get('grade');

        if ($name === '')            $errors[] = ['field' => 'full_name', 'message' => 'Full name is required.'];
        elseif (mb_strlen($name) > 160) $errors[] = ['field' => 'full_name', 'message' => 'Name is longer than 160 characters.'];

        $gid = null;
        if ($grd === '') $errors[] = ['field' => 'grade', 'message' => 'Grade is required.'];
        else {
            $hit = $gmap[mb_strtolower($grd)] ?? null;
            if (!$hit) {
                /* tolerate "Grade 5" / "5" / "g5" */
                if (preg_match('/(\d{1,2})/', $grd, $m)) $hit = $gmap['g' . (int)$m[1]] ?? null;
            }
            if ($hit) $gid = $hit['id'];
            else $errors[] = ['field' => 'grade', 'message' => 'Unknown grade "' . $grd . '".'];
        }

        /* ---- Student ID ------------------------------------------------
           A bad ID is never a reason to reject a row: the system owns the
           numbering, so anything unusable is simply replaced and the row
           still imports. We only WARN, and we say exactly what will
           happen, so the preview is honest about the outcome. */
        $lc = mb_strtolower($code);
        if ($code === '') {
            $warnings[] = 'No ID in the file — one will be generated.';
            $dupes++;
        } elseif (!sr_is_auto_code($code)) {
            $warnings[] = 'ID "' . $code . '" is not in this school\'s format — it will be renumbered (the original is kept in Notes).';
            $dupes++;
        } elseif (isset($codes[$lc])) {
            $warnings[] = 'ID "' . $code . '" is already used by another student — this row will be renumbered.';
            $dupes++;
        } elseif (isset($seenCode[$lc])) {
            $warnings[] = 'ID "' . $code . '" also appears on row ' . $seenCode[$lc] . ' — this row will be renumbered.';
            $dupes++;
        } else {
            $seenCode[$lc] = $rowNo;      /* well-formed and free: kept as-is */
        }

        $gender = mb_strtolower($get('gender'));
        $gender = match (true) {
            in_array($gender, ['m','male','ወንድ','ወ'], true)   => 'male',
            in_array($gender, ['f','female','ሴት','ሴ'], true)  => 'female',
            default                                            => null,
        };
        /* Only male/female exist. A blank or unrecognised cell defaults to
           male and is flagged so the importer can correct it afterwards. */
        if ($gender === null) {
            $warnings[] = 'Gender is blank or unrecognised — defaulting to male. Please review this row.';
            $gender = 'male';
        }

        $bd = $get('birth_date');
        $birth = '';
        if ($bd !== '') {
            $birth = sr_norm_date($bd);
            if ($birth === '') $warnings[] = 'Birth date "' . $bd . '" is not a recognised date and will be left empty.';
        }

        if ($name !== '' && $gid) {
            $nk = mb_strtolower($name) . '|' . $gid;
            if (isset($seenName[$nk])) $warnings[] = 'Same name and grade as row ' . $seenName[$nk] . ' — check for a duplicate.';
            else $seenName[$nk] = $rowNo;
        }

        $ok = !$errors;
        if ($ok) $valid++;

        $rows[] = [
            'row'      => $rowNo,
            'valid'    => $ok,
            'errors'   => $errors,
            'warnings' => $warnings,
            'data'     => [
                'student_code'  => $code,
                'full_name'     => $name,
                'baptism_name'  => mb_substr($get('baptism_name'), 0, 160),
                'grade_id'      => $gid,
                'grade_label'   => $grd,
                'gender'        => $gender,
                'birth_date'    => $birth,
                'phone'         => mb_substr($get('phone'), 0, 40),
                'guardian_name' => mb_substr($get('guardian_name'), 0, 160),
                'guardian_phone'=> mb_substr($get('guardian_phone'), 0, 40),
                'address'       => mb_substr($get('address'), 0, 255),
                'notes'         => mb_substr($get('notes'), 0, 500),
            ],
        ];
    }

    return [
        'total' => count($rows), 'valid_count' => $valid, 'invalid_count' => count($rows) - $valid,
        'duplicate_count' => $dupes, 'rows' => $rows,
        'columns' => array_keys($map), 'unknown_columns' => array_values(array_unique($unknown)),
    ];
}

/** Accept YYYY-MM-DD, DD/MM/YYYY, YYYY/MM/DD, D-M-YY and Excel serials. */
function sr_norm_date(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('/^\d{5}$/', $s)) {                 /* Excel serial */
        $ts = ((int)$s - 25569) * 86400;
        return gmdate('Y-m-d', $ts);
    }
    $s = str_replace(['.', '\\'], ['-', '/'], $s);
    foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd/m/y', 'Y-n-j'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            $y = (int)$d->format('Y');
            if ($y >= 1900 && $y <= (int)date('Y')) return $d->format('Y-m-d');
        }
    }
    return '';
}
