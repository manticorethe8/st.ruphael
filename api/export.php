<?php
/* ============================================================
   api/export.php
   Students / attendance / ID cards out to CSV, XLSX, JSON and a
   print-ready HTML report (Ctrl+P → PDF). Everything respects the
   caller's grade scope, so a teacher can only ever export their
   own classes.
   ============================================================ */
require_once __DIR__ . '/_boot.php';
require_once SR_ROOT . '/lib/xlsx.php';

$action = sr_action('students');
$user   = require_login();

/* ------------------------------------------------------------------
   shared: pull the student rows the caller is allowed to see
   ------------------------------------------------------------------ */
function sr_export_students(array $user): array
{
    $year  = resolve_year();
    $yid   = (int)$year['id'];
    $where = ['s.deleted_at IS NULL'];
    $args  = [$yid];

    $gradeId = input_int('grade_id');
    if ($gradeId) { sr_assert_grade_access((int)$gradeId, $yid); $where[] = 'e.grade_id = ?'; $args[] = $gradeId; }
    else {
        $scope = sr_scope_grades($yid);
        if ($scope !== null) {
            if (!$scope) return ['year' => $year, 'rows' => []];
            $where[] = 'e.grade_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $args = array_merge($args, $scope);
        }
    }

    /* An explicit selection arrives as ids=1,2,3 (the print/download links
       build one query string) or as a JSON array. Accept both — otherwise
       the selection is silently dropped and the whole roll prints. */
    $idsRaw = input('ids', []);
    if (is_string($idsRaw)) $idsRaw = preg_split('/[\s,]+/', trim($idsRaw), -1, PREG_SPLIT_NO_EMPTY);
    $ids = array_values(array_filter(array_map('intval', (array)$idsRaw)));
    if ($ids) {
        foreach ($ids as $sid) sr_assert_student_access($sid, $yid);
        $where[] = 's.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }
    if ($q = trim(input_str('q'))) {
        /* Same forgiving match as the student list, so an export filtered
           by an abbreviation returns the same rows the user just saw. */
        [$qSql, $qArgs] = sr_search_clause($q, [
            's.full_name', 's.baptism_name', 's.student_code',
            "REPLACE(s.student_code, '-', '')",
        ]);
        if ($qSql !== '') { $where[] = $qSql; $args = array_merge($args, $qArgs); }
    }
    if ($status = input_str('status')) {
        $where[] = 's.status = ?'; $args[] = v_in($status, ['active','inactive','graduated','transferred'], 'Status');
    }

    $st = db()->prepare("SELECT s.*, e.roll_no, e.status enroll_status, e.result,
                                g.id grade_id, g.name_am grade_am, g.name_en grade_en, g.code grade_code
                         FROM students s
                         JOIN enrollments e ON e.student_id = s.id AND e.academic_year_id = ?
                         JOIN grades g ON g.id = e.grade_id
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY g.sort_order, s.full_name");
    $st->execute($args);
    return ['year' => $year, 'rows' => $st->fetchAll()];
}

function sr_send_file(string $body, string $filename, string $mime): void
{
    /* keep a copy in exports/ for the admin, then stream it */
    sr_ensure_dir(EXPORT_DIR);
    @file_put_contents(rtrim(EXPORT_DIR, '/') . '/' . $filename, $body);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

function sr_stamp(): string { return date('Ymd-His'); }

switch ($action) {

/* ------------------------------------------------------------------
   students — csv | xlsx | json | pdf(html)
   ------------------------------------------------------------------ */
case 'students': {
    sr_methods(['GET', 'POST']);
    require_cap('export.students');
    $fmt  = v_in(strtolower(input_str('format', 'csv')), ['csv','xlsx','excel','json','pdf','html'], 'Format');
    $lang = input_str('lang', 'am') === 'en' ? 'en' : 'am';
    $d    = sr_export_students($user);
    $rows = $d['rows'];
    $yearEc = (int)$d['year']['year_ec'];
    /* The brand toggle: with "always Amharic" (default) printed reports
       carry the Amharic name whatever the print language; off, they
       follow it. sr_brand_name() is the single authority. */
    $school = sr_brand_name($lang);

    $head = $lang === 'am'
        ? ['መለያ ቁጥር','ሙሉ ስም','የክርስትና ስም','ክፍል','ጾታ','የልደት ቀን','ስልክ','የወላጅ ስም','የወላጅ ስልክ','አድራሻ','ሁኔታ','የትምህርት ዓመት']
        : ['Student ID','Full Name','Baptism Name','Grade','Gender','Birth Date','Phone','Guardian','Guardian Phone','Address','Status','Academic Year'];

    $genders = $lang === 'am'
        ? ['male' => 'ወንድ', 'female' => 'ሴት']
        : ['male' => 'Male', 'female' => 'Female'];

    $table = [$head];
    foreach ($rows as $r) {
        $table[] = [
            $r['student_code'],
            $r['full_name'],
            $r['baptism_name'] ?: '',
            $lang === 'am' ? $r['grade_am'] : $r['grade_en'],
            $genders[$r['gender']] ?? $r['gender'],
            $r['birth_date'] ?: '',
            $r['phone'] ?: '',
            $r['guardian_name'] ?: '',
            $r['guardian_phone'] ?: '',
            $r['address'] ?: '',
            $r['status'],
            $yearEc,
        ];
    }

    audit('export.students', 'student', null, count($rows) . " students exported as " . strtoupper($fmt), ['format' => $fmt, 'count' => count($rows)]);

    if ($fmt === 'json') {
        $students = [];
        foreach ($rows as $r) {
            $students[] = [
                'student_code'  => $r['student_code'],
                'full_name'     => $r['full_name'],
                'baptism_name'  => $r['baptism_name'],
                'grade_code'    => $r['grade_code'],
                'grade_am'      => $r['grade_am'],
                'grade_en'      => $r['grade_en'],
                'gender'        => $r['gender'],
                'birth_date'    => $r['birth_date'],
                'phone'         => $r['phone'],
                'guardian_name' => $r['guardian_name'],
                'guardian_phone'=> $r['guardian_phone'],
                'address'       => $r['address'],
                'notes'         => $r['notes'],
                'status'        => $r['status'],
                'roll_no'       => $r['roll_no'] !== null ? (int)$r['roll_no'] : null,
                'academic_year' => $yearEc,
            ];
        }
        $out = ['school' => $school, 'academic_year_ec' => $yearEc,
                'exported_at' => date('c'), 'exported_by' => $user['username'],
                'count' => count($students), 'students' => $students];
        sr_send_file(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                     "students-$yearEc-" . sr_stamp() . '.json', 'application/json; charset=utf-8');
    }
    if ($fmt === 'csv') {
        sr_send_file(sr_csv_string($table), "students-$yearEc-" . sr_stamp() . '.csv', 'text/csv; charset=utf-8');
    }
    if ($fmt === 'xlsx' || $fmt === 'excel') {
        $x = new SrXlsx();
        $x->sheet($lang === 'am' ? 'ተማሪዎች' : 'Students', $table, [16,26,24,14,10,14,16,22,16,26,12,10]);
        $tmp = tempnam(sys_get_temp_dir(), 'srx') . '.xlsx';
        $x->save($tmp);
        $body = file_get_contents($tmp); @unlink($tmp);
        sr_send_file($body, "students-$yearEc-" . sr_stamp() . '.xlsx',
                     'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    /* pdf / html — printable report */
    sr_print_report(
        $lang === 'am' ? 'የተማሪዎች መዝገብ' : 'Student Register',
        $school, $yearEc, $head, array_slice($table, 1), $lang,
        $lang === 'am' ? 'ጠቅላላ ተማሪዎች: ' . count($rows) : 'Total students: ' . count($rows),
        sr_printed_by($user, $lang)
    );
}

/* ------------------------------------------------------------------
   attendance — csv | xlsx | json | pdf, Pagume included
   ------------------------------------------------------------------ */
case 'attendance': {
    sr_methods(['GET', 'POST']);
    require_cap('reports.view');
    $fmt  = v_in(strtolower(input_str('format', 'csv')), ['csv','xlsx','excel','json','pdf','html'], 'Format');
    $lang = input_str('lang', 'am') === 'en' ? 'en' : 'am';
    $year = resolve_year();
    $yid  = (int)$year['id'];

    $where = ['a.academic_year_id = ?'];
    $args  = [$yid];

    $gradeId = input_int('grade_id');
    if ($gradeId) { sr_assert_grade_access((int)$gradeId, $yid); $where[] = 'a.grade_id = ?'; $args[] = $gradeId; }
    else {
        $scope = sr_scope_grades($yid);
        if ($scope !== null) {
            if (!$scope) $scope = [0];
            $where[] = 'a.grade_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $args = array_merge($args, $scope);
        }
    }
    if ($m = input_int('month')) {          /* 1..13 — 13 is Pagume */
        $where[] = 'a.et_month = ?'; $args[] = max(1, min(13, (int)$m));
    }
    if ($from = input_str('from')) { $where[] = 'a.session_date >= ?'; $args[] = v_iso_date($from, 'From date'); }
    if ($to   = input_str('to'))   { $where[] = 'a.session_date <= ?'; $args[] = v_iso_date($to, 'To date'); }
    if ($dk   = input_str('day'))  { $where[] = 'a.day_of_week = ?';  $args[] = v_in($dk, ['sat','sun','other'], 'Day'); }
    if ($stat = input_str('status')) { $where[] = 'a.status = ?'; $args[] = v_in($stat, ['present','absent','excused'], 'Status'); }

    $st = db()->prepare("SELECT a.*, s.full_name, s.student_code, s.baptism_name,
                                g.name_am grade_am, g.name_en grade_en, u.full_name recorded_by_name
                         FROM attendance a
                         JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                         JOIN grades   g ON g.id = a.grade_id
                         LEFT JOIN users u ON u.id = a.recorded_by
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY a.session_date DESC, g.sort_order, s.full_name
                         LIMIT 20000");
    $st->execute($args);
    $rows = $st->fetchAll();

    $months = $lang === 'am' ? SR_ET_MONTHS_AM : SR_ET_MONTHS_EN;
    $days   = $lang === 'am' ? ['sat' => 'ቅዳሜ', 'sun' => 'እሁድ', 'other' => '—']
                             : ['sat' => 'Saturday', 'sun' => 'Sunday', 'other' => '—'];
    $stats  = $lang === 'am' ? ['present' => 'ተገኝቷል', 'absent' => 'አልተገኘም', 'excused' => 'በፈቃድ']
                             : ['present' => 'Present', 'absent' => 'Absent', 'excused' => 'Excused'];

    $head = $lang === 'am'
        ? ['ቀን','ዕለት','የኢት. ወር','የኢት. ቀን','መለያ ቁጥር','ሙሉ ስም','ክፍል','ሁኔታ','ምክንያት','መዝጋቢ']
        : ['Date','Day','Et. Month','Et. Day','Student ID','Full Name','Grade','Status','Reason','Recorded By'];

    $table = [$head];
    foreach ($rows as $r) {
        $table[] = [
            $r['session_date'],
            $days[$r['day_of_week']] ?? $r['day_of_week'],
            $months[(int)$r['et_month'] - 1] ?? '',
            $lang === 'am' ? to_geez((int)$r['et_day']) : (int)$r['et_day'],
            $r['student_code'],
            $r['full_name'],
            $lang === 'am' ? $r['grade_am'] : $r['grade_en'],
            $stats[$r['status']] ?? $r['status'],
            $r['excuse_reason'] ?: '',
            $r['recorded_by_name'] ?: '',
        ];
    }

    audit('export.attendance', 'attendance', null, count($rows) . ' attendance rows exported as ' . strtoupper($fmt), ['format' => $fmt, 'count' => count($rows)]);
    /* Brand toggle — see the students export above; same rule. */
    $school = sr_brand_name($lang);
    $yearEc = (int)$year['year_ec'];

    if ($fmt === 'json') {
        sr_send_file(json_encode(['school' => $school, 'academic_year_ec' => $yearEc, 'count' => count($rows), 'records' => $rows],
                     JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                     "attendance-$yearEc-" . sr_stamp() . '.json', 'application/json; charset=utf-8');
    }
    if ($fmt === 'csv') {
        sr_send_file(sr_csv_string($table), "attendance-$yearEc-" . sr_stamp() . '.csv', 'text/csv; charset=utf-8');
    }
    if ($fmt === 'xlsx' || $fmt === 'excel') {
        $x = new SrXlsx();
        $x->sheet($lang === 'am' ? 'መገኘት' : 'Attendance', $table, [13,12,14,10,16,26,14,12,24,20]);

        /* second sheet: per-month totals including Pagume */
        $sum = [];
        foreach ($rows as $r) {
            $k = (int)$r['et_month'];
            $sum[$k] ??= ['present' => 0, 'absent' => 0, 'excused' => 0];
            $sum[$k][$r['status']]++;
        }
        ksort($sum);
        $s2 = [$lang === 'am' ? ['ወር','ተገኝቷል','አልተገኘም','በፈቃድ','መጠን %'] : ['Month','Present','Absent','Excused','Rate %']];
        foreach ($sum as $m => $v) {
            $tot = $v['present'] + $v['absent'] + $v['excused'];
            $s2[] = [$months[$m - 1] ?? $m, $v['present'], $v['absent'], $v['excused'], $tot ? round($v['present'] / $tot * 100) : 0];
        }
        $x->sheet($lang === 'am' ? 'ማጠቃለያ' : 'Summary', $s2, [18,12,12,12,12]);

        $tmp = tempnam(sys_get_temp_dir(), 'srx') . '.xlsx';
        $x->save($tmp);
        $body = file_get_contents($tmp); @unlink($tmp);
        sr_send_file($body, "attendance-$yearEc-" . sr_stamp() . '.xlsx',
                     'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    sr_print_report(
        $lang === 'am' ? 'የመገኘት መዝገብ' : 'Attendance Report',
        $school, $yearEc, $head, array_slice($table, 1), $lang,
        $lang === 'am' ? 'ጠቅላላ መዝገቦች: ' . count($rows) : 'Total records: ' . count($rows),
        sr_printed_by($user, $lang)
    );
}

/* ------------------------------------------------------------------
   template — the blank import sheet (csv or xlsx)
   ------------------------------------------------------------------ */
case 'template': {
    sr_methods(['GET']);
    require_cap('students.import');
    $fmt  = v_in(strtolower(input_str('format', 'csv')), ['csv','xlsx','excel'], 'Format');
    /* NO student_code column. IDs are issued by the system, so asking for
       one on the template invites people to invent a scheme, and every
       invented value then has to be thrown away on import. Two required
       columns first, optional ones after — a registrar can fill in just
       the first two and the file will import. */
    $head = ['full_name','grade','baptism_name','gender','birth_date','phone','guardian_name','guardian_phone','address','notes'];
    $sample = [
        ['አበበ ከበደ',      'ቂርቆስ',   'ገብረ ማርያም',    'male',   '2012-03-14', '0911000000', 'ከበደ ታደሰ',  '0911000001', 'አዲስ አበባ', ''],
        ['Sara Tesfaye', 'Grade 5', 'Welete Mikael', 'female', '2011-07-02', '0911000002', 'Tesfaye A.', '0911000003', 'Addis Ababa', 'optional note'],
        ['Yonas Girma',  'g3',      '',              'male',   '',           '',           '',           '',           '',            ''],
    ];
    if ($fmt === 'csv') {
        sr_send_file(sr_csv_string(array_merge([$head], $sample)), 'student-import-template.csv', 'text/csv; charset=utf-8');
    }
    $x = new SrXlsx();
    $x->sheet('Students', array_merge([$head], $sample), [26,16,24,10,14,16,22,16,26,20]);

    /* A short, plain-language guide. Three rows of sample data above are
       usually enough, but the registrar filling this in is not the person
       who read the README. */
    $notes = [
        ['Column',          'Required', 'What to put in it'],
        ['full_name',       'YES',      'The student\'s full name, Amharic or English. This is the only column you must always fill in.'],
        ['grade',           'YES',      'Amharic name, English name or short code — ቂርቆስ / Kirkos / kirkos / Grade 5 / g5 / 5 all work.'],
        ['baptism_name',    'no',       'የክርስትና ስም. Leave blank if you do not have it.'],
        ['gender',          'no',       'male / female / ወንድ / ሴት. Blank is treated as male.'],
        ['birth_date',      'no',       'YYYY-MM-DD (2012-03-14), DD/MM/YYYY or YYYY/MM/DD. Gregorian dates.'],
        ['phone',           'no',       'The student\'s own phone, if any. Keep the leading zero.'],
        ['guardian_name',   'no',       'Parent or guardian.'],
        ['guardian_phone',  'no',       'Best number to reach the family on.'],
        ['address',         'no',       'Sub-city / woreda / kebele, or however the school records it.'],
        ['notes',           'no',       'Anything else worth keeping.'],
        ['', '', ''],
        ['Student ID',      '—',        'Do NOT add an ID column. The system issues IDs itself, in the form 18-0042 (year, then a running number).'],
        ['',                '',         'If your old spreadsheet has its own ID column you can leave it in — it will be renumbered, and the old value saved into Notes.'],
        ['Roll number',     '—',        'Also automatic. Numbering restarts at 1 in each grade, each year.'],
        ['', '', ''],
        ['How it works',    '',         '1. Fill in this sheet.  2. Import Students → choose the file → Upload.  3. Check the preview.  4. Confirm.'],
        ['',                '',         'Nothing is saved until you press Confirm, so it is safe to upload and look.'],
        ['',                '',         'Rows with a problem are listed in the preview; untick any row to leave it out.'],
    ];
    $x->sheet('Instructions', $notes, [18, 11, 96]);
    $tmp = tempnam(sys_get_temp_dir(), 'srx') . '.xlsx';
    $x->save($tmp);
    $body = file_get_contents($tmp); @unlink($tmp);
    sr_send_file($body, 'student-import-template.xlsx',
                 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

/* ------------------------------------------------------------------
   idcards — printable ID cards (one or many)
   ------------------------------------------------------------------ */
case 'idcards': {
    sr_methods(['GET', 'POST']);
    require_cap('idcard.view');
    $lang = input_str('lang', 'am') === 'en' ? 'en' : 'am';
    $d    = sr_export_students($user);
    if (!$d['rows']) json_error('There are no students matching that selection.', 404, 'empty');
    audit('export.idcards', 'student', null, count($d['rows']) . ' ID card(s) generated');
    sr_print_idcards($d['rows'], (int)$d['year']['year_ec'], $lang, $user);
}

default:
    json_error('Unknown export action: ' . $action, 404, 'unknown_action');
}


/* ==================================================================
   Printable HTML documents
   No GD / no PDF library on the target XAMPP box, so PDFs are
   produced through the browser's own "Print → Save as PDF", which
   also keeps Amharic shaping perfect via the bundled Ethiopic font.
   ================================================================== */

function sr_doc_head(string $title, string $extraCss = ''): string
{
    return '<!doctype html><html lang="am"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<link rel="icon" type="image/png" href="../assets/logo.png">'
      . '<title>' . e($title) . '</title>'
      . '<link rel="stylesheet" href="../css/fonts.min.css">'
      . '<style>'
      . '*{box-sizing:border-box}'
      . 'body{font-family:"Noto Sans Ethiopic","Inter",system-ui,sans-serif;margin:0;padding:24px;color:#1d2129;background:#f5f6f8}'
      . '.sheet{background:#fff;max-width:1100px;margin:0 auto;padding:28px 32px;border-radius:10px;box-shadow:0 2px 18px rgba(0,0,0,.08)}'
      . '.hd{display:flex;align-items:center;gap:16px;border-bottom:3px solid #7a1f2b;padding-bottom:14px;margin-bottom:18px}'
      . '.hd img{width:62px;height:62px;object-fit:contain}'
      . '.hd h1{margin:0;font-size:20px;color:#7a1f2b}'
      . '.hd p{margin:3px 0 0;font-size:13px;color:#5b6570}'
      . 'table{width:100%;border-collapse:collapse;font-size:12.5px}'
      . 'th{background:#7a1f2b;color:#fff;text-align:start;padding:8px 9px;font-weight:600}'
      . 'td{padding:7px 9px;border-bottom:1px solid #e6e8eb}'
      . 'tbody tr:nth-child(even){background:#fafafa}'
      . '.meta{display:flex;justify-content:space-between;font-size:12px;color:#5b6570;margin-bottom:12px;flex-wrap:wrap;gap:8px}'
      . '.pbar{position:sticky;top:0;background:#fff;padding:10px 0 16px;text-align:center;z-index:5}'
      . '.pbar button{background:#7a1f2b;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:14px;cursor:pointer;font-family:inherit}'
      . '.sr-foot{margin-top:16px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:11.5px;color:#6b7280;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}'
      . '@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;max-width:none;padding:0;border-radius:0}.pbar{display:none}thead{display:table-header-group}tr{break-inside:avoid}}'
      . $extraCss
      . '</style></head><body>';
}

/** The "printed by …" line every report footer carries, in Addis Ababa time. */
function sr_printed_by(array $user, string $lang): string
{
    $name = trim((string)($user['full_name'] ?? ''));
    if ($name === '') $name = trim((string)($user['username'] ?? ''));
    if ($name === '') $name = '—';
    $stamp = et_label(date('Y-m-d'), $lang) . ' · ' . date('H:i');
    return ($lang === 'am' ? 'ታተመ በ ' : 'Printed by ') . $name . ' — ' . $stamp;
}

function sr_print_report(string $title, string $school, int $yearEc, array $head, array $rows, string $lang, string $summary, string $foot = ''): void
{
    $printLabel = $lang === 'am' ? 'አትም / PDF አድርግ' : 'Print / Save as PDF';
    $html  = sr_doc_head($title);
    $html .= '<div class="pbar"><button onclick="window.print()">' . $printLabel . '</button></div>';
    $html .= '<div class="sheet">';
    $html .= '<div class="hd"><img src="../assets/logo.png" alt=""><div><h1>' . e($school) . '</h1>'
           . '<p>' . e($title) . ' &middot; ' . ($lang === 'am' ? to_geez($yearEc) : $yearEc) . '</p></div></div>';
    $html .= '<div class="meta"><span>' . e($summary) . '</span></div>';
    $html .= '<table><thead><tr>';
    foreach ($head as $h) $html .= '<th>' . e($h) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($r as $c) $html .= '<td>' . e($c) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    if ($foot !== '') {
        $html .= '<div class="sr-foot">' . e($foot) . '</div>';
    }
    $html .= '</div></body></html>';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

function sr_print_idcards(array $rows, int $yearEc, string $lang, array $user = []): void
{
    $schoolAm = (string)setting('school_name_am', 'ቅዱስ ሩፋኤል');
    $schoolEn = (string)setting('school_name_en', 'St. Rufael');
    $footer   = (string)setting($lang === 'am' ? 'id_card_footer_am' : 'id_card_footer_en', '');
    $school   = $lang === 'am' ? $schoolAm : $schoolEn;

    $css = '.cards{display:flex;flex-wrap:wrap;gap:14px;justify-content:flex-start}'
         . '.card{width:325px;height:205px;border-radius:12px;overflow:hidden;background:#fff;'
         . 'border:1px solid #d9dde2;box-shadow:0 1px 6px rgba(0,0,0,.07);display:flex;flex-direction:column}'
         . '.card .top{background:linear-gradient(135deg,#7a1f2b,#a8394a);color:#fff;padding:8px 12px;display:flex;align-items:center;gap:9px}'
         . '.card .top img{width:30px;height:30px;object-fit:contain;background:#fff;border-radius:50%;padding:2px}'
         . '.card .top b{font-size:12.5px;line-height:1.2;display:block}'
         . '.card .top span{font-size:9.5px;opacity:.9}'
         . '.card .body{display:flex;gap:10px;padding:10px 12px;flex:1}'
         . '.photo{width:64px;height:78px;border-radius:6px;background:#eef0f2;border:1px solid #dcdfe3;'
         . 'display:flex;align-items:center;justify-content:center;font-size:22px;color:#9aa3ab;overflow:hidden;flex:none}'
         . '.photo img{width:100%;height:100%;object-fit:cover}'
         . '.info{font-size:11px;line-height:1.55;flex:1;min-width:0}'
         . '.info .nm{font-size:13.5px;font-weight:700;color:#7a1f2b;margin-bottom:1px;'
         . 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
         . '.info .row{display:flex;gap:5px}.info .k{color:#79838d;flex:none}.info .v{font-weight:600;'
         . 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
         . '.card .btm{background:#f3f4f6;border-top:1px solid #e3e6e9;padding:4px 12px;font-size:9px;'
         . 'color:#6b747d;display:flex;justify-content:space-between;align-items:center}'
         . '.ic-foot{margin-top:16px;padding-top:10px;border-top:1px solid #e3e6e9;font-size:11px;color:#6b747d;text-align:center}'
         . '@media print{.cards{gap:8px}.card{break-inside:avoid;box-shadow:none}.ic-foot{margin-top:16px}}';

    $L = $lang === 'am'
        ? ['id' => 'መለያ', 'grade' => 'ክፍል', 'year' => 'ዓ.ም', 'bap' => 'ክርስትና', 'print' => 'አትም / PDF አድርግ', 'title' => 'የተማሪ መታወቂያ']
        : ['id' => 'ID', 'grade' => 'Grade', 'year' => 'Year', 'bap' => 'Baptism', 'print' => 'Print / Save as PDF', 'title' => 'Student ID Cards'];

    $html  = sr_doc_head($L['title'], $css);
    $html .= '<div class="pbar"><button onclick="window.print()">' . $L['print'] . '</button></div>';
    $html .= '<div class="sheet"><div class="cards">';

    foreach ($rows as $r) {
        $photo = $r['photo_path'] ? '<img src="../' . e(ltrim($r['photo_path'], '/')) . '" alt="">' : '&#128100;';
        $grade = $lang === 'am' ? $r['grade_am'] : $r['grade_en'];
        $yr    = $lang === 'am' ? to_geez($yearEc) : (string)$yearEc;
        $html .= '<div class="card">'
              . '<div class="top"><img src="../assets/logo.png" alt=""><div><b>' . e($school) . '</b>'
              . '<span>' . e($lang === 'am' ? $schoolEn : $schoolAm) . '</span></div></div>'
              . '<div class="body"><div class="photo">' . $photo . '</div><div class="info">'
              . '<div class="nm">' . e($r['full_name']) . '</div>'
              . ($r['baptism_name'] ? '<div class="row"><span class="k">' . $L['bap'] . ':</span><span class="v">' . e($r['baptism_name']) . '</span></div>' : '')
              . '<div class="row"><span class="k">' . $L['id'] . ':</span><span class="v">' . e($r['student_code']) . '</span></div>'
              . '<div class="row"><span class="k">' . $L['grade'] . ':</span><span class="v">' . e($grade) . '</span></div>'
              . '<div class="row"><span class="k">' . $L['year'] . ':</span><span class="v">' . e($yr) . '</span></div>'
              . '</div></div>'
              . '<div class="btm"><span>' . e($footer) . '</span><span>' . e($r['student_code']) . '</span></div>'
              . '</div>';
    }
    if ($user) {
        $html .= '<div class="ic-foot">' . e(sr_printed_by($user, $lang)) . '</div>';
    }
    $html .= '</div></div></body></html>';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}
