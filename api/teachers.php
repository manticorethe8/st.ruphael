<?php
/* api/teachers.php — teacher roster and grade assignments. */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------ */
case 'list': {
    sr_methods(['GET', 'POST']);
    require_cap('teachers.view');
    $year = resolve_year();
    $st = db()->prepare("SELECT u.id, u.username, u.full_name, u.email, u.phone, u.is_active, u.last_login_at
                         FROM users u WHERE u.role = 'teacher' AND u.deleted_at IS NULL
                         ORDER BY u.full_name");
    $st->execute();
    $teachers = $st->fetchAll();

    if ($teachers) {
        $ids = array_column($teachers, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $a = db()->prepare("SELECT tc.user_id, g.id grade_id, g.code, g.name_am, g.name_en
                            FROM teacher_classes tc JOIN grades g ON g.id = tc.grade_id AND g.deleted_at IS NULL
                            WHERE tc.academic_year_id = ? AND tc.user_id IN ($in)
                            ORDER BY g.sort_order");
        $a->execute(array_merge([$year['id']], $ids));
        $by = [];
        foreach ($a->fetchAll() as $r) {
            $by[(int)$r['user_id']][] = ['id' => (int)$r['grade_id'], 'code' => $r['code'],
                                         'name_am' => $r['name_am'], 'name_en' => $r['name_en']];
        }
        foreach ($teachers as &$t) {
            $t['id'] = (int)$t['id'];
            $t['is_active'] = (bool)$t['is_active'];
            $t['grades'] = $by[(int)$t['id']] ?? [];
        }
        unset($t);
    }
    json_ok(['teachers' => $teachers, 'year' => $year]);
}

/* ------------------------------------------------------------------
   assignments — the full grade × teacher matrix for a year
   ------------------------------------------------------------------ */
case 'assignments': {
    sr_methods(['GET', 'POST']);
    require_cap('teachers.view');
    $year = resolve_year();
    $st = db()->prepare("SELECT tc.id, tc.user_id, tc.grade_id, tc.is_primary, tc.created_at,
                                u.full_name teacher_name, u.username,
                                g.code grade_code, g.name_am grade_am, g.name_en grade_en,
                                a.full_name assigned_by_name
                         FROM teacher_classes tc
                         JOIN users u ON u.id = tc.user_id AND u.deleted_at IS NULL
                         JOIN grades g ON g.id = tc.grade_id AND g.deleted_at IS NULL
                         LEFT JOIN users a ON a.id = tc.assigned_by
                         WHERE tc.academic_year_id = ?
                         ORDER BY g.sort_order, u.full_name");
    $st->execute([$year['id']]);
    json_ok(['assignments' => $st->fetchAll(), 'year' => $year]);
}

/* ------------------------------------------------------------------ */
case 'assign': {
    sr_methods(['POST']);
    require_cap('teachers.assign');
    $year   = resolve_year();
    $yid    = (int)$year['id'];
    $uid    = (int)input_int('user_id', 0);
    $grades = array_values(array_unique(array_map('intval', input_array('grade_ids'))));
    if (!$grades && input_int('grade_id')) $grades = [(int)input_int('grade_id')];
    if (!$uid) json_error('Choose a teacher.', 422, 'validation');

    $t = db()->prepare("SELECT username, full_name, role FROM users WHERE id = ? AND deleted_at IS NULL");
    $t->execute([$uid]);
    $target = $t->fetch();
    if (!$target) json_error('That teacher does not exist.', 404, 'not_found');
    if ($target['role'] !== 'teacher') json_error('Only teacher accounts can be assigned to classes.', 422, 'not_teacher');

    /* validate every grade before touching anything */
    foreach ($grades as $g) {
        $c = db()->prepare("SELECT COUNT(*) FROM grades WHERE id = ? AND deleted_at IS NULL");
        $c->execute([$g]);
        if (!(int)$c->fetchColumn()) json_error("Grade #$g does not exist.", 422, 'validation');
    }

    $replace = input_bool('replace', true);
    db()->beginTransaction();
    if ($replace) db()->prepare("DELETE FROM teacher_classes WHERE user_id = ? AND academic_year_id = ?")->execute([$uid, $yid]);
    $ins = db()->prepare("INSERT IGNORE INTO teacher_classes (user_id, grade_id, academic_year_id, is_primary, assigned_by)
                          VALUES (?,?,?,?,?)");
    foreach ($grades as $i => $g) $ins->execute([$uid, $g, $yid, $i === 0 ? 1 : 0, $user['id']]);
    db()->commit();

    audit('teacher.assigned', 'user', $uid,
          "{$target['full_name']} assigned to " . count($grades) . ' class(es)',
          ['grade_ids' => $grades, 'year_ec' => $year['year_ec'], 'replace' => $replace]);
    json_ok(['assigned' => count($grades)], ['message' => count($grades) . ' class(es) assigned to ' . $target['full_name'] . '.']);
}

/* ------------------------------------------------------------------ */
case 'unassign': {
    sr_methods(['POST']);
    require_cap('teachers.assign');
    $year = resolve_year();
    $uid  = (int)input_int('user_id', 0);
    $gid  = (int)input_int('grade_id', 0);
    $id   = (int)input_int('id', 0);

    if ($id) {
        db()->prepare("DELETE FROM teacher_classes WHERE id = ?")->execute([$id]);
    } elseif ($uid && $gid) {
        db()->prepare("DELETE FROM teacher_classes WHERE user_id = ? AND grade_id = ? AND academic_year_id = ?")
            ->execute([$uid, $gid, $year['id']]);
    } else {
        json_error('Provide the assignment, or a teacher and a grade.', 422, 'validation');
    }
    audit('teacher.unassigned', 'user', $uid ?: null, 'Class assignment removed', ['grade_id' => $gid, 'assignment_id' => $id]);
    json_ok(['unassigned' => true], ['message' => 'Assignment removed.']);
}

/* ------------------------------------------------------------------
   my_classes — what the signed-in teacher is allowed to see
   ------------------------------------------------------------------ */
case 'my_classes': {
    sr_methods(['GET', 'POST']);
    require_login();
    $year  = resolve_year();
    $scope = sr_scope_grades((int)$year['id']);
    if ($scope === null) {
        $st = db()->query("SELECT id, code, name_am, name_en, badge_am, badge_en, tone FROM grades WHERE deleted_at IS NULL ORDER BY sort_order");
        json_ok(['classes' => $st->fetchAll(), 'unrestricted' => true, 'year' => $year]);
    }
    if (!$scope) json_ok(['classes' => [], 'unrestricted' => false, 'year' => $year]);
    $in = implode(',', array_fill(0, count($scope), '?'));
    $st = db()->prepare("SELECT id, code, name_am, name_en, badge_am, badge_en, tone FROM grades
                         WHERE id IN ($in) AND deleted_at IS NULL ORDER BY sort_order");
    $st->execute($scope);
    json_ok(['classes' => $st->fetchAll(), 'unrestricted' => false, 'year' => $year]);
}

default:
    json_error('Unknown teachers action: ' . $action, 404, 'unknown_action');
}
