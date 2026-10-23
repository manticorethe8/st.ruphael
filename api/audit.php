<?php
/* ============================================================
   api/audit.php
   Read-only trail of who did what, with filters and CSV export.
   Admins can read it; only a Master may purge.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();

switch ($action) {

/* ------------------------------------------------------------------
   list — paged, filtered trail.
   Every filter is optional and ANDed together. Values always travel as
   bound parameters; only the whitelisted column names are interpolated,
   so the assembled $w string can never carry user input.
   ------------------------------------------------------------------ */
case 'list': {
    sr_methods(['GET', 'POST']);
    require_cap('audit.view');

    $page    = max(1, (int)input_int('page', 1));
    $perPage = min(200, max(10, (int)input_int('per_page', 50)));
    $where = ['1=1']; $args = [];

    if ($uid = input_int('user_id'))      { $where[] = 'a.user_id = ?';       $args[] = $uid; }
    if ($act = input_str('action_type'))  { $where[] = 'a.action LIKE ?';     $args[] = $act . '%'; }
    if ($ent = input_str('entity_type'))  { $where[] = 'a.entity_type = ?';   $args[] = $ent; }
    /* Validate against sr_roles() rather than a hand-copied list, so a role
       added in config/auth.php is filterable here without a second edit.
       (A literal list here silently rejected 'editor' until v2.3.) */
    if ($rol = input_str('role'))         { $where[] = 'a.role = ?';          $args[] = v_in($rol, sr_roles(), 'Role'); }
    if ($from = input_str('from'))        { $where[] = 'a.created_at >= ?';   $args[] = v_iso_date($from, 'From date') . ' 00:00:00'; }
    if ($to   = input_str('to'))          { $where[] = 'a.created_at <= ?';   $args[] = v_iso_date($to, 'To date') . ' 23:59:59'; }
    if ($q = trim(input_str('q'))) {
        $where[] = '(a.summary LIKE ? OR a.username LIKE ? OR a.action LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    $w = implode(' AND ', $where);

    /* Count first (for the pager), then fetch just the current page.
       LIMIT/OFFSET are cast ints, not placeholders: MySQL will not accept
       bound parameters in LIMIT under emulated prepares. */
    $c = db()->prepare("SELECT COUNT(*) FROM audit_logs a WHERE $w");
    $c->execute($args);
    $total = (int)$c->fetchColumn();

    $st = db()->prepare("SELECT a.* FROM audit_logs a WHERE $w
                         ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $st->execute($args);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'user_id'     => $r['user_id'] !== null ? (int)$r['user_id'] : null,
            'username'    => $r['username'],
            'role'        => $r['role'],
            'action'      => $r['action'],
            'entity_type' => $r['entity_type'],
            'entity_id'   => $r['entity_id'] !== null ? (int)$r['entity_id'] : null,
            'summary'     => $r['summary'],
            'meta'        => $r['meta'] ? json_decode((string)$r['meta'], true) : null,
            'ip_address'  => $r['ip_address'],
            'created_at'  => $r['created_at'],
            'et_label'    => et_label(substr((string)$r['created_at'], 0, 10), input_str('lang', 'am') === 'en' ? 'en' : 'am'),
        ];
    }

    json_ok(['logs' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
             'pages' => (int)ceil($total / $perPage)]);
}

/* distinct values, for the filter dropdowns */
case 'filters': {
    sr_methods(['GET']);
    require_cap('audit.view');
    $actions = db()->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $ents    = db()->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
    $users   = db()->query("SELECT DISTINCT u.id, u.username, u.full_name FROM audit_logs a
                            JOIN users u ON u.id = a.user_id ORDER BY u.username")->fetchAll();
    json_ok(['actions' => $actions, 'entity_types' => $ents, 'users' => $users]);
}

/* CSV of the whole trail (capped at 20,000 rows so a huge log cannot
   exhaust memory). Exporting is itself an audited event. */
case 'export': {
    sr_methods(['GET', 'POST']);
    require_cap('audit.view');
    $st = db()->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 20000");
    $rows = [['ID', 'When', 'User', 'Role', 'Action', 'Entity', 'Entity ID', 'Summary', 'IP']];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [$r['id'], $r['created_at'], $r['username'], $r['role'], $r['action'],
                   $r['entity_type'], $r['entity_id'], $r['summary'], $r['ip_address']];
    }
    require_once SR_ROOT . '/lib/xlsx.php';
    $body = sr_csv_string($rows);
    $name = 'audit-log-' . date('Ymd-His') . '.csv';
    audit('export.audit', 'audit', null, (count($rows) - 1) . ' audit rows exported');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo $body;
    exit;
}

/* Master-only housekeeping: permanently delete entries older than N days.
   Irreversible, so it is capability-gated to audit.purge (master alone) and
   the purge itself is written back into the log. */
case 'purge': {
    sr_methods(['POST']);
    require_cap('audit.purge');
    $days = max(1, (int)input_int('older_than_days', 365));
    $st = db()->prepare("DELETE FROM audit_logs WHERE created_at < (NOW() - INTERVAL ? DAY)");
    $st->execute([$days]);
    $n = $st->rowCount();
    audit('audit.purged', 'audit', null, "$n audit entries older than $days days removed");
    json_ok(['deleted' => $n], ['message' => "$n old entries removed."]);
}

default:
    json_error('Unknown audit action: ' . $action, 404, 'unknown_action');
}
