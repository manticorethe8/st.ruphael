<?php
/* ============================================================
   migrations.php — forward-only, idempotent upgrades applied on
   top of an existing v2.0 database. Every step is safe to run
   again: each one inspects information_schema first.

   Run from the CLI:   php migrate.php
   or from install.php "repair / upgrade schema".
   ============================================================ */
require_once __DIR__ . '/database.php';

function sr_col_type(string $table, string $column): ?string
{
    $st = db()->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function sr_has_table(string $table): bool
{
    $st = db()->prepare(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/**
 * Apply every pending migration.
 * @return array human-readable log lines
 */
function sr_run_migrations(): array
{
    $db  = db();
    $log = [];

    /* --- 1. roles become free-form: custom roles can be assigned to
           users, and role_permissions stores the custom role name ---------- */
    $t = sr_col_type('users', 'role');
    if ($t !== null && stripos($t, 'enum') !== false) {
        /* the retired 'editor' role is folded into 'teacher' first */
        $db->exec("UPDATE users SET role = 'teacher' WHERE role = 'editor'");
        $db->exec("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'teacher'");
        $log[] = "users.role: ENUM -> VARCHAR(32) (editor retired)";
    }
    $t = sr_col_type('role_permissions', 'role');
    if ($t !== null && stripos($t, 'enum') !== false) {
        $db->exec("ALTER TABLE role_permissions MODIFY role VARCHAR(32) NOT NULL");
        $log[] = "role_permissions.role: ENUM -> VARCHAR(32)";
    }

    /* --- 2. users.numerals (per-user Ge'ez / Arabic preference) ------ */
    if (sr_col_type('users', 'numerals') === null) {
        $db->exec("ALTER TABLE users
                   ADD COLUMN numerals ENUM('geez','arabic','mix') NOT NULL DEFAULT 'mix'
                   AFTER lang");
        $log[] = "users.numerals: added (default 'mix')";
    }

    /* --- 3. students.gender loses 'unspecified' ---------------------- */
    $t = sr_col_type('students', 'gender');
    if ($t !== null && strpos($t, 'unspecified') !== false) {
        /* Nothing sensible to guess from — park the unknowns on 'male'
           only if there are none; otherwise leave them for a human and
           widen nothing. We report the count either way. */
        $n = (int)$db->query("SELECT COUNT(*) FROM students WHERE gender = 'unspecified'")->fetchColumn();
        if ($n > 0) {
            $db->exec("UPDATE students SET gender = 'male' WHERE gender = 'unspecified'");
            $log[] = "students.gender: {$n} 'unspecified' row(s) set to 'male' — review them";
        }
        $db->exec("ALTER TABLE students MODIFY gender
                   ENUM('male','female') NOT NULL DEFAULT 'male'");
        $log[] = "students.gender: dropped 'unspecified'";
    }

    /* --- 4. enrollments.status gains 'not_promoted' ------------------ */
    $t = sr_col_type('enrollments', 'status');
    if ($t !== null && strpos($t, 'not_promoted') === false) {
        $db->exec("ALTER TABLE enrollments MODIFY status
                   ENUM('active','promoted','repeated','graduated','withdrawn','not_promoted')
                   NOT NULL DEFAULT 'active'");
        $log[] = "enrollments.status: added 'not_promoted'";
    }

    /* --- 5. promotions gains the 'withheld' action ------------------- */
    $t = sr_col_type('promotions', 'action');
    if ($t !== null && strpos($t, 'withheld') === false) {
        $db->exec("ALTER TABLE promotions MODIFY action
                   ENUM('promoted','repeated','graduated','withheld') NOT NULL DEFAULT 'promoted'");
        $log[] = "promotions.action: added 'withheld'";
    }
    $t = sr_col_type('promotions', 'result');
    if ($t !== null && strpos($t, 'pending') === false) {
        $db->exec("ALTER TABLE promotions MODIFY result
                   ENUM('passed','failed','pending') NOT NULL DEFAULT 'passed'");
        $log[] = "promotions.result: added 'pending'";
    }

    /* --- 6. permission tables ---------------------------------------- */
    require_once __DIR__ . '/schema.php';
    foreach (['role_permissions', 'user_permissions'] as $tbl) {
        if (!sr_has_table($tbl)) {
            $db->exec(sr_schema_statements()[$tbl]);
            $log[] = "{$tbl}: created";
        }
    }

    /* --- 7. students.photo_path (v2.3 student photos) ----------------- */
    /* Fresh installs get this from schema.php; databases created before
       v2.3 need it added, otherwise saving a student with a photo fails
       on an unknown column. NULL = no photo, which is the normal case. */
    if (sr_col_type('students', 'photo_path') === null) {
        $db->exec("ALTER TABLE students
                   ADD COLUMN photo_path VARCHAR(255) NULL AFTER address");
        $log[] = 'students.photo_path: added (v2.3 photos)';
    }

    /* --- 8. v2.4 settings ---------------------------------------------
       New rows: databases created before v2.4 simply have no row, and
       setting() would fall back to its hardcoded default, but writing
       them makes the values visible and editable in the Settings page.
       v2.4.1: the brand toggle now defaults to keeping the Amharic name
       (brand_always_amharic = 1); the old brand_use_english row is left
       in place and simply no longer read. */
    $v24 = [
        ['year_auto_switch',      '1', 'bool',   1],
        ['year_start_date',       '01-01', 'string', 1],
        ['brand_always_amharic',  '1', 'bool',   1],
    ];
    $has = $db->prepare("SELECT COUNT(*) FROM settings WHERE skey = ?");
    $add = $db->prepare("INSERT INTO settings (skey, svalue, vtype, is_public) VALUES (?,?,?,?)");
    foreach ($v24 as $row) {
        $has->execute([$row[0]]);
        if (!(int)$has->fetchColumn()) { $add->execute($row); $log[] = "settings.{$row[0]}: added (v2.4)"; }
    }

    /* --- 9. recovery_keys table (v2.4 item 15, Master recovery) -------
       Fresh installs get this from schema.php. Older databases need the
       table created before the Forgot-password flow can issue or spend
       a key. Nothing is seeded: keys only exist once a Master makes one
       (or install.php mints the first one). */
    $tbl = $db->query("SHOW TABLES LIKE 'recovery_keys'")->fetchColumn();
    if (!$tbl) {
        $db->exec(sr_schema_statements()['recovery_keys']);
        $log[] = 'recovery_keys: table created (v2.4 master recovery)';
    }

    /* --- 10. v2.4.2 sign-in quotation ----------------------------------
       The branding panel of the sign-in page carries a Bible quotation in
       Amharic only, editable from Settings -> Identity. Seed the sample
       rows so the block renders on existing databases too; an empty text
       hides it. */
    $v242 = [
        ['login_quote_am', 'እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድዬ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ እንዳይጠፋ ይልቁንም የዘላለም ሕይወት እንዲኖረው።', 'string', 1],
        ['login_quote_ref',  'ዮሐንስ 3፡16', 'string', 1],
    ];
    foreach ($v242 as $row) {
        $has->execute([$row[0]]);
        if (!(int)$has->fetchColumn()) { $add->execute($row); $log[] = "settings.{$row[0]}: added (v2.4.2)"; }
    }

    return $log;
}
