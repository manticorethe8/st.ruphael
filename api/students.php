<?php
/* api/students.php — student CRUD, search, details, soft delete. */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------ */
case 'list': {
    sr_methods(['GET', 'POST']);
    require_cap('students.view');
    $year  = resolve_year();
    $yid   = (int)$year['id'];
    $scope = sr_scope_grades($yid);

    $q        = input_str('q');
    $gradeId  = input_int('grade_id');
    $status   = input_str('status');            // active|inactive|graduated|transferred
    $gender   = input_str('gender');
    $page     = max(1, (int)input_int('page', 1));
    $perPage  = min(500, max(1, (int)input_int('per_page', 100)));
    $includeDeleted = input_bool('include_deleted') && can('students.delete');

    $where = ["e.academic_year_id = ?"];
    $args  = [$yid];
    if (!$includeDeleted) $where[] = "s.deleted_at IS NULL";

    if ($gradeId) {
        sr_assert_grade_access($gradeId, $yid);
        $where[] = "e.grade_id = ?";
        $args[]  = $gradeId;
    } elseif ($scope !== null) {
        if (!$scope) json_ok(['students' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'year' => $year]);
        $where[] = "e.grade_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
        $args    = array_merge($args, $scope);
    }
    if ($q !== '') {
        /* Forgiving match: every word typed must appear somewhere in the row,
           so an abbreviation like "a s" finds "Aron Shinles". */
        [$qSql, $qArgs] = sr_search_clause($q, [
            's.full_name', 's.baptism_name', 's.student_code',
            's.guardian_name', 's.phone',
            "REPLACE(s.student_code, '-', '')",
        ]);
        if ($qSql !== '') { $where[] = $qSql; $args = array_merge($args, $qArgs); }
    }
    if ($status !== '') { $where[] = "s.status = ?"; $args[] = $status; }
    if ($gender !== '') { $where[] = "s.gender = ?"; $args[] = $gender; }

    $w = implode(' AND ', $where);

    $cnt = db()->prepare("SELECT COUNT(*) FROM enrollments e JOIN students s ON s.id = e.student_id WHERE $w");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $sortMap = [
        'name'  => 's.full_name', 'code' => 's.student_code', 'grade' => 'g.sort_order',
        'roll'  => 'e.roll_no',   'created' => 's.created_at',
    ];
    $sortCol = $sortMap[input_str('sort', 'grade')] ?? 'g.sort_order';
    $dir     = strtolower(input_str('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $offset  = ($page - 1) * $perPage;

    $sql = "SELECT s.*, e.id AS enrollment_id, e.grade_id, e.roll_no, e.status AS enroll_status, e.result,
                   g.code AS grade_code, g.name_am AS grade_am, g.name_en AS grade_en,
                   g.badge_am, g.badge_en, g.tone
            FROM enrollments e
            JOIN students s ON s.id = e.student_id
            JOIN grades   g ON g.id = e.grade_id
            WHERE $w
            ORDER BY $sortCol $dir, s.full_name ASC
            LIMIT $perPage OFFSET $offset";
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    /* absence streaks power the red alert badge */
    $threshold = (int)setting('absence_alert_threshold', 3);
    $streaks   = sr_absence_streaks(array_column($rows, 'id'), $yid);

    $out = [];
    foreach ($rows as $r) {
        $s = sr_student_out($r);
        $s['absence_streak'] = $streaks[(int)$r['id']] ?? 0;
        $s['alert']          = $s['absence_streak'] >= $threshold;
        $out[] = $s;
    }

    json_ok([
        'students'  => $out,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'pages'     => (int)ceil($total / $perPage),
        'year'      => $year,
        'threshold' => $threshold,
    ]);
}

/* ------------------------------------------------------------------ */
case 'get': {
    sr_methods(['GET', 'POST']);
    require_cap('students.view');
    $id   = (int)input_int('id', 0);
    $year = resolve_year();
    if (!$id) json_error('A student id is required.', 422, 'validation');
    sr_assert_student_access($id, null);   // any year the teacher owns

    $st = db()->prepare("SELECT * FROM students WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) json_error('That student does not exist.', 404, 'not_found');

    /* enrolment history across every year = the preserved record */
    $h = db()->prepare("SELECT e.*, g.code grade_code, g.name_am grade_am, g.name_en grade_en,
                               y.year_ec, y.label_am year_am, y.label_en year_en
                        FROM enrollments e
                        JOIN grades g ON g.id = e.grade_id
                        JOIN academic_years y ON y.id = e.academic_year_id
                        WHERE e.student_id = ? ORDER BY y.year_ec DESC");
    $h->execute([$id]);
    $history = $h->fetchAll();

    $p = db()->prepare("SELECT p.*, fg.name_en from_grade, tg.name_en to_grade,
                               fy.year_ec from_year, ty.year_ec to_year, u.full_name by_name
                        FROM promotions p
                        LEFT JOIN grades fg ON fg.id = p.from_grade_id
                        LEFT JOIN grades tg ON tg.id = p.to_grade_id
                        LEFT JOIN academic_years fy ON fy.id = p.from_year_id
                        LEFT JOIN academic_years ty ON ty.id = p.to_year_id
                        LEFT JOIN users u ON u.id = p.performed_by
                        WHERE p.student_id = ? ORDER BY p.created_at DESC");
    $p->execute([$id]);

    /* attendance summary for the requested year */
    $a = db()->prepare("SELECT status, COUNT(*) c FROM attendance
                        WHERE student_id = ? AND academic_year_id = ? GROUP BY status");
    $a->execute([$id, $year['id']]);
    $summary = ['present' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($a->fetchAll() as $r) $summary[$r['status']] = (int)$r['c'];
    $marked = array_sum($summary);
    $summary['total']  = $marked;
    $summary['rate']   = $marked ? round($summary['present'] * 100 / $marked, 1) : 0.0;

    /* per-month breakdown incl. Pagume (month 13) */
    $m = db()->prepare("SELECT et_month, status, COUNT(*) c FROM attendance
                        WHERE student_id = ? AND academic_year_id = ? GROUP BY et_month, status");
    $m->execute([$id, $year['id']]);
    $byMonth = [];
    foreach ($m->fetchAll() as $r) $byMonth[(int)$r['et_month']][$r['status']] = (int)$r['c'];

    $streak = sr_absence_streaks([$id], (int)$year['id'])[$id] ?? 0;

    json_ok([
        'student'    => sr_student_out($s),
        'enrollments'=> $history,
        'promotions' => $p->fetchAll(),
        'attendance' => ['summary' => $summary, 'by_month' => $byMonth],
        'absence_streak' => $streak,
        'threshold'  => (int)setting('absence_alert_threshold', 3),
        'year'       => $year,
    ]);
}

/* ------------------------------------------------------------------ */
case 'create': {
    sr_methods(['POST']);
    require_cap('students.create');
    $year = resolve_year();
    $yid  = (int)$year['id'];

    $data    = sr_read_student_input();
    $gradeId = (int)input_int('grade_id', 0);
    if (!$gradeId) json_error('Please choose a grade for this student.', 422, 'validation');
    sr_assert_grade_access($gradeId, $yid);

    /* --- Student ID -------------------------------------------------
       Generated server-side. Anything the client sends is IGNORED unless
       SR_MANUAL_IDENTIFIERS is on in config/config.php — the browser
       greys the field out, but a crafted request must not be able to
       bypass that. See the block comment above next_student_code(). */
    $code = '';
    if (SR_MANUAL_IDENTIFIERS) {
        $code = input_str('student_code');
        if ($code !== '') {
            $code = v_len($code, 'Student ID', 40);
            $d = db()->prepare("SELECT id FROM students WHERE student_code = ?");
            $d->execute([$code]);
            if ($d->fetchColumn()) json_error("Student ID “{$code}” is already in use.", 409, 'duplicate_code');
        }
    }
    if ($code === '') $code = next_student_code((int)$year['year_ec']);

    /* --- Roll number ------------------------------------------------
       Per grade, per year; see next_roll_no(). Same rule as the ID: the
       client's value only counts when manual entry is switched on. */
    $rollNo = null;
    if (SR_MANUAL_IDENTIFIERS) $rollNo = input_int('roll_no');
    if (!$rollNo) $rollNo = next_roll_no($gradeId, $yid);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO students
            (student_code, full_name, baptism_name, gender, birth_date, phone, guardian_name, guardian_phone, address, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $code, $data['full_name'], $data['baptism_name'], $data['gender'], $data['birth_date'],
            $data['phone'], $data['guardian_name'], $data['guardian_phone'], $data['address'],
            $data['notes'], $data['status'], $user['id'],
        ]);
        $sid = (int)$pdo->lastInsertId();

        $en = $pdo->prepare("INSERT INTO enrollments (student_id, grade_id, academic_year_id, roll_no, enrolled_on)
                             VALUES (?,?,?,?,CURDATE())");
        $en->execute([$sid, $gradeId, $yid, $rollNo]);

        /* The photo is saved after the INSERT because the filename embeds
           the student id. Validation happens inside sr_save_photo(). */
        $photo = input_str('photo');
        if ($photo !== '') {
            $rel = sr_save_photo($photo, $sid);
            $pdo->prepare("UPDATE students SET photo_path = ? WHERE id = ?")->execute([$rel, $sid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('student.created', 'student', $sid, "Student {$data['full_name']} ($code) added", ['grade_id' => $gradeId, 'year_ec' => $year['year_ec']]);
    json_ok(['id' => $sid, 'student_code' => $code], ['message' => 'Student added.']);
}

/* ------------------------------------------------------------------ */
case 'update': {
    sr_methods(['POST']);
    require_cap('students.edit');
    $id   = (int)input_int('id', 0);
    $year = resolve_year();
    $yid  = (int)$year['id'];
    if (!$id) json_error('A student id is required.', 422, 'validation');
    sr_assert_student_access($id, $yid);

    $cur = db()->prepare("SELECT * FROM students WHERE id = ? AND deleted_at IS NULL");
    $cur->execute([$id]);
    $existing = $cur->fetch();
    if (!$existing) json_error('That student could not be found.', 404, 'not_found');

    $data = sr_read_student_input($existing);

    /* A student's ID is their permanent identity — it appears on printed
       ID cards and in every historical record — so editing it is refused
       outright unless manual identifiers are switched on. */
    $code = '';
    if (SR_MANUAL_IDENTIFIERS) {
        $code = input_str('student_code');
        if ($code !== '') {
            $d = db()->prepare("SELECT id FROM students WHERE student_code = ? AND id <> ?");
            $d->execute([$code, $id]);
            if ($d->fetchColumn()) json_error("Student ID “{$code}” is already used by another student.", 409, 'duplicate_code');
        }
    }

    $pdo = db();
    $sql = "UPDATE students SET full_name=?, baptism_name=?, gender=?, birth_date=?, phone=?,
            guardian_name=?, guardian_phone=?, address=?, notes=?, status=?";
    $args = [$data['full_name'], $data['baptism_name'], $data['gender'], $data['birth_date'], $data['phone'],
             $data['guardian_name'], $data['guardian_phone'], $data['address'], $data['notes'], $data['status']];
    if ($code !== '') { $sql .= ", student_code = ?"; $args[] = $code; }

    /* --- Photo ------------------------------------------------------
       Three different intentions have to be told apart:
         'photo' absent          → leave the current picture alone
                                   (most saves never touch it)
         'photo' = data URL      → replace it, and delete the old file
         'photo_remove' = 1      → clear it back to the default avatar   */
    $oldPhoto = $existing['photo_path'] ?? null;
    $newPhoto = null; $dropPhoto = false;

    if ((string)input_str('photo_remove') === '1') {
        $dropPhoto = true;
        $sql .= ", photo_path = NULL";
    } elseif (($p = input_str('photo')) !== '') {
        $newPhoto = sr_save_photo($p, $id);
        $sql .= ", photo_path = ?";
        $args[] = $newPhoto;
    }

    $sql .= " WHERE id = ? AND deleted_at IS NULL";
    $args[] = $id;
    $pdo->prepare($sql)->execute($args);

    /* Only unlink once the row is safely updated — a failed UPDATE must
       not leave the database pointing at a file we already deleted. */
    if (($dropPhoto || $newPhoto) && $oldPhoto && $oldPhoto !== $newPhoto) sr_delete_photo($oldPhoto);

    /* moving a student between grades within the same year keeps one identity */
    $gradeId = input_int('grade_id');
    if ($gradeId) {
        sr_assert_grade_access($gradeId, $yid);

        /* Work out the roll number to store. Keeping the existing one is
           the common case: re-saving the form must not renumber anybody.
           A new number is only minted when the student has none, or when
           they have actually moved to a different grade (roll numbers are
           per-class, so the old number belongs to the old class). */
        $curEn = $pdo->prepare("SELECT grade_id, roll_no FROM enrollments
                                 WHERE student_id = ? AND academic_year_id = ?");
        $curEn->execute([$id, $yid]);
        $enRow = $curEn->fetch();

        $rollNo = SR_MANUAL_IDENTIFIERS ? input_int('roll_no') : null;
        if (!$rollNo) {
            $moved = $enRow && (int)$enRow['grade_id'] !== $gradeId;
            $rollNo = ($enRow && $enRow['roll_no'] && !$moved)
                ? (int)$enRow['roll_no']
                : next_roll_no($gradeId, $yid);
        }

        /* Branch on whether the row EXISTS, never on rowCount(): MySQL
           reports 0 affected rows when an UPDATE writes the same values
           it already held, which would send an unchanged save down the
           INSERT path and trip the UNIQUE(student_id, academic_year_id)
           constraint. */
        if ($enRow) {
            $pdo->prepare("UPDATE enrollments SET grade_id = ?, roll_no = ?
                            WHERE student_id = ? AND academic_year_id = ?")
                ->execute([$gradeId, $rollNo, $id, $yid]);
        } else {
            $pdo->prepare("INSERT INTO enrollments (student_id, grade_id, academic_year_id, roll_no, enrolled_on)
                           VALUES (?,?,?,?,CURDATE())")
                ->execute([$id, $gradeId, $yid, $rollNo]);
        }
        /* keep historical attendance rows pointing at the right grade for this year */
        $pdo->prepare("UPDATE attendance SET grade_id = ? WHERE student_id = ? AND academic_year_id = ?")
            ->execute([$gradeId, $id, $yid]);
    }

    audit('student.updated', 'student', $id, "Student {$data['full_name']} updated", ['grade_id' => $gradeId]);
    json_ok(['id' => $id], ['message' => 'Changes saved.']);
}

/* ------------------------------------------------------------------ */
case 'delete': {
    sr_methods(['POST']);
    require_cap('students.delete');
    $id = (int)input_int('id', 0);
    if (!$id) json_error('A student id is required.', 422, 'validation');
    sr_assert_student_access($id, null);

    $st = db()->prepare("SELECT full_name, student_code FROM students WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) json_error('That student does not exist or is already removed.', 404, 'not_found');

    /* SOFT delete — attendance history is deliberately preserved */
    db()->prepare("UPDATE students SET deleted_at = NOW(), status = 'inactive' WHERE id = ?")->execute([$id]);

    $c = db()->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ?");
    $c->execute([$id]);
    audit('student.deleted', 'student', $id, "Student {$s['full_name']} ({$s['student_code']}) removed (soft delete)",
          ['attendance_rows_preserved' => (int)$c->fetchColumn()]);

    json_ok(['deleted' => true, 'soft' => true], ['message' => 'Student removed. Their attendance history has been kept.']);
}

/* ------------------------------------------------------------------ */
case 'restore': {
    sr_methods(['POST']);
    require_cap('students.delete');
    $id = (int)input_int('id', 0);
    db()->prepare("UPDATE students SET deleted_at = NULL, status = 'active' WHERE id = ?")->execute([$id]);
    audit('student.restored', 'student', $id, 'Student restored');
    json_ok(['restored' => true], ['message' => 'Student restored.']);
}

/* ------------------------------------------------------------------ */
case 'bulk_delete': {
    sr_methods(['POST']);
    require_cap('students.delete');
    $ids = array_values(array_unique(array_map('intval', input_array('ids'))));
    if (!$ids) json_error('Select at least one student.', 422, 'validation');
    foreach ($ids as $id) sr_assert_student_access($id, null);
    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE students SET deleted_at = NOW(), status='inactive' WHERE id IN ($in) AND deleted_at IS NULL")->execute($ids);
    audit('student.bulk_deleted', 'student', null, count($ids) . ' students removed (soft delete)', ['ids' => $ids]);
    json_ok(['deleted' => count($ids)], ['message' => count($ids) . ' students removed. Attendance history kept.']);
}

default:
    json_error('Unknown students action: ' . $action, 404, 'unknown_action');
}

/* ============================ helpers ============================ */
/**
 * Read the student fields from the request.
 *
 * When $base is supplied (an existing row) the update behaves as a partial
 * patch: any key the caller did not send keeps its current value, so an edit
 * form that posts only the changed inputs cannot blank out the rest.
 */
function sr_read_student_input(?array $base = null): array
{
    $has  = static fn(string $k): bool => input($k) !== null;
    $keep = static fn(string $k, $d = null) => $base !== null ? ($base[$k] ?? $d) : $d;

    if ($base !== null && !$has('full_name')) {
        $name = (string)$base['full_name'];
    } else {
        $name = v_len(v_required(input_str('full_name'), 'Full name'), 'Full name', 160, 2);
    }

    $bd = $has('birth_date') ? input_str('birth_date') : (string)($keep('birth_date') ?? '');
    if ($bd !== '') v_iso_date($bd, 'Birth date');

    $str = static function (string $k, int $max) use ($has, $keep, $base): ?string {
        if (!$has($k) && $base !== null) {
            $v = $keep($k);
            return ($v === null || $v === '') ? null : (string)$v;
        }
        return v_len(input_str($k), ucfirst(str_replace('_', ' ', $k)), $max) ?: null;
    };

    if ($base !== null) {
        return [
            'full_name'      => $name,
            'baptism_name'   => $str('baptism_name', 160),
            'gender'         => $has('gender')
                ? v_in(input_str('gender') ?: 'male', ['male','female'], 'Gender')
                : (string)($keep('gender', 'male')),
            'birth_date'     => $bd ?: null,
            'phone'          => $str('phone', 40),
            'guardian_name'  => $str('guardian_name', 160),
            'guardian_phone' => $str('guardian_phone', 40),
            'address'        => $str('address', 255),
            'notes'          => $has('notes') ? (input_str('notes') ?: null) : ($keep('notes') ?: null),
            'status'         => $has('status')
                ? v_in(input_str('status') ?: 'active', ['active','inactive','graduated','transferred'], 'Status')
                : (string)($keep('status', 'active')),
        ];
    }

    return [
        'full_name'      => $name,
        'baptism_name'   => v_len(input_str('baptism_name'), 'Baptism name', 160) ?: null,
        'gender'         => v_in(input_str('gender', 'male') ?: 'male', ['male','female'], 'Gender'),
        'birth_date'     => $bd ?: null,
        'phone'          => v_len(input_str('phone'), 'Phone', 40) ?: null,
        'guardian_name'  => v_len(input_str('guardian_name'), 'Guardian name', 160) ?: null,
        'guardian_phone' => v_len(input_str('guardian_phone'), 'Guardian phone', 40) ?: null,
        'address'        => v_len(input_str('address'), 'Address', 255) ?: null,
        'notes'          => input_str('notes') ?: null,
        'status'         => v_in(input_str('status', 'active') ?: 'active', ['active','inactive','graduated','transferred'], 'Status'),
    ];
}

function sr_student_out(array $r): array
{
    $o = [
        'id'            => (int)$r['id'],
        'student_code'  => $r['student_code'],
        'full_name'     => $r['full_name'],
        'baptism_name'  => $r['baptism_name'],
        'gender'        => $r['gender'],
        'birth_date'    => $r['birth_date'],
        'phone'         => $r['phone'],
        'guardian_name' => $r['guardian_name'],
        'guardian_phone'=> $r['guardian_phone'],
        'address'       => $r['address'],
        'photo_path'    => $r['photo_path'],
        'notes'         => $r['notes'],
        'status'        => $r['status'],
        'deleted'       => !empty($r['deleted_at']),
        'created_at'    => $r['created_at'] ?? null,
    ];
    foreach (['enrollment_id','grade_id','roll_no'] as $k) {
        if (array_key_exists($k, $r)) $o[$k] = $r[$k] !== null ? (int)$r[$k] : null;
    }
    foreach (['grade_code','grade_am','grade_en','badge_am','badge_en','tone','enroll_status','result'] as $k) {
        if (array_key_exists($k, $r)) $o[$k] = $r[$k];
    }
    return $o;
}
