<?php
/* ============================================================
   api/grades.php — grade (class) listing and management.

   A "grade" is a class: Kirkos, Minas and Grades 1-12. Their
   creation order is also their display order, and each row may
   point at the class a student moves into next
   (`next_grade_id`), which is what drives promotion.

   Grades are NEVER hard-deleted. Attendance and promotion
   history reference them, so removal is a soft delete
   (`deleted_at`) and the history stays readable.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------ */
/* Listing is gated on students.view rather than grades.view: a teacher
   needs the class list to use the register, but has no rights over the
   classes themselves. */
case 'list': {
    sr_methods(['GET', 'POST']);
    require_cap('students.view');
    $year  = resolve_year();
    $scope = sr_scope_grades((int)$year['id']);      // null = all
    $withCounts = input_bool('with_counts', true);

    $sql  = "SELECT g.* FROM grades g WHERE g.deleted_at IS NULL";
    $args = [];
    if ($scope !== null) {
        if (!$scope) json_ok(['grades' => [], 'year' => $year, 'scoped' => true]);
        $sql .= " AND g.id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
        $args = $scope;
    }
    $sql .= " ORDER BY g.sort_order, g.id";
    $st = db()->prepare($sql);
    $st->execute($args);
    $grades = $st->fetchAll();

    if ($withCounts && $grades) {
        $ids = array_column($grades, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $cs  = db()->prepare("SELECT e.grade_id, COUNT(*) c
                              FROM enrollments e
                              JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                              WHERE e.academic_year_id = ? AND e.grade_id IN ($in)
                                AND e.status IN ('active','repeated')
                              GROUP BY e.grade_id");
        $cs->execute(array_merge([$year['id']], $ids));
        $counts = [];
        foreach ($cs->fetchAll() as $r) $counts[(int)$r['grade_id']] = (int)$r['c'];

        $ts = db()->prepare("SELECT tc.grade_id, u.id, u.full_name
                             FROM teacher_classes tc JOIN users u ON u.id = tc.user_id AND u.deleted_at IS NULL
                             WHERE tc.academic_year_id = ? AND tc.grade_id IN ($in)");
        $ts->execute(array_merge([$year['id']], $ids));
        $teachers = [];
        foreach ($ts->fetchAll() as $r) $teachers[(int)$r['grade_id']][] = ['id' => (int)$r['id'], 'name' => $r['full_name']];

        foreach ($grades as &$g) {
            $g['student_count'] = $counts[(int)$g['id']] ?? 0;
            $g['teachers']      = $teachers[(int)$g['id']] ?? [];
        }
        unset($g);
    }

    json_ok(['grades' => sr_grades_out($grades), 'year' => $year, 'scoped' => $scope !== null]);
}

/* ------------------------------------------------------------------ */
case 'get': {
    sr_methods(['GET', 'POST']);
    require_cap('students.view');
    $id   = (int)input_int('id', 0);
    $year = resolve_year();
    if (!$id) json_error('A grade id is required.', 422, 'validation');
    sr_assert_grade_access($id, (int)$year['id']);

    $st = db()->prepare("SELECT * FROM grades WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) json_error('That grade does not exist.', 404, 'not_found');

    json_ok(['grade' => sr_grade_out($g), 'year' => $year]);
}

/* ------------------------------------------------------------------ */
case 'create':
case 'update': {
    sr_methods(['POST']);
    require_cap('grades.manage');

    $id      = (int)input_int('id', 0);
    $code    = strtolower(v_len(v_required(input_str('code'), 'Grade code'), 'Grade code', 40));
    if (!preg_match('/^[a-z0-9_-]+$/', $code)) json_error('Grade code may contain only lowercase letters, digits, _ and -.', 422, 'validation');
    $nameAm  = v_len(v_required(input_str('name_am'), 'Amharic name'), 'Amharic name', 80);
    $nameEn  = v_len(v_required(input_str('name_en'), 'English name'), 'English name', 80);
    $badgeAm = mb_substr(input_str('badge_am') ?: mb_substr($nameAm, 0, 1), 0, 8);
    $badgeEn = mb_substr(input_str('badge_en') ?: mb_substr($nameEn, 0, 1), 0, 8);
    $saintAm = input_str('saint_am');
    $saintEn = input_str('saint_en');
    $tone    = input_str('tone', 'gold');
    $levelNo = (int)input_int('level_no', 0);
    $sort    = (int)input_int('sort_order', 99);
    $next    = input_int('next_grade_id');

    if ($action === 'create') {
        $dup = db()->prepare("SELECT id FROM grades WHERE code = ?");
        $dup->execute([$code]);
        if ($dup->fetchColumn()) json_error("A grade with the code “{$code}” already exists.", 409, 'duplicate');

        $st = db()->prepare("INSERT INTO grades (code,name_am,name_en,badge_am,badge_en,saint_am,saint_en,tone,level_no,sort_order,next_grade_id)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$code,$nameAm,$nameEn,$badgeAm,$badgeEn,$saintAm ?: null,$saintEn ?: null,$tone,$levelNo,$sort,$next ?: null]);
        $id = (int)db()->lastInsertId();
        audit('grade.created', 'grade', $id, "Grade $nameEn ($code) created");
    } else {
        if (!$id) json_error('A grade id is required.', 422, 'validation');
        $dup = db()->prepare("SELECT id FROM grades WHERE code = ? AND id <> ?");
        $dup->execute([$code, $id]);
        if ($dup->fetchColumn()) json_error("Another grade already uses the code “{$code}”.", 409, 'duplicate');
        if ($next === $id) json_error('A grade cannot promote into itself.', 422, 'validation');

        $st = db()->prepare("UPDATE grades SET code=?,name_am=?,name_en=?,badge_am=?,badge_en=?,saint_am=?,saint_en=?,
                             tone=?,level_no=?,sort_order=?,next_grade_id=? WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$code,$nameAm,$nameEn,$badgeAm,$badgeEn,$saintAm ?: null,$saintEn ?: null,$tone,$levelNo,$sort,$next ?: null,$id]);
        audit('grade.updated', 'grade', $id, "Grade $nameEn ($code) updated");
    }

    $g = db()->prepare("SELECT * FROM grades WHERE id = ?");
    $g->execute([$id]);
    json_ok(['grade' => sr_grade_out($g->fetch())]);
}

/* ------------------------------------------------------------------ */
/* Archive a class. Refuses while students are still enrolled unless the
   caller explicitly passes force=1, so a class cannot be made to vanish out
   from under its pupils by accident. Any other grade that promotes INTO this
   one has its next_grade_id cleared, otherwise promotion would later try to
   move students into an archived class. */
case 'delete': {
    sr_methods(['POST']);
    require_cap('grades.manage');
    $id = (int)input_int('id', 0);
    if (!$id) json_error('A grade id is required.', 422, 'validation');

    $c = db()->prepare("SELECT COUNT(*) FROM enrollments e JOIN students s ON s.id = e.student_id AND s.deleted_at IS NULL
                        WHERE e.grade_id = ?");
    $c->execute([$id]);
    $n = (int)$c->fetchColumn();
    if ($n > 0 && !input_bool('force')) {
        json_error("This grade still has $n enrolled student(s). Move or promote them first, or confirm a forced archive.", 409, 'in_use', ['student_count' => $n]);
    }

    $st = db()->prepare("UPDATE grades SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    db()->prepare("UPDATE grades SET next_grade_id = NULL WHERE next_grade_id = ?")->execute([$id]);
    audit('grade.deleted', 'grade', $id, "Grade archived (soft delete), $n student(s) affected");
    json_ok(['deleted' => true, 'soft' => true]);
}

/* ------------------------------------------------------------------ */
case 'restore': {
    sr_methods(['POST']);
    require_cap('grades.manage');
    $id = (int)input_int('id', 0);
    db()->prepare("UPDATE grades SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    audit('grade.restored', 'grade', $id, 'Grade restored');
    json_ok(['restored' => true]);
}

default:
    json_error('Unknown grades action: ' . $action, 404, 'unknown_action');
}

/* ---------------------------- shaping ---------------------------- */
function sr_grade_out(?array $g): ?array
{
    if (!$g) return null;
    return [
        'id'        => (int)$g['id'],
        'code'      => $g['code'],
        'name_am'   => $g['name_am'],
        'name_en'   => $g['name_en'],
        'badge_am'  => $g['badge_am'],
        'badge_en'  => $g['badge_en'],
        'saint_am'  => $g['saint_am'],
        'saint_en'  => $g['saint_en'],
        'tone'      => $g['tone'],
        'level_no'  => $g['level_no'] !== null ? (int)$g['level_no'] : null,
        'sort_order'=> (int)$g['sort_order'],
        'next_grade_id' => $g['next_grade_id'] !== null ? (int)$g['next_grade_id'] : null,
        'student_count' => isset($g['student_count']) ? (int)$g['student_count'] : null,
        'teachers'  => $g['teachers'] ?? [],
    ];
}

function sr_grades_out(array $rows): array
{
    return array_map('sr_grade_out', $rows);
}
