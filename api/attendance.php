<?php
/* ============================================================
   api/attendance.php
   Register loading + saving, bulk marking, copy-last-week,
   dashboard statistics, alerts. Saturday and Sunday are always
   separate rows. Pagume (Ethiopian month 13) is a first-class
   period everywhere, exactly like Sene / Hamle / Nehase.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('register');
$user   = require_login();

const SR_STATUSES = ['present', 'absent', 'excused'];

switch ($action) {

/* ------------------------------------------------------------------
   periods — the month tabs the UI renders. Group 4 is Sene, Hamle,
   Nehase and Pagume; Pagume carries its real day count for the year.
   ------------------------------------------------------------------ */
case 'periods': {
    sr_methods(['GET', 'POST']);
    $year  = resolve_year();
    $etY   = (int)$year['year_ec'];
    $groups = [[1,2,3],[4,5,6],[7,8,9],[10,11,12,13]];   // 13 = ጳጉሜ / Pagume
    $meta   = et_months_meta($etY);

    $out = [];
    foreach ($groups as $gi => $months) {
        $ms = [];
        foreach ($months as $m) {
            $cols = et_month_weekend_cols($etY, $m);      // sat/sun dates only
            $ms[] = [
                'month'     => $m,
                'name_am'   => SR_ET_MONTHS_AM[$m - 1],
                'name_en'   => SR_ET_MONTHS_EN[$m - 1],
                'is_pagume' => $m === 13,
                'days'      => et_month_days($etY, $m),
                'columns'   => $cols,
                'col_count' => count($cols),
            ];
        }
        $out[] = ['index' => $gi, 'months' => $ms];
    }

    json_ok([
        'year'      => $year,
        'et_year'   => $etY,
        'groups'    => $out,
        'months'    => $meta,
        'pagume_days' => et_month_days($etY, 13),
        'is_leap'   => et_is_leap($etY),
        'today'     => ['iso' => date('Y-m-d'), 'et' => et_today(), 'kind' => iso_day_kind(date('Y-m-d'))],
    ]);
}

/* ------------------------------------------------------------------
   register — one grade × one Ethiopian month: students × sat/sun cols
   ------------------------------------------------------------------ */
