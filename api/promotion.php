<?php
/* ============================================================
   api/promotion.php
   Moves students to the next grade in a new academic year by
   adding a NEW ENROLMENT ROW — the student record itself is
   never duplicated, and every past enrolment, attendance mark
   and promotion entry stays exactly where it was.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('preview');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------
   preview — what would happen, with warnings, before anything runs
   ------------------------------------------------------------------ */
case 'preview':
case 'run': {
    sr_methods(['POST', 'GET']);
    require_cap('promotion.manage');
    $commit = ($action === 'run') && input_bool('confirm', false);
    if ($action === 'run') sr_methods(['POST']);

    $fromYear = resolve_year();
    $fromYid  = (int)$fromYear['id'];

    $toEc = (int)input_int('to_year_ec', (int)$fromYear['year_ec'] + 1);
    if ($toEc === (int)$fromYear['year_ec']) json_error('The target year must be different from the source year.', 422, 'validation');
    if ($toEc < (int)$fromYear['year_ec']) json_error('You cannot promote backwards into an earlier year.', 422, 'validation');

    $gradeFilter = input_int('grade_id');
    if ($gradeFilter) sr_assert_grade_access($gradeFilter, $fromYid);

    /* optional explicit selection: [{student_id, result, to_grade_id}] */
    $selection = [];
    foreach (input_array('students') as $s) {
        if (!isset($s['student_id'])) continue;
        $selection[(int)$s['student_id']] = [
            'result'      => strtolower((string)($s['result'] ?? 'passed')),
            'to_grade_id' => isset($s['to_grade_id']) ? (int)$s['to_grade_id'] : null,
        ];
    }
    /* NOTE: this is the *decision vocabulary* the promotion screen speaks, and it
       is deliberately wider than the promotions.result ENUM('passed','failed',
       'pending'). 'withheld' and 'skip' describe what the operator chose to do;
       they are translated below into an `action` (withhold / skip) while the
       `result` column still receives a legal ENUM value. Do not "simplify" this
       list to match the ENUM — it would remove the two no-op outcomes. */
    $defaultResult = v_in(strtolower(input_str('default_result', 'passed')), ['passed','failed','withheld','skip'], 'Default result');

    $where = ['e.academic_year_id = ?', 's.deleted_at IS NULL', "e.status IN ('active','repeated','not_promoted')"];
    $args  = [$fromYid];
    if ($gradeFilter) { $where[] = 'e.grade_id = ?'; $args[] = $gradeFilter; }
    if ($selection) {
        $ids = array_keys($selection);
        $where[] = 'e.student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }

    $st = db()->prepare("SELECT e.id enrollment_id, e.student_id, e.grade_id, e.roll_no, e.status enr_status,
                                s.full_name, s.student_code, s.baptism_name, s.status student_status,
                                g.name_am grade_am, g.name_en grade_en, g.next_grade_id,
                                ng.name_am next_am, ng.name_en next_en
                         FROM enrollments e
                         JOIN students s ON s.id = e.student_id
                         JOIN grades   g ON g.id = e.grade_id
                         LEFT JOIN grades ng ON ng.id = g.next_grade_id
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY g.sort_order, s.full_name");
    $st->execute($args);
    $rows = $st->fetchAll();
    if (!$rows) json_error('There are no students to promote for that selection.', 404, 'empty');

    $toYear = year_by_ec($toEc);
    $existing = [];
    if ($toYear) {
        $ex = db()->prepare("SELECT student_id FROM enrollments WHERE academic_year_id = ?");
        $ex->execute([$toYear['id']]);
        foreach ($ex->fetchAll() as $r) $existing[(int)$r['student_id']] = true;
    }

    $plan = [];
    foreach ($rows as $r) {
        $sid    = (int)$r['student_id'];
        $sel    = $selection[$sid] ?? ['result' => $defaultResult, 'to_grade_id' => null];
        $result = in_array($sel['result'], ['passed','failed','withheld','skip'], true) ? $sel['result'] : 'passed';
        $toGid  = $sel['to_grade_id'] ?: ($result === 'passed' ? ($r['next_grade_id'] ? (int)$r['next_grade_id'] : null) : (int)$r['grade_id']);

        /* Four outcomes, and they are genuinely different things:
             promote  — new enrolment in the next grade
             repeat   — new enrolment, same grade, student continues
             withhold — NOT promoted: no new enrolment at all. The old
                        enrolment closes as 'not_promoted' and the decision
                        is written to the trail so it can be revisited.
             skip     — no decision taken; the student is simply untouched. */
        $note = null; $act = 'promote';
        if ($result === 'skip')            { $act = 'skip';     $toGid = null; $note = 'Left out of this run — no change recorded.'; }
        elseif ($result === 'withheld')    { $act = 'withhold'; $toGid = null; $note = 'Not promoted — no enrolment created for ' . $toEc; }
        elseif ($result === 'failed')      { $act = 'repeat';   $toGid = (int)$r['grade_id']; $note = 'Repeats the same grade in the new year.'; }
        elseif (!$toGid)                   { $act = 'graduate'; $note = 'No next grade — will be marked as graduated.'; }
        if (isset($existing[$sid]) && !in_array($act, ['skip','withhold'], true)) {
            $act = 'skip'; $toGid = null;
            $note = 'Already enrolled in ' . $toEc . ' — skipped, no duplicate created.';
        }

        $plan[] = [
            'student_id'   => $sid,
            'student_code' => $r['student_code'],
            'full_name'    => $r['full_name'],
            'baptism_name' => $r['baptism_name'],
            'from_grade_id'=> (int)$r['grade_id'],
            'from_grade'   => $r['grade_am'],
            'from_grade_en'=> $r['grade_en'],
            'to_grade_id'  => $toGid,
            'to_grade'     => $toGid === null ? null : ($toGid === (int)$r['grade_id'] ? $r['grade_am'] : $r['next_am']),
            'to_grade_en'  => $toGid === null ? null : ($toGid === (int)$r['grade_id'] ? $r['grade_en'] : $r['next_en']),
            'was_withheld' => $r['enr_status'] === 'not_promoted',
            'result'       => $result,
            'action'       => $act,
            'note'         => $note,
        ];
    }

    $summary = ['promote' => 0, 'repeat' => 0, 'graduate' => 0, 'withhold' => 0, 'skip' => 0];
    foreach ($plan as $p) $summary[$p['action']]++;

    /* Everyone who will NOT have a place in the new year, spelled out, so
       nobody is silently lost between two academic years. */
    $leftBehind = array_values(array_filter($plan, fn($p) => in_array($p['action'], ['withhold','skip'], true)));

    if (!$commit) {
        json_ok([
            'committed' => false,
            'from_year' => $fromYear,
            'to_year_ec'=> $toEc,
            'to_year'   => $toYear,
            'plan'         => $plan,
            'summary'      => $summary,
            'left_behind'  => $leftBehind,
            'left_behind_count' => count($leftBehind),
        ], ['message' => 'Review the plan, then confirm to apply it.']);
    }

    /* ---------------- commit ---------------- */
    $toYear = ensure_year($toEc);
    $toYid  = (int)$toYear['id'];
    $pdo = db();
    $done = ['promote' => 0, 'repeat' => 0, 'graduate' => 0, 'withhold' => 0, 'skip' => 0];

    $pdo->beginTransaction();
    try {
        $insEnr = $pdo->prepare("INSERT INTO enrollments (student_id, grade_id, academic_year_id, roll_no, status, enrolled_on)
                                 VALUES (?,?,?,NULL,'active',CURDATE())
                                 ON DUPLICATE KEY UPDATE grade_id = VALUES(grade_id)");
        $insPro = $pdo->prepare("INSERT INTO promotions (student_id, from_grade_id, to_grade_id, from_year_id, to_year_id, result, action, note, performed_by)
                                 VALUES (?,?,?,?,?,?,?,?,?)");
        $updOld = $pdo->prepare("UPDATE enrollments SET status = ?, result = ? WHERE student_id = ? AND academic_year_id = ?");
        $updStu = $pdo->prepare("UPDATE students SET status = ? WHERE id = ?");

        foreach ($plan as $p) {
            if ($p['action'] === 'skip') { $done['skip']++; continue; }

            /* Not promoted: close the old enrolment, write the decision,
               create nothing in the new year. */
            if ($p['action'] === 'withhold') {
                $updOld->execute(['not_promoted', 'failed', $p['student_id'], $fromYid]);
                $insPro->execute([$p['student_id'], $p['from_grade_id'], null, $fromYid, $toYid,
                                  'failed', 'withheld', $p['note'], $user['id']]);
                $done['withhold']++;
                continue;
            }

            if ($p['action'] === 'graduate') {
                $updOld->execute(['graduated', 'passed', $p['student_id'], $fromYid]);
                $updStu->execute(['graduated', $p['student_id']]);
                $insPro->execute([$p['student_id'], $p['from_grade_id'], null, $fromYid, $toYid, 'passed', 'graduated', $p['note'], $user['id']]);
                $done['graduate']++;
                continue;
            }

            /* NEW enrolment row — the student row itself is untouched */
            $insEnr->execute([$p['student_id'], $p['to_grade_id'], $toYid]);
            $updOld->execute([$p['action'] === 'repeat' ? 'repeated' : 'promoted',
                              $p['action'] === 'repeat' ? 'failed' : 'passed',
                              $p['student_id'], $fromYid]);
            $insPro->execute([$p['student_id'], $p['from_grade_id'], $p['to_grade_id'], $fromYid, $toYid,
                              $p['action'] === 'repeat' ? 'failed' : 'passed',
                              $p['action'] === 'repeat' ? 'repeated' : 'promoted',
                              $p['note'], $user['id']]);
            $done[$p['action']]++;
        }

        /* Promotion records outcomes; it does NOT start the new academic year.
           Enrolments for the next year are created here so the roster is ready
           in advance, but the school keeps working in the current year until
           the year officially starts — either on the configured start date or
           via the manual switch in Settings (see sr_year_autoswitch()). */
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('promotion.run', 'academic_year', $toYid,
          "Promotion {$fromYear['year_ec']} → $toEc: {$done['promote']} promoted, {$done['repeat']} repeated, {$done['graduate']} graduated, {$done['withhold']} not promoted, {$done['skip']} skipped",
          $done + ['from_year_ec' => $fromYear['year_ec'], 'to_year_ec' => $toEc]);

    json_ok(['committed' => true, 'summary' => $done, 'to_year' => $toYear],
            ['message' => "Promotion complete: {$done['promote']} promoted, {$done['repeat']} repeating, {$done['graduate']} graduated, {$done['withhold']} not promoted, {$done['skip']} skipped."]);
}

/* ------------------------------------------------------------------
   history — the promotion trail (whole school or one student)
   ------------------------------------------------------------------ */
case 'history': {
    sr_methods(['GET', 'POST']);
    require_cap('promotion.manage');
    $sid = input_int('student_id');
    $where = ['1=1']; $args = [];
    if ($sid) { sr_assert_student_access((int)$sid, null); $where[] = 'p.student_id = ?'; $args[] = $sid; }
    $yearEc = input_int('year_ec');
    if ($yearEc) { $where[] = 'ty.year_ec = ?'; $args[] = $yearEc; }

    $st = db()->prepare("SELECT p.*, s.full_name, s.student_code,
                                fg.name_am from_am, fg.name_en from_en,
                                tg.name_am to_am,   tg.name_en to_en,
                                fy.year_ec from_year, ty.year_ec to_year,
                                u.full_name performed_by_name
                         FROM promotions p
                         JOIN students s ON s.id = p.student_id
                         LEFT JOIN grades fg ON fg.id = p.from_grade_id
                         LEFT JOIN grades tg ON tg.id = p.to_grade_id
                         LEFT JOIN academic_years fy ON fy.id = p.from_year_id
                         LEFT JOIN academic_years ty ON ty.id = p.to_year_id
                         LEFT JOIN users u ON u.id = p.performed_by
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY p.created_at DESC LIMIT 1000");
    $st->execute($args);
    json_ok(['history' => $st->fetchAll()]);
}

/* ------------------------------------------------------------------
   undo — reverse one promotion batch (target year must be empty of marks)
   ------------------------------------------------------------------ */
case 'undo': {
    sr_methods(['POST']);
    require_cap('promotion.manage');
    $toEc = (int)input_int('to_year_ec', 0);
    $toYear = $toEc ? year_by_ec($toEc) : null;
    if (!$toYear) json_error('Choose the academic year you want to roll back.', 422, 'validation');

    $c = db()->prepare("SELECT COUNT(*) FROM attendance WHERE academic_year_id = ?");
    $c->execute([$toYear['id']]);
    if ((int)$c->fetchColumn() > 0) {
        json_error('Attendance has already been recorded in ' . $toEc . ', so this promotion can no longer be undone.', 409, 'has_attendance');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $p = $pdo->prepare("SELECT * FROM promotions WHERE to_year_id = ?");
    $p->execute([$toYear['id']]);
    $rows = $p->fetchAll();
    foreach ($rows as $r) {
        $pdo->prepare("DELETE FROM enrollments WHERE student_id = ? AND academic_year_id = ?")
            ->execute([$r['student_id'], $toYear['id']]);
        $pdo->prepare("UPDATE enrollments SET status = 'active', result = 'pending' WHERE student_id = ? AND academic_year_id = ?")
            ->execute([$r['student_id'], $r['from_year_id']]);
        /* 'withheld' rows never created a new-year enrolment; the DELETE
           above is a harmless no-op and the UPDATE below reopens the year. */
        if ($r['action'] === 'graduated') {
            $pdo->prepare("UPDATE students SET status = 'active' WHERE id = ?")->execute([$r['student_id']]);
        }
    }
    $pdo->prepare("DELETE FROM promotions WHERE to_year_id = ?")->execute([$toYear['id']]);
    /* Undo reverses the promotion records only. The current year is not
       touched here either — promotion never moved it in the first place. */
    $pdo->commit();

    audit('promotion.undone', 'academic_year', $toYear['id'], count($rows) . " promotion(s) rolled back for $toEc");
    json_ok(['undone' => count($rows)], ['message' => count($rows) . ' promotions rolled back.']);
}

default:
    json_error('Unknown promotion action: ' . $action, 404, 'unknown_action');
}
