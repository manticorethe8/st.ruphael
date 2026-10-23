<?php
/* ============================================================
   api/backup.php  (Master only)
   Logical backup to a self-contained JSON file, plus restore.
   No shell, no mysqldump — works on a stock XAMPP box.
   Credentials are never written into the file.
   ============================================================ */
require_once __DIR__ . '/_boot.php';

$action = sr_action('list');
$user   = require_login();
require_cap('backup.manage');

/* order matters: parents before children on restore */
const SR_BACKUP_TABLES = [
    'users', 'academic_years', 'grades', 'students', 'enrollments',
    'teacher_classes', 'attendance', 'promotions', 'settings',
    'alert_reads', 'audit_logs', 'import_batches',
];

switch ($action) {

/* ------------------------------------------------------------------
   create — build the archive, stream it and keep a copy
   ------------------------------------------------------------------ */
case 'create': {
    sr_methods(['POST', 'GET']);
    $includeAudit = input_bool('include_audit', true);
    $pdo  = db();
    $data = [];
    $counts = [];

    foreach (SR_BACKUP_TABLES as $t) {
        if ($t === 'audit_logs' && !$includeAudit) continue;
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $data[$t] = $rows;
        $counts[$t] = count($rows);
    }

    $payload = [
        'format'      => 'st-rufael-backup',
        'version'     => 1,
        'app_version' => APP_VERSION,
        'created_at'  => date('c'),
        'created_by'  => $user['username'],
        'server'      => ['php' => PHP_VERSION, 'db' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)],
        'counts'      => $counts,
        'tables'      => $data,
    ];
    /* integrity check so a truncated or edited file is caught on restore */
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $payload['checksum'] = hash('sha256', $body);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $name = 'st-rufael-backup-' . date('Ymd-His') . '.json';
    sr_ensure_dir(BACKUP_DIR);
    @file_put_contents(rtrim(BACKUP_DIR, '/') . '/' . $name, $body);

    audit('backup.created', 'backup', null, 'Backup created: ' . $name, $counts);

    if (input_bool('download', true)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }
    json_ok(['file' => $name, 'bytes' => strlen($body), 'counts' => $counts], ['message' => 'Backup created.']);
}

/* ------------------------------------------------------------------
   list — what is already on disk
   ------------------------------------------------------------------ */