case 'register': {
    sr_methods(['GET', 'POST']);
    require_cap('attendance.view');
    $year    = resolve_year();
    $yid     = (int)$year['id'];
    $etY     = (int)$year['year_ec'];
    $gradeId = (int)input_int('grade_id', 0);
    $month   = (int)input_int('month', et_today()['m']);

    if (!$gradeId) json_error('Please choose a class.', 422, 'validation');
    if ($month < 1 || $month > 13) json_error('Month must be between 1 and 13 (13 = ጳጉሜ / Pagume).', 422, 'validation');
    sr_assert_grade_access($gradeId, $yid);

    $cols = et_month_weekend_cols($etY, $month);   // [{iso, et_day, kind, dow}]
    $isos = array_column($cols, 'iso');

    $st = db()->prepare("SELECT s.id, s.student_code, s.full_name, s.baptism_name, s.gender, s.photo_path,
                                e.id enrollment_id, e.roll_no
                         FROM enrollments e JOIN students s ON s.id = e.student_id
                         WHERE e.academic_year_id = ? AND e.grade_id = ? AND s.deleted_at IS NULL
                           AND e.status IN ('active','repeated')
                         ORDER BY e.roll_no IS NULL, e.roll_no, s.full_name");
    $st->execute([$yid, $gradeId]);
    $students = $st->fetchAll();

    $marks = [];
    if ($isos && $students) {
        $in  = implode(',', array_fill(0, count($isos), '?'));
        $ma  = db()->prepare("SELECT student_id, session_date, status, excuse_reason, day_of_week, updated_at
                              FROM attendance
                              WHERE grade_id = ? AND academic_year_id = ? AND session_date IN ($in)");
        $ma->execute(array_merge([$gradeId, $yid], $isos));
        foreach ($ma->fetchAll() as $r) {
            $marks[(int)$r['student_id']][$r['session_date']] = [
                'status' => $r['status'],
                'reason' => $r['excuse_reason'],
                'day'    => $r['day_of_week'],
                'saved'  => $r['updated_at'],
            ];
        }
    }

    $g = db()->prepare("SELECT * FROM grades WHERE id = ?");
    $g->execute([$gradeId]);

    $rows = [];
    foreach ($students as $s) {
        $rows[] = [
            'id'            => (int)$s['id'],
            'student_code'  => $s['student_code'],
            'full_name'     => $s['full_name'],
            'baptism_name'  => $s['baptism_name'],
            'gender'        => $s['gender'],
            'photo_path'    => $s['photo_path'],
            'enrollment_id' => (int)$s['enrollment_id'],
            'roll_no'       => $s['roll_no'] !== null ? (int)$s['roll_no'] : null,
            'marks'         => $marks[(int)$s['id']] ?? new stdClass(),
        ];
    }

    json_ok([
        'year'      => $year,
        'grade'     => $g->fetch(),
        'month'     => [
            'no'        => $month,
            'name_am'   => SR_ET_MONTHS_AM[$month - 1],
            'name_en'   => SR_ET_MONTHS_EN[$month - 1],
            'is_pagume' => $month === 13,
            'days'      => et_month_days($etY, $month),
        ],
        'columns'   => $cols,
        'students'  => $rows,
        'statuses'  => SR_STATUSES,
        'can_edit'  => can('attendance.edit'),
    ]);
}

/* ------------------------------------------------------------------
   save — one or many marks. Idempotent per (student, date).
   ------------------------------------------------------------------ */
case 'save': {
    sr_methods(['POST']);
    require_cap('attendance.edit');
    $year = resolve_year();
    $yid  = (int)$year['id'];
    $etY  = (int)$year['year_ec'];

    $marks = input_array('marks');
    if (!$marks) {
        /* single-mark shorthand */
        $one = [
            'student_id'  => input_int('student_id'),
            'grade_id'    => input_int('grade_id'),
            'date'        => input_str('date'),
            'status'      => input_str('status'),
            'reason'      => input_str('reason'),
            'client_uuid' => input_str('client_uuid') ?: null,
        ];
        if (!$one['student_id']) json_error('Nothing to save.', 422, 'validation');
        $marks = [$one];
    }
    if (count($marks) > 5000) json_error('Too many records in one request. Save in smaller batches.', 413, 'too_large');

    $pdo = db();
    $saved = 0; $cleared = 0; $errors = [];
    $gradeCache = [];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO attendance
            (student_id, enrollment_id, grade_id, academic_year_id, session_date, et_year, et_month, et_day,
             day_of_week, status, excuse_reason, client_uuid, recorded_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              status = VALUES(status), excuse_reason = VALUES(excuse_reason),
              grade_id = VALUES(grade_id), enrollment_id = VALUES(enrollment_id),
              updated_by = VALUES(updated_by), updated_at = NOW()");
        $del = $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND session_date = ?");

        foreach ($marks as $i => $m) {
            $sid    = (int)($m['student_id'] ?? 0);
            $date   = trim((string)($m['date'] ?? ''));
            $status = strtolower(trim((string)($m['status'] ?? '')));
            $reason = trim((string)($m['reason'] ?? ''));
            $gid    = (int)($m['grade_id'] ?? 0);
            $uuid   = isset($m['client_uuid']) && $m['client_uuid'] ? substr((string)$m['client_uuid'], 0, 36) : null;

            if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = ['index' => $i, 'message' => 'Missing student or date.'];
                continue;
            }
            /* The grade register records TODAY; other days may only be
               touched by the Master or an admin — everyone else corrects
               them through the Correction page. */
            if ($date !== date('Y-m-d') && !in_array($user['role'], ['master','admin'], true)) {
                $errors[] = ['index' => $i, 'student_id' => $sid,
                             'message' => 'Attendance is taken for today only. Correct other days from the Attendance Correction page.'];
                continue;
            }

            /* "notMarked" from the old UI means: remove the row */
            if ($status === '' || $status === 'notmarked' || $status === 'none') {
                $del->execute([$sid, $date]);
                $cleared += $del->rowCount();
                continue;
            }
            if (!in_array($status, SR_STATUSES, true)) {
                $errors[] = ['index' => $i, 'message' => "Unknown status “{$status}”. Use present, absent or excused."];
                continue;
            }

            /* resolve enrolment (and therefore the grade) server-side */
            $key = $sid . ':' . $yid;
            if (!isset($gradeCache[$key])) {
                $q = $pdo->prepare("SELECT e.id, e.grade_id FROM enrollments e
                                    JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                                    WHERE e.student_id = ? AND e.academic_year_id = ? LIMIT 1");
                $q->execute([$sid, $yid]);
                $gradeCache[$key] = $q->fetch() ?: false;
            }
            $enr = $gradeCache[$key];
            if (!$enr) {
                $errors[] = ['index' => $i, 'student_id' => $sid, 'message' => 'This student is not enrolled in the selected academic year.'];
                continue;
            }
            $gid = (int)$enr['grade_id'];
            sr_assert_grade_access($gid, $yid);   // hard server-side scope check

            $et   = iso_to_et($date);
            $kind = iso_day_kind($date);          // sat | sun | other — never merged

            $ins->execute([
                $sid, (int)$enr['id'], $gid, $yid, $date,
                $et['y'], $et['m'], $et['d'], $kind,
                $status, $status === 'excused' ? ($reason !== '' ? mb_substr($reason, 0, 255) : null) : null,
                $uuid, $user['id'], $user['id'],
            ]);
            $saved++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('attendance.saved', 'attendance', null, "$saved mark(s) saved, $cleared cleared",
          ['year_ec' => $etY, 'saved' => $saved, 'cleared' => $cleared, 'errors' => count($errors)]);

    /* Partial success is normal for a bulk save, so the message has to name
       the rejected rows — otherwise a silent "0 saved" looks like success. */
    $msg = $saved . ' record(s) saved.';
    if ($cleared)        $msg .= ' ' . $cleared . ' cleared.';
    if ($errors)         $msg .= ' ' . count($errors) . ' row(s) could not be saved.';

    json_ok(['saved' => $saved, 'cleared' => $cleared, 'errors' => $errors],
            ['message' => $msg]);
}

/* ------------------------------------------------------------------
   mark_all — one status for every student on one date
   ------------------------------------------------------------------ */
case 'mark_all': {
    sr_methods(['POST']);
    require_cap('attendance.edit');
    $year    = resolve_year();
    $yid     = (int)$year['id'];
    $gradeId = (int)input_int('grade_id', 0);
    $date    = v_iso_date(input_str('date'), 'Date');
    $status  = v_in(strtolower(input_str('status')), SR_STATUSES, 'Status');
    $reason  = input_str('reason');
    $overwrite = input_bool('overwrite', true);

    if (!$gradeId) json_error('Please choose a class.', 422, 'validation');
    if ($date !== date('Y-m-d') && !in_array($user['role'], ['master','admin'], true))
        json_error('Attendance is taken for today only. Correct other days from the Attendance Correction page.', 422, 'validation');
    sr_assert_grade_access($gradeId, $yid);

    $st = db()->prepare("SELECT e.id enrollment_id, e.student_id FROM enrollments e
                         JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                         WHERE e.academic_year_id = ? AND e.grade_id = ? AND e.status IN ('active','repeated')");
    $st->execute([$yid, $gradeId]);
    $rows = $st->fetchAll();
    if (!$rows) json_error('There are no students in this class yet.', 404, 'empty_class');

    $et   = iso_to_et($date);
    $kind = iso_day_kind($date);
    $sql  = "INSERT INTO attendance
             (student_id, enrollment_id, grade_id, academic_year_id, session_date, et_year, et_month, et_day,
              day_of_week, status, excuse_reason, recorded_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $sql .= $overwrite
        ? " ON DUPLICATE KEY UPDATE status=VALUES(status), excuse_reason=VALUES(excuse_reason), updated_by=VALUES(updated_by), updated_at=NOW()"
        : " ON DUPLICATE KEY UPDATE id = id";
    $ins = db()->prepare($sql);

    $n = 0;
    db()->beginTransaction();
    foreach ($rows as $r) {
        $ins->execute([
            (int)$r['student_id'], (int)$r['enrollment_id'], $gradeId, $yid, $date,
            $et['y'], $et['m'], $et['d'], $kind, $status,
            $status === 'excused' && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            $user['id'], $user['id'],
        ]);
        $n++;
    }
    db()->commit();

    audit('attendance.mark_all', 'attendance', $gradeId,
          "Marked all $n student(s) $status on $date", ['date' => $date, 'status' => $status, 'et' => $et]);
    json_ok(['marked' => $n, 'date' => $date, 'status' => $status],
            ['message' => "All $n students marked $status."]);
}

/* ------------------------------------------------------------------
   copy_last_week — returns a PREVIEW by default; only writes when
   confirm=true, so the UI can let the user edit first.
   ------------------------------------------------------------------ */
case 'copy_last_week': {
    sr_methods(['POST', 'GET']);
    require_cap('attendance.edit');
    $year    = resolve_year();
    $yid     = (int)$year['id'];
    $gradeId = (int)input_int('grade_id', 0);
    $target  = v_iso_date(input_str('date'), 'Target date');
    if ($target !== date('Y-m-d') && !in_array($user['role'], ['master','admin'], true))
        json_error('Attendance is taken for today only. Correct other days from the Attendance Correction page.', 422, 'validation');
    $confirm = input_bool('confirm', false);
    if (!$gradeId) json_error('Please choose a class.', 422, 'validation');
    sr_assert_grade_access($gradeId, $yid);

    /* the same weekday, seven days earlier — Saturday copies Saturday */
    $source = input_str('source_date');
    if ($source === '') $source = date('Y-m-d', strtotime($target . ' -7 days'));
    v_iso_date($source, 'Source date');

    if (iso_day_kind($source) !== iso_day_kind($target)) {
        json_error('Copy only works between the same kind of day — Saturday to Saturday, Sunday to Sunday.', 422, 'day_mismatch');
    }

    $src = db()->prepare("SELECT a.student_id, a.status, a.excuse_reason, s.full_name, s.student_code
                          FROM attendance a JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                          WHERE a.grade_id = ? AND a.academic_year_id = ? AND a.session_date = ?");
    $src->execute([$gradeId, $yid, $source]);
    $rows = $src->fetchAll();
    if (!$rows) {
        json_error('There is nothing recorded for ' . et_label($source, 'en') . ' to copy from.', 404, 'no_source');
    }

    $ex = db()->prepare("SELECT student_id, status FROM attendance WHERE grade_id = ? AND academic_year_id = ? AND session_date = ?");
    $ex->execute([$gradeId, $yid, $target]);
    $existing = [];
    foreach ($ex->fetchAll() as $r) $existing[(int)$r['student_id']] = $r['status'];

    $preview = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        $preview[] = [
            'student_id'   => $sid,
            'student_code' => $r['student_code'],
            'full_name'    => $r['full_name'],
            'status'       => $r['status'],
            'reason'       => $r['excuse_reason'],
            'current'      => $existing[$sid] ?? null,
            'will_overwrite' => isset($existing[$sid]),
        ];
    }

    if (!$confirm) {
        json_ok([
            'preview'   => $preview,
            'source'    => ['date' => $source, 'label_am' => et_label($source, 'am'), 'label_en' => et_label($source, 'en')],
            'target'    => ['date' => $target, 'label_am' => et_label($target, 'am'), 'label_en' => et_label($target, 'en')],
            'overwrite_count' => count($existing),
            'committed' => false,
        ], ['message' => 'Review and edit before confirming.']);
    }

    $et   = iso_to_et($target);
    $kind = iso_day_kind($target);
    $ins  = db()->prepare("INSERT INTO attendance
        (student_id, enrollment_id, grade_id, academic_year_id, session_date, et_year, et_month, et_day,
         day_of_week, status, excuse_reason, recorded_by, updated_by)
        SELECT e.student_id, e.id, e.grade_id, e.academic_year_id, ?, ?, ?, ?, ?, ?, ?, ?, ?
        FROM enrollments e WHERE e.student_id = ? AND e.academic_year_id = ? LIMIT 1
        ON DUPLICATE KEY UPDATE status=VALUES(status), excuse_reason=VALUES(excuse_reason),
                                client_uuid=COALESCE(client_uuid, VALUES(client_uuid)),
                                updated_by=VALUES(updated_by), updated_at=NOW()");
    $n = 0;
    db()->beginTransaction();
    foreach ($preview as $p) {
        $ins->execute([$target, $et['y'], $et['m'], $et['d'], $kind, $p['status'], $p['reason'],
                       $user['id'], $user['id'], $p['student_id'], $yid]);
        $n++;
    }
    db()->commit();

    audit('attendance.copied', 'attendance', $gradeId, "Copied $n mark(s) from $source to $target",
          ['source' => $source, 'target' => $target, 'overwritten' => count($existing)]);
    json_ok(['copied' => $n, 'committed' => true], ['message' => "$n records copied to " . et_label($target, 'en') . '.']);
}

/* ------------------------------------------------------------------
   stats — dashboard numbers, always scoped to the caller
   ------------------------------------------------------------------ */
case 'stats': {
    sr_methods(['GET', 'POST']);
    require_cap('dashboard.view');
    $year  = resolve_year();
    $yid   = (int)$year['id'];
    $etY   = (int)$year['year_ec'];
    $scope = sr_scope_grades($yid);

    $where = ['a.academic_year_id = ?'];
    $args  = [$yid];
    $gradeId = input_int('grade_id');
    if ($gradeId) { sr_assert_grade_access($gradeId, $yid); $where[] = 'a.grade_id = ?'; $args[] = $gradeId; }
    elseif ($scope !== null) {
        if (!$scope) json_ok(sr_empty_stats($year));
        $where[] = 'a.grade_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $args = array_merge($args, $scope);
    }
    $month = input_int('month');
    if ($month) { $where[] = 'a.et_month = ?'; $args[] = $month; }
    $day = input_str('day');   // sat | sun
    if ($day !== '' && in_array($day, ['sat','sun','other'], true)) { $where[] = 'a.day_of_week = ?'; $args[] = $day; }
    $w = implode(' AND ', $where);

    /* Soft-deleted students must not count towards any figure on the dashboard.
       Their attendance rows stay in the table for history, but every aggregate
       joins through students so a removed student drops out of the totals. */
    $q = db()->prepare("SELECT a.status, a.day_of_week, COUNT(*) c FROM attendance a
                        JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                        WHERE $w GROUP BY a.status, a.day_of_week");
    $q->execute($args);
    $totals = ['present'=>0,'absent'=>0,'excused'=>0];
    $byDay  = ['sat'=>['present'=>0,'absent'=>0,'excused'=>0], 'sun'=>['present'=>0,'absent'=>0,'excused'=>0]];
    foreach ($q->fetchAll() as $r) {
        $totals[$r['status']] += (int)$r['c'];
        if (isset($byDay[$r['day_of_week']])) $byDay[$r['day_of_week']][$r['status']] += (int)$r['c'];
    }
    $marked = array_sum($totals);

    /* per Ethiopian month, 1..13 — Pagume included */
    $mq = db()->prepare("SELECT a.et_month, a.status, COUNT(*) c FROM attendance a
                         JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                         WHERE $w GROUP BY a.et_month, a.status");
    $mq->execute($args);
    $byMonth = [];
    for ($m = 1; $m <= 13; $m++) {
        $byMonth[$m] = ['month'=>$m,'name_am'=>SR_ET_MONTHS_AM[$m-1],'name_en'=>SR_ET_MONTHS_EN[$m-1],
                        'is_pagume'=>$m===13,'present'=>0,'absent'=>0,'excused'=>0,'total'=>0,'rate'=>0.0];
    }
    foreach ($mq->fetchAll() as $r) {
        $m = (int)$r['et_month'];
        if (!isset($byMonth[$m])) continue;
        $byMonth[$m][$r['status']] = (int)$r['c'];
        $byMonth[$m]['total'] += (int)$r['c'];
    }
    foreach ($byMonth as &$bm) $bm['rate'] = $bm['total'] ? round($bm['present'] * 100 / $bm['total'], 1) : 0.0;
    unset($bm);

    /* student head-count within scope */
    $sw = ['e.academic_year_id = ?']; $sa = [$yid];
    if ($gradeId) { $sw[] = 'e.grade_id = ?'; $sa[] = $gradeId; }
    elseif ($scope !== null && $scope) { $sw[] = 'e.grade_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $sa = array_merge($sa, $scope); }
    $sc = db()->prepare("SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
                         JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                         WHERE " . implode(' AND ', $sw) . " AND e.status IN ('active','repeated')");
    $sc->execute($sa);
    $studentCount = (int)$sc->fetchColumn();

    $gc = db()->prepare("SELECT COUNT(*) FROM grades g WHERE g.deleted_at IS NULL"
        . ($scope !== null && $scope ? " AND g.id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")" : ($scope !== null ? " AND 1=0" : "")));
    $gc->execute($scope !== null && $scope ? $scope : []);

    json_ok([
        'year'      => $year,
        'et_year'   => $etY,
        'students'  => $studentCount,
        'grades'    => (int)$gc->fetchColumn(),
        'present'   => $totals['present'],
        'absent'    => $totals['absent'],
        'excused'   => $totals['excused'],
        'marked'    => $marked,
        'rate'      => $marked ? round($totals['present'] * 100 / $marked, 1) : 0.0,
        'by_day'    => $byDay,
        'by_month'  => array_values($byMonth),
        'scoped'    => $scope !== null,
        'pagume_days' => et_month_days($etY, 13),
    ]);
}

/* ------------------------------------------------------------------
   alerts — students over the configurable absence threshold
   ------------------------------------------------------------------ */
case 'alerts': {
    sr_methods(['GET', 'POST']);
    require_cap('dashboard.view');
    $year  = resolve_year();
    $yid   = (int)$year['id'];
    $scope = sr_scope_grades($yid);
    $threshold = max(1, (int)setting('absence_alert_threshold', 3));
    $enabled   = (bool)setting('absence_alert_enabled', true);
    $lang      = input_str('lang', (string)setting('default_lang', 'am')) ?: 'am';
    $template  = (string)setting($lang === 'en' ? 'absence_alert_message_en' : 'absence_alert_message_am', '');

    $where = ['a.academic_year_id = ?']; $args = [$yid];
    if ($scope !== null) {
        if (!$scope) json_ok(['alerts' => [], 'threshold' => $threshold, 'enabled' => $enabled]);
        $where[] = 'a.grade_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $args = array_merge($args, $scope);
    }

    $q = db()->prepare("SELECT a.student_id, a.session_date, a.status, s.full_name, s.baptism_name, s.student_code,
                               g.id grade_id, g.name_am grade_am, g.name_en grade_en
                        FROM attendance a
                        JOIN students s ON s.id = a.student_id AND s.deleted_at IS NULL
                        JOIN grades  g ON g.id = a.grade_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY a.student_id, a.session_date DESC");
    $q->execute($args);

    $acc = [];
    foreach ($q->fetchAll() as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($acc[$sid])) $acc[$sid] = ['row' => $r, 'streak' => 0, 'done' => false, 'last' => $r['session_date']];
        if ($acc[$sid]['done']) continue;
        if ($r['status'] === 'absent') $acc[$sid]['streak']++;
        else $acc[$sid]['done'] = true;
    }

    $read = [];
    $rr = db()->prepare("SELECT alert_key FROM alert_reads WHERE user_id = ?");
    $rr->execute([$user['id']]);
    foreach ($rr->fetchAll() as $r) $read[$r['alert_key']] = true;

    $alerts = [];
    foreach ($acc as $sid => $a) {
        if ($a['streak'] < $threshold) continue;
        $row  = $a['row'];
        $grade = $lang === 'en' ? $row['grade_en'] : $row['grade_am'];
        $msg  = strtr($template, [
            '{days}'    => $lang === 'en' ? (string)$a['streak'] : to_geez($a['streak']),
            '{student}' => $row['full_name'],
            '{grade}'   => $grade,
        ]);
        $key = "absence:$sid:{$a['streak']}:{$year['year_ec']}";
        $alerts[] = [
            'key'          => $key,
            'student_id'   => $sid,
            'student_code' => $row['student_code'],
            'full_name'    => $row['full_name'],
            'baptism_name' => $row['baptism_name'],
            'grade_id'     => (int)$row['grade_id'],
            'grade'        => $grade,
            'days'         => $a['streak'],
            'days_geez'    => to_geez($a['streak']),
            'last_date'    => $a['last'],
            'last_label'   => et_label($a['last'], $lang),
            'message'      => $msg,
            'read'         => isset($read[$key]),
            'severity'     => $a['streak'] >= $threshold * 2 ? 'high' : 'normal',
        ];
    }
    usort($alerts, fn($x, $y) => $y['days'] <=> $x['days']);

    json_ok([
        'alerts'    => $enabled ? $alerts : [],
        'unread'    => count(array_filter($alerts, fn($a) => !$a['read'])),
        'threshold' => $threshold,
        'enabled'   => $enabled,
        'template'  => $template,
    ]);
}

/* ------------------------------------------------------------------ */
case 'mark_alert_read': {
    sr_methods(['POST']);
    require_login();
    $keys = input_array('keys');
    if (!$keys && input_str('key') !== '') $keys = [input_str('key')];
    $ins = db()->prepare("INSERT INTO alert_reads (user_id, alert_key) VALUES (?,?) ON DUPLICATE KEY UPDATE created_at = NOW()");
    foreach ($keys as $k) $ins->execute([$user['id'], mb_substr((string)$k, 0, 190)]);
    json_ok(['read' => count($keys)]);
}

/* ------------------------------------------------------------------
   history — a student's full attendance trail (incl. Pagume)
   ------------------------------------------------------------------ */
case 'history': {
    sr_methods(['GET', 'POST']);
    require_cap('attendance.view');
    $sid  = (int)input_int('student_id', 0);
    if (!$sid) json_error('A student id is required.', 422, 'validation');
    sr_assert_student_access($sid, null);
    $lang = input_str('lang', 'am') ?: 'am';

    $where = ['a.student_id = ?']; $args = [$sid];
    $yearEc = input_int('year_ec');
    if ($yearEc) { $where[] = 'a.et_year = ?'; $args[] = $yearEc; }
    $month = input_int('month');
    if ($month) { $where[] = 'a.et_month = ?'; $args[] = $month; }

    $q = db()->prepare("SELECT a.*, g.name_am grade_am, g.name_en grade_en, u.full_name recorded_by_name
                        FROM attendance a
                        JOIN grades g ON g.id = a.grade_id
                        LEFT JOIN users u ON u.id = a.recorded_by
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY a.session_date DESC LIMIT 500");
    $q->execute($args);

    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $rows[] = [
            'date'        => $r['session_date'],
            'label'       => et_label($r['session_date'], $lang),
            'et_year'     => (int)$r['et_year'],
            'et_month'    => (int)$r['et_month'],
            'et_day'      => (int)$r['et_day'],
            'month_name'  => $lang === 'en' ? SR_ET_MONTHS_EN[(int)$r['et_month'] - 1] : SR_ET_MONTHS_AM[(int)$r['et_month'] - 1],
            'is_pagume'   => (int)$r['et_month'] === 13,
            'day'         => $r['day_of_week'],
            'status'      => $r['status'],
            'reason'      => $r['excuse_reason'],
            'grade'       => $lang === 'en' ? $r['grade_en'] : $r['grade_am'],
            'recorded_by' => $r['recorded_by_name'],
            'recorded_at' => $r['created_at'],
        ];
    }
    json_ok(['history' => $rows, 'count' => count($rows)]);
}

/* ------------------------------------------------------------------
   lookup — the existing record for one student on one date.

   Feeds the Attendance Correction page: before anything is changed the
   user is shown what is currently stored, so a correction is always a
   deliberate edit of a known value rather than a blind overwrite.
   ------------------------------------------------------------------ */
case 'lookup': {
    sr_methods(['GET', 'POST']);
    require_cap('attendance.correct');
    $year = resolve_year();
    $yid  = (int)$year['id'];

    $sid  = input_int('student_id');
    $date = input_str('date');
    if (!$sid)                                        json_error('Choose a student.', 422, 'validation');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))  json_error('Choose a valid date.', 422, 'validation');

    /* Scope check first: a teacher may only look into their own grades. */
    sr_assert_student_access($sid, $yid);

    $q = db()->prepare("SELECT s.id, s.full_name, s.baptism_name, s.student_code, s.photo_path,
                               g.id grade_id, g.name_am grade_am, g.name_en grade_en, e.roll_no
                        FROM students s
                        JOIN enrollments e ON e.student_id = s.id AND e.academic_year_id = ?
                        JOIN grades g ON g.id = e.grade_id
                        WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1");
    $q->execute([$yid, $sid]);
    $stu = $q->fetch();
    if (!$stu) json_error('That student is not enrolled in the selected academic year.', 404, 'not_found');

    $r = db()->prepare("SELECT a.*, ru.full_name recorded_by_name, uu.full_name updated_by_name
                        FROM attendance a
                        LEFT JOIN users ru ON ru.id = a.recorded_by
                        LEFT JOIN users uu ON uu.id = a.updated_by
                        WHERE a.student_id = ? AND a.session_date = ? LIMIT 1");
    $r->execute([$sid, $date]);
    $rec = $r->fetch();

    $et = iso_to_et($date);
    json_ok([
        'student' => [
            'id'       => (int)$stu['id'],
            'name'     => $stu['full_name'],
            'baptism'  => $stu['baptism_name'],
            'code'     => $stu['student_code'],
            'photo'    => $stu['photo_path'],
            'grade_id' => (int)$stu['grade_id'],
            'grade_am' => $stu['grade_am'],
            'grade_en' => $stu['grade_en'],
            'roll_no'  => $stu['roll_no'],
        ],
        'date'      => $date,
        'et_label'  => et_label($date, $lang ?? 'am'),
        'day'       => iso_day_kind($date),
        'et_year'   => $et['y'],
        'record'    => $rec ? [
            'id'          => (int)$rec['id'],
            'status'      => $rec['status'],
            'reason'      => $rec['excuse_reason'],
            'recorded_by' => $rec['recorded_by_name'],
            'updated_by'  => $rec['updated_by_name'],
            'recorded_at' => $rec['created_at'],
            'updated_at'  => $rec['updated_at'],
        ] : null,
        'exists'    => (bool)$rec,
    ]);
}

/* ------------------------------------------------------------------
   correct — change an existing mark in place.

   Distinct from `save` on purpose:
     * it requires the separate attendance.correct capability,
     * it moves freely between present / absent / excused in BOTH
       directions (not just absent -> excused),
     * it NEVER creates a second row for the same student and date —
       an existing row is UPDATEd by primary key, and the
       UNIQUE(student_id, session_date) index is the backstop,
     * it records the previous value in the audit log.

   Every downstream figure (percentages, absence counts, excused counts,
   consecutive-absence streaks, alerts, dashboard, reports) is computed
   live from this table, so correcting the row here is what makes them
   all move — in either direction.
   ------------------------------------------------------------------ */
case 'correct': {
    sr_methods(['POST']);
    require_cap('attendance.correct');
    $year = resolve_year();
    $yid  = (int)$year['id'];
    $etY  = (int)$year['year_ec'];

    $sid    = input_int('student_id');
    $date   = input_str('date');
    $status = strtolower(trim(input_str('status')));
    $reason = trim(input_str('reason'));
    $note   = trim(input_str('note'));

    if (!$sid)                                       json_error('Choose a student.', 422, 'validation');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Choose a valid date.', 422, 'validation');
    /* A correction fixes what already happened. Attendance cannot be
       recorded for a day that has not arrived yet. */
    if ($date > date('Y-m-d'))                       json_error('That date is in the future.', 422, 'validation');
    if (!in_array($status, [...SR_STATUSES, 'none'], true))
                                                     json_error('Choose present, absent or excused.', 422, 'validation');
    /* The correction dialog asks WHY once; for an excused day that answer
       doubles as the excuse reason, so either field may carry it. */
    if ($status === 'excused' && $reason === '' && $note === '')
                                                     json_error('An excused record needs a reason.', 422, 'validation');

    sr_assert_student_access($sid, $yid);

    /* Resolve enrolment server-side — never trust a posted grade_id. */
    $e = db()->prepare("SELECT e.id, e.grade_id, s.full_name, s.student_code
                        FROM enrollments e
                        JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                        WHERE e.student_id = ? AND e.academic_year_id = ? LIMIT 1");
    $e->execute([$sid, $yid]);
    $enr = $e->fetch();
    if (!$enr) json_error('That student is not enrolled in the selected academic year.', 404, 'not_found');
    $gid = (int)$enr['grade_id'];
    sr_assert_grade_access($gid, $yid);

    $cur = db()->prepare("SELECT id, status, excuse_reason FROM attendance
                          WHERE student_id = ? AND session_date = ? LIMIT 1");
    $cur->execute([$sid, $date]);
    $old = $cur->fetch();

    $excuse = $status === 'excused' ? mb_substr($reason !== '' ? $reason : $note, 0, 255) : null;
    $et     = iso_to_et($date);
    $kind   = iso_day_kind($date);

    /* Clearing a mark is a correction too — the row is removed in place. */
    if ($status === 'none') {
        $deleted = false;
        if ($old) {
            $deleted = db()->prepare('DELETE FROM attendance WHERE id = ?')->execute([(int)$old['id']]) ? true : false;
        }
        audit('attendance.corrected', 'attendance', $sid,
              "{$enr['full_name']} ({$enr['student_code']}) on {$date}: "
              . ($old ? $old['status'] : 'no record') . ' -> none',
              ['student_id' => $sid, 'date' => $date, 'from' => $old ? $old['status'] : null, 'to' => 'none',
               'reason' => null, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
               'created' => false, 'year_ec' => $etY]);
        json_ok(['changed' => $deleted, 'status' => 'none', 'previous' => $old ? $old['status'] : null,
                 'created' => false], ['message' => $deleted ? 'Record cleared.' : 'There was nothing to clear.']);
    }

    if ($old) {
        if ($old['status'] === $status && (string)$old['excuse_reason'] === (string)$excuse) {
            json_ok(['changed' => false, 'status' => $status, 'previous' => $old['status'], 'created' => false],
                    ['message' => 'That record already reads ' . $status . '. Nothing was changed.']);
        }
        /* UPDATE by primary key — in place, so no duplicate can appear. */
        db()->prepare("UPDATE attendance
                       SET status = ?, excuse_reason = ?, grade_id = ?, enrollment_id = ?,
                           updated_by = ?, updated_at = NOW()
                       WHERE id = ?")
            ->execute([$status, $excuse, $gid, (int)$enr['id'], $user['id'], (int)$old['id']]);
        $created  = false;
        $previous = $old['status'];
    } else {
        /* No record for that day yet: a correction may legitimately supply
           one (a session that was missed entirely). Still a single row. */
        db()->prepare("INSERT INTO attendance
              (student_id, enrollment_id, grade_id, academic_year_id, session_date, et_year, et_month, et_day,
               day_of_week, status, excuse_reason, recorded_by, updated_by)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                status = VALUES(status), excuse_reason = VALUES(excuse_reason),
                updated_by = VALUES(updated_by), updated_at = NOW()")
            ->execute([$sid, (int)$enr['id'], $gid, $yid, $date,
                       $et['y'], $et['m'], $et['d'], $kind,
                       $status, $excuse, $user['id'], $user['id']]);
        $created  = true;
        $previous = null;
    }

    audit('attendance.corrected', 'attendance', $sid,
          "{$enr['full_name']} ({$enr['student_code']}) on {$date}: "
          . ($previous ?? 'no record') . " -> {$status}",
          ['student_id' => $sid, 'date' => $date, 'from' => $previous, 'to' => $status,
           'reason' => $excuse, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
           'created' => $created, 'year_ec' => $etY]);

    /* Return the recalculated figures for this student so the page can show
       the effect of the correction straight away. */
    $sum = db()->prepare("SELECT status, COUNT(*) c FROM attendance
                          WHERE student_id = ? AND academic_year_id = ? GROUP BY status");
    $sum->execute([$sid, $yid]);
    $tot = ['present' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($sum->fetchAll() as $r) $tot[$r['status']] = (int)$r['c'];
    $marked = array_sum($tot);

    json_ok([
        'changed'  => true,
        'created'  => $created,
        'previous' => $previous,
        'status'   => $status,
        'summary'  => [
            'present' => $tot['present'], 'absent' => $tot['absent'], 'excused' => $tot['excused'],
            'total'   => $marked,
            'rate'    => $marked ? round($tot['present'] * 100 / $marked, 1) : 0.0,
        ],
        'streak'   => sr_absence_streaks([$sid], $yid)[$sid] ?? 0,
    ], ['message' => $created
            ? 'Record created as ' . $status . '.'
            : 'Corrected from ' . $previous . ' to ' . $status . '.']);
}

/* ------------------------------------------------------------------
   sync — offline PWA queue replay, idempotent via client_uuid
   ------------------------------------------------------------------ */
case 'sync': {
    sr_methods(['POST']);
    require_cap('attendance.edit');
    /* Accept either key: the offline queue in js/ posts "queue", while
       hand-rolled clients tend to post "items". */
    $queue = input_array('queue') ?: input_array('items');
    if (!$queue) json_ok(['synced' => 0, 'failed' => 0, 'results' => []],
                         ['message' => 'Nothing to sync.']);
    if (count($queue) > 2000) json_error('Sync batch too large. Send at most 2000 items.', 413, 'too_large');

    $year = resolve_year();
    $yid  = (int)$year['id'];
    $results = [];
    $ok = 0;

    $ins = db()->prepare("INSERT INTO attendance
        (student_id, enrollment_id, grade_id, academic_year_id, session_date, et_year, et_month, et_day,
         day_of_week, status, excuse_reason, client_uuid, recorded_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE status=VALUES(status), excuse_reason=VALUES(excuse_reason),
                                updated_by=VALUES(updated_by), updated_at=NOW()");

    foreach ($queue as $item) {
        $uuid = substr((string)($item['client_uuid'] ?? ''), 0, 36);
        $sid  = (int)($item['student_id'] ?? 0);
        $date = (string)($item['date'] ?? '');
        $status = strtolower((string)($item['status'] ?? ''));
        try {
            if (!$uuid) throw new RuntimeException('Missing client_uuid.');
            if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Missing student or date.');
            if (!in_array($status, SR_STATUSES, true)) throw new RuntimeException('Unknown status.');

            $q = db()->prepare("SELECT e.id, e.grade_id FROM enrollments e
                                JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                                WHERE e.student_id = ? AND e.academic_year_id = ? LIMIT 1");
            $q->execute([$sid, $yid]);
            $enr = $q->fetch();
            if (!$enr) throw new RuntimeException('Student is not enrolled this year.');
            sr_assert_grade_access((int)$enr['grade_id'], $yid);

            $et = iso_to_et($date);
            $ins->execute([$sid, (int)$enr['id'], (int)$enr['grade_id'], $yid, $date,
                           $et['y'], $et['m'], $et['d'], iso_day_kind($date), $status,
                           $status === 'excused'
                               ? ((($item['reason'] ?? '') ?: ($item['excuse_reason'] ?? '')) ?: null)
                               : null,
                           $uuid, $user['id'], $user['id']]);
            $results[] = ['client_uuid' => $uuid, 'state' => 'synced'];
            $ok++;
        } catch (Throwable $e) {
            $results[] = ['client_uuid' => $uuid, 'state' => 'failed', 'message' => $e->getMessage()];
        }
    }

    $failed = count($queue) - $ok;
    audit('attendance.synced', 'attendance', null, "Offline sync: $ok of " . count($queue) . ' item(s)');
    json_ok(['synced' => $ok, 'failed' => $failed, 'results' => $results],
            ['message' => $failed
                ? "$ok mark(s) synced, $failed failed."
                : "$ok mark(s) synced."]);
}

default:
    json_error('Unknown attendance action: ' . $action, 404, 'unknown_action');
}

function sr_empty_stats(array $year): array
{
    $bm = [];
    for ($m = 1; $m <= 13; $m++) {
        $bm[] = ['month'=>$m,'name_am'=>SR_ET_MONTHS_AM[$m-1],'name_en'=>SR_ET_MONTHS_EN[$m-1],
                 'is_pagume'=>$m===13,'present'=>0,'absent'=>0,'excused'=>0,'total'=>0,'rate'=>0.0];
    }
    return ['year'=>$year,'students'=>0,'grades'=>0,'present'=>0,'absent'=>0,'excused'=>0,
            'marked'=>0,'rate'=>0.0,'by_day'=>['sat'=>[],'sun'=>[]],'by_month'=>$bm,'scoped'=>true];
}
