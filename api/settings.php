<?php
/* api/settings.php — DB-persisted settings + academic year management. */
require_once __DIR__ . '/_boot.php';

$action = sr_action('get');
$user   = require_login();

/* keys the UI may write, with their storage type */
const SR_SETTING_TYPES = [
    'school_name_am'          => 'string',
    'school_name_en'          => 'string',
    'school_sub_am'           => 'string',
    'school_sub_en'           => 'string',
    'absence_alert_enabled'   => 'bool',
    'absence_alert_threshold' => 'int',
    'absence_alert_message_am'=> 'string',
    'absence_alert_message_en'=> 'string',
    'attendance_days'         => 'string',
    'default_lang'            => 'string',
    'current_year_ec'         => 'string',
    'id_card_footer_am'       => 'string',
    'id_card_footer_en'       => 'string',
    'session_idle_minutes'    => 'int',
    'year_auto_switch'        => 'bool',
    'year_start_date'         => 'string',
    'brand_always_amharic'    => 'bool',
    'login_quote_am'          => 'string',
    'login_quote_ref'         => 'string',
];

switch ($action) {

/* ------------------------------------------------------------------ */
case 'get': {
    sr_methods(['GET', 'POST']);
    /* everyone gets the public branding keys; managers get everything */
    $all = can('settings.view');
    $st = db()->query("SELECT skey, svalue, vtype, is_public, updated_at FROM settings ORDER BY skey");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!$all && !(int)$r['is_public']) continue;
        $out[$r['skey']] = setting_cast($r['svalue'], $r['vtype']);
    }
    $years = db()->query("SELECT * FROM academic_years ORDER BY year_ec DESC")->fetchAll();
    json_ok([
        'settings' => $out,
        'editable' => can('settings.manage'),
        'years'    => $years,
        'current_year' => current_year_row(),
        'placeholders' => ['{days}', '{student}', '{grade}'],
    ]);
}

/* ------------------------------------------------------------------ */
case 'save': {
    sr_methods(['POST']);
    require_cap('settings.manage');
    $payload = input_array('settings');
    if (!$payload) {
        /* accept flat form posts too */
        foreach (SR_SETTING_TYPES as $k => $_) if (input($k) !== null) $payload[$k] = input($k);
    }
    if (!$payload) json_error('There is nothing to save.', 422, 'validation');

    $changed = [];
    foreach ($payload as $key => $val) {
        if (!isset(SR_SETTING_TYPES[$key])) continue;           // ignore unknown keys
        $type = SR_SETTING_TYPES[$key];

        if ($key === 'absence_alert_threshold') {
            $val = (int)$val;
            if ($val < 1 || $val > 60) json_error('The absence alert threshold must be between 1 and 60 days.', 422, 'validation');
        }
        if ($key === 'session_idle_minutes') {
            $val = (int)$val;
            if ($val < 5 || $val > 1440) json_error('Session timeout must be between 5 and 1440 minutes.', 422, 'validation');
        }
        if ($key === 'year_start_date' && !preg_match('/^(0[1-9]|1[0-3])-(0[1-9]|[12]\\d|30)$/', (string)$val)) {
            json_error('The academic year start date must be an Ethiopian month and day, for example 01-01 for Meskerem 1.', 422, 'validation');
        }
        if ($key === 'default_lang' && !in_array($val, ['am','en'], true)) {
            json_error('Default language must be am or en.', 422, 'validation');
        }
        if (str_starts_with($key, 'absence_alert_message')) {
            $val = mb_substr((string)$val, 0, 500);
            if (trim((string)$val) === '') json_error('The alert message cannot be empty.', 422, 'validation');
        }
        if (str_starts_with($key, 'school_name') && trim((string)$val) === '') {
            json_error('The school name cannot be empty.', 422, 'validation');
        }
        if ($type === 'string') $val = mb_substr((string)$val, 0, 500);

        setting_set($key, $val, $type, (int)$user['id']);
        $changed[$key] = $type === 'bool' ? (bool)$val : $val;
    }

    audit('settings.updated', 'settings', null, count($changed) . ' setting(s) updated', ['keys' => array_keys($changed)]);
    json_ok(['saved' => $changed], ['message' => 'Settings saved.']);
}

/* ------------------------------------------------------------------ */
case 'years': {
    sr_methods(['GET', 'POST']);
    require_login();
    json_ok(['years' => db()->query("SELECT * FROM academic_years ORDER BY year_ec DESC")->fetchAll(),
             'current' => current_year_row()]);
}

/* ------------------------------------------------------------------ */
case 'add_year': {
    sr_methods(['POST']);
    require_cap('years.manage');
    $ec = (int)input_int('year_ec', 0);
    if ($ec < 1900 || $ec > 2200) json_error('Enter a valid Ethiopian year.', 422, 'validation');
    if (year_by_ec($ec)) json_error("Academic year $ec already exists.", 409, 'duplicate');
    $y = ensure_year($ec);
    if (input_bool('make_current')) {
        db()->prepare("UPDATE academic_years SET is_current = (id = ?)")->execute([$y['id']]);
        setting_set('current_year_ec', (string)$ec, 'string', (int)$user['id']);
    }
    audit('year.created', 'academic_year', $y['id'], "Academic year $ec added");
    json_ok(['year' => year_by_ec($ec)], ['message' => "Academic year $ec added."]);
}

/* ------------------------------------------------------------------ */
case 'set_current_year': {
    sr_methods(['POST']);
    require_cap('years.manage');
    $id = (int)input_int('id', 0);
    $ec = (int)input_int('year_ec', 0);
    $y  = $id ? year_by_id($id) : ($ec ? year_by_ec($ec) : null);
    if (!$y) json_error('That academic year does not exist.', 404, 'not_found');
    db()->prepare("UPDATE academic_years SET is_current = (id = ?)")->execute([$y['id']]);
    setting_set('current_year_ec', (string)$y['year_ec'], 'string', (int)$user['id']);
    audit('year.set_current', 'academic_year', $y['id'], "Current academic year set to {$y['year_ec']}");
    json_ok(['year' => $y], ['message' => "Now working in {$y['year_ec']}"]);
}

default:
    json_error('Unknown settings action: ' . $action, 404, 'unknown_action');
}