case 'list': {
    sr_methods(['GET']);
    sr_ensure_dir(BACKUP_DIR);
    $out = [];
    foreach (glob(rtrim(BACKUP_DIR, '/') . '/*.json') ?: [] as $p) {
        $out[] = ['file' => basename($p), 'bytes' => filesize($p), 'created_at' => date('c', (int)filemtime($p))];
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    json_ok(['backups' => $out, 'dir' => basename(rtrim(BACKUP_DIR, '/'))]);
}

/* ------------------------------------------------------------------
   restore — validate hard, then replace the data
   ------------------------------------------------------------------ */
case 'restore': {
    sr_methods(['POST']);

    /* source: uploaded file or a filename already in backups/ */
    $json = null;
    if (!empty($_FILES['file']['tmp_name'])) {
        if (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) json_error('The upload failed.', 400, 'upload_failed');
        if ($_FILES['file']['size'] > 67108864) json_error('That backup is larger than the 64 MB limit.', 413, 'too_large');
        if (!is_uploaded_file($_FILES['file']['tmp_name'])) json_error('Invalid upload.', 400, 'upload_failed');
        if (strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'json') {
            json_error('A backup must be the .json file produced by this system.', 422, 'bad_format');
        }
        $json = (string)file_get_contents($_FILES['file']['tmp_name']);
    } elseif ($f = input_str('file')) {
        $safe = basename($f);                       /* no traversal */
        if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $safe)) json_error('Invalid backup file name.', 422, 'validation');
        $path = rtrim(BACKUP_DIR, '/') . '/' . $safe;
        if (!is_file($path)) json_error('That backup file no longer exists on the server.', 404, 'not_found');
        $json = (string)file_get_contents($path);
    } else {
        json_error('Upload a backup file or name one already on the server.', 422, 'validation');
    }

    $p = json_decode($json, true);
    if (!is_array($p) || ($p['format'] ?? '') !== 'st-rufael-backup') {
        json_error('That file is not a St. Rufael backup.', 422, 'bad_format');
    }
    if ((int)($p['version'] ?? 0) !== 1) {
        json_error('That backup was made by an incompatible version (' . e((string)($p['version'] ?? '?')) . ').', 422, 'bad_version');
    }
    if (!isset($p['tables']) || !is_array($p['tables'])) json_error('The backup contains no table data.', 422, 'bad_format');

    if (!empty($p['checksum'])) {
        $claim = $p['checksum'];
        $copy = $p; unset($copy['checksum']);
        if (hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE)) !== $claim) {
            json_error('This backup failed its integrity check — it has been modified or is incomplete.', 422, 'checksum_failed');
        }
    }
    if (empty($p['tables']['users'])) {
        json_error('Refusing to restore: the backup has no user accounts, which would lock you out.', 422, 'unsafe_restore');
    }
    if (!input_bool('confirm', false)) {
        json_ok(['committed' => false, 'created_at' => $p['created_at'] ?? null,
                 'created_by' => $p['created_by'] ?? null, 'counts' => $p['counts'] ?? []],
                ['message' => 'This will replace all current data. Send confirm=true to proceed.']);
    }

    /* safety copy of the current state before overwriting */
    $pdo = db();
    $pre = [];
    foreach (SR_BACKUP_TABLES as $t) $pre[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    sr_ensure_dir(BACKUP_DIR);
    @file_put_contents(rtrim(BACKUP_DIR, '/') . '/pre-restore-' . date('Ymd-His') . '.json',
        json_encode(['format' => 'st-rufael-backup', 'version' => 1, 'created_at' => date('c'),
                     'created_by' => $user['username'] . ' (auto pre-restore)', 'tables' => $pre], JSON_UNESCAPED_UNICODE));

    $restored = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->beginTransaction();
    try {
        foreach (SR_BACKUP_TABLES as $t) {
            if (!isset($p['tables'][$t])) continue;
            $rows = $p['tables'][$t];
            if (!is_array($rows)) continue;

            /* only columns that exist in this schema survive */
            $cols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) $cols[$c['Field']] = true;

            $pdo->exec("DELETE FROM `$t`");
            $n = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $use = array_intersect_key($row, $cols);
                if (!$use) continue;
                $names = array_keys($use);
                $sql = "INSERT INTO `$t` (`" . implode('`,`', $names) . "`) VALUES ("
                     . implode(',', array_fill(0, count($names), '?')) . ")";
                $pdo->prepare($sql)->execute(array_values($use));
                $n++;
            }
            $restored[$t] = $n;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        json_error('Restore failed and nothing was changed: ' . $e->getMessage(), 500, 'restore_failed');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    audit('backup.restored', 'backup', null, 'Backup restored (' . array_sum($restored) . ' rows)', $restored);
    json_ok(['committed' => true, 'restored' => $restored],
            ['message' => 'Restore complete. Please sign in again if your account changed.']);
}

/* ------------------------------------------------------------------
   delete — remove a stored backup file
   ------------------------------------------------------------------ */
case 'delete': {
    sr_methods(['POST']);
    $safe = basename(input_str('file'));
    if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $safe)) json_error('Invalid backup file name.', 422, 'validation');
    $path = rtrim(BACKUP_DIR, '/') . '/' . $safe;
    if (!is_file($path)) json_error('That backup no longer exists.', 404, 'not_found');
    @unlink($path);
    audit('backup.deleted', 'backup', null, 'Backup deleted: ' . $safe);
    json_ok(['deleted' => $safe], ['message' => 'Backup deleted.']);
}

default:
    json_error('Unknown backup action: ' . $action, 404, 'unknown_action');
}
