<?php
/* ============================================================
   Canonical schema — used by install.php and by the
   "repair / upgrade schema" action. Every statement is
   idempotent (CREATE TABLE IF NOT EXISTS).
   ============================================================ */

function sr_schema_statements(): array
{
    return [

/* ---------------- users ---------------- */
'users' => "CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(64)  NOT NULL,
  email           VARCHAR(190) NULL,
  password_hash   VARCHAR(255) NOT NULL,
  full_name       VARCHAR(160) NOT NULL,
  role            VARCHAR(32)  NOT NULL DEFAULT 'teacher',
  phone           VARCHAR(40)  NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw  TINYINT(1)   NOT NULL DEFAULT 0,
  lang            ENUM('am','en') NOT NULL DEFAULT 'am',
  numerals        ENUM('geez','arabic','mix') NOT NULL DEFAULT 'mix',
  last_login_at   DATETIME     NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- academic years ---------------- */
'academic_years' => "CREATE TABLE IF NOT EXISTS academic_years (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_ec      SMALLINT UNSIGNED NOT NULL,
  label_am     VARCHAR(60)  NOT NULL,
  label_en     VARCHAR(60)  NOT NULL,
  is_current   TINYINT(1)   NOT NULL DEFAULT 0,
  is_closed    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_year_ec (year_ec),
  KEY idx_year_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- grades ---------------- */
'grades' => "CREATE TABLE IF NOT EXISTS grades (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(32)  NOT NULL,
  name_am      VARCHAR(80)  NOT NULL,
  name_en      VARCHAR(80)  NOT NULL,
  badge_am     VARCHAR(12)  NOT NULL DEFAULT '',
  badge_en     VARCHAR(12)  NOT NULL DEFAULT '',
  saint_am     VARCHAR(80)  NULL,
  saint_en     VARCHAR(80)  NULL,
  tone         VARCHAR(24)  NOT NULL DEFAULT '',
  level_no     SMALLINT     NOT NULL DEFAULT 0,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  next_grade_id INT UNSIGNED NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_code (code),
  KEY idx_grade_sort (sort_order),
  KEY fk_grade_next (next_grade_id),
  CONSTRAINT fk_grade_next FOREIGN KEY (next_grade_id) REFERENCES grades(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- students (identity — one row per human) ---------------- */
'students' => "CREATE TABLE IF NOT EXISTS students (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_code   VARCHAR(40)  NOT NULL,
  full_name      VARCHAR(160) NOT NULL,
  baptism_name   VARCHAR(160) NULL,
  gender         ENUM('male','female') NOT NULL DEFAULT 'male',
  birth_date     DATE         NULL,
  phone          VARCHAR(40)  NULL,
  guardian_name  VARCHAR(160) NULL,
  guardian_phone VARCHAR(40)  NULL,
  address        VARCHAR(255) NULL,
  photo_path     VARCHAR(255) NULL,
  notes          TEXT         NULL,
  status         ENUM('active','inactive','graduated','transferred') NOT NULL DEFAULT 'active',
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_code (student_code),
  KEY idx_student_name (full_name),
  KEY idx_student_status (status, deleted_at),
  CONSTRAINT fk_student_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* -------- enrollments (student ↔ grade ↔ academic year) -------- */
'enrollments' => "CREATE TABLE IF NOT EXISTS enrollments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  grade_id         INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  roll_no          SMALLINT UNSIGNED NULL,
  status           ENUM('active','promoted','repeated','graduated','withdrawn','not_promoted') NOT NULL DEFAULT 'active',
  result           ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
  enrolled_on      DATE NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enroll_student_year (student_id, academic_year_id),
  KEY idx_enroll_grade_year (grade_id, academic_year_id),
  KEY fk_enroll_year (academic_year_id),
  CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_grade   FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enroll_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- teacher ↔ class assignment ---------------- */
'teacher_classes' => "CREATE TABLE IF NOT EXISTS teacher_classes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  grade_id         INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  is_primary       TINYINT(1) NOT NULL DEFAULT 1,
  assigned_by      INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teacher_grade_year (user_id, grade_id, academic_year_id),
  KEY idx_tc_grade_year (grade_id, academic_year_id),
  KEY fk_tc_year (academic_year_id),
  KEY fk_tc_assigner (assigned_by),
  CONSTRAINT fk_tc_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_grade  FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_year   FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- attendance ----------------
   One row per student per calendar day (Saturday and Sunday are
   ALWAYS separate rows). Ethiopian date parts are stored alongside
   the Gregorian date so Sene/Hamle/Nehase/Pagume filtering is a
   plain indexed WHERE — month 13 = ጳጉሜ / Pagume.               */
'attendance' => "CREATE TABLE IF NOT EXISTS attendance (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id       INT UNSIGNED NOT NULL,
  enrollment_id    INT UNSIGNED NULL,
  grade_id         INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  session_date     DATE NOT NULL,
  et_year          SMALLINT UNSIGNED NOT NULL,
  et_month         TINYINT UNSIGNED NOT NULL,
  et_day           TINYINT UNSIGNED NOT NULL,
  day_of_week      ENUM('sat','sun','other') NOT NULL DEFAULT 'other',
  status           ENUM('present','absent','excused') NOT NULL,
  excuse_reason    VARCHAR(255) NULL,
  client_uuid      CHAR(36) NULL,
  recorded_by      INT UNSIGNED NULL,
  updated_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_att_student_date (student_id, session_date),
  UNIQUE KEY uq_att_client_uuid (client_uuid),
  KEY idx_att_grade_date (grade_id, session_date),
  KEY idx_att_year_month (academic_year_id, et_month),
  KEY idx_att_etdate (et_year, et_month, et_day),
  KEY idx_att_status (status),
  KEY idx_att_student_date_status (student_id, session_date, status),
  KEY fk_att_enrollment (enrollment_id),
  KEY fk_att_recorder (recorded_by),
  KEY fk_att_updater (updated_by),
  CONSTRAINT fk_att_student    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE SET NULL,
  CONSTRAINT fk_att_grade      FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_year       FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_recorder   FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_att_updater    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- promotions history ---------------- */
'promotions' => "CREATE TABLE IF NOT EXISTS promotions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  from_grade_id INT UNSIGNED NULL,
  to_grade_id   INT UNSIGNED NULL,
  from_year_id  INT UNSIGNED NULL,
  to_year_id    INT UNSIGNED NOT NULL,
  result        ENUM('passed','failed','pending') NOT NULL DEFAULT 'passed',
  action        ENUM('promoted','repeated','graduated','withheld') NOT NULL DEFAULT 'promoted',
  note          VARCHAR(255) NULL,
  performed_by  INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_promo_student (student_id),
  KEY idx_promo_years (from_year_id, to_year_id),
  KEY fk_promo_from_grade (from_grade_id),
  KEY fk_promo_to_grade (to_grade_id),
  KEY fk_promo_to_year (to_year_id),
  KEY fk_promo_user (performed_by),
  CONSTRAINT fk_promo_student    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_from_grade FOREIGN KEY (from_grade_id) REFERENCES grades(id) ON DELETE SET NULL,
  CONSTRAINT fk_promo_to_grade   FOREIGN KEY (to_grade_id) REFERENCES grades(id) ON DELETE SET NULL,
  CONSTRAINT fk_promo_from_year  FOREIGN KEY (from_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
  CONSTRAINT fk_promo_to_year    FOREIGN KEY (to_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_user       FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- settings ---------------- */
'settings' => "CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(64) NOT NULL,
  svalue      TEXT NULL,
  vtype       ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
  is_public   TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  INT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey),
  KEY fk_set_user (updated_by),
  CONSTRAINT fk_set_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- audit trail ---------------- */
'audit_logs' => "CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(64) NULL,
  role        VARCHAR(16) NULL,
  action      VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   VARCHAR(64) NULL,
  summary     VARCHAR(255) NULL,
  meta        LONGTEXT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- alert read-state (per user) ---------------- */
'alert_reads' => "CREATE TABLE IF NOT EXISTS alert_reads (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  alert_key  VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert_user_key (user_id, alert_key),
  CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- login throttling ---------------- */
/* -------- master recovery keys (v2.4, item 15) --------
   One row per issued key. Only the hash is stored, exactly like a
   password, so reading the table does not let anyone recover an
   account. A key is single use: used_at is stamped when it is spent
   and a spent key is never accepted again. Keys belong to a specific
   Master account, so a key cannot be replayed against another user. */
'recovery_keys' => "CREATE TABLE IF NOT EXISTS recovery_keys (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  key_hash   VARCHAR(255) NOT NULL,
  label      VARCHAR(120) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at    DATETIME NULL,
  used_ip    VARCHAR(45) NULL,
  PRIMARY KEY (id),
  KEY idx_rk_user (user_id, used_at),
  CONSTRAINT fk_rk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username   VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_lookup (username, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* -------- role permission defaults (editable by master) --------
   One row per (role, capability). A role with no rows at all falls
   back to the hardcoded defaults in config/auth.php, so the app is
   never left without a working permission set.                    */
'role_permissions' => "CREATE TABLE IF NOT EXISTS role_permissions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role       VARCHAR(32) NOT NULL,
  capability VARCHAR(48) NOT NULL,
  allowed    TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roleperm (role, capability),
  KEY fk_roleperm_user (updated_by),
  CONSTRAINT fk_roleperm_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* -------- per-user permission overrides --------
   allowed = 1 grants a capability the role does not have,
   allowed = 0 revokes one the role does have.                     */
'user_permissions' => "CREATE TABLE IF NOT EXISTS user_permissions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  capability VARCHAR(48) NOT NULL,
  allowed    TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_userperm (user_id, capability),
  KEY fk_userperm_updater (updated_by),
  CONSTRAINT fk_userperm_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_userperm_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

/* ---------------- import batches ---------------- */
'import_batches' => "CREATE TABLE IF NOT EXISTS import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token         CHAR(36) NOT NULL,
  filename      VARCHAR(255) NULL,
  source        ENUM('csv','excel','localstorage','json') NOT NULL DEFAULT 'csv',
  total_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  payload       LONGTEXT NULL,
  status        ENUM('preview','committed','discarded') NOT NULL DEFAULT 'preview',
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  committed_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_imp_token (token),
  KEY idx_imp_status (status),
  KEY fk_imp_user (created_by),
  CONSTRAINT fk_imp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    ];
}

/* Grade structure used by the existing UI (ቂርቆስ → ሚናስ → ክፍል ፩ … ፲፪).
   This is structural configuration, not sample data. */
function sr_default_grades(): array
{
    $geez = ['፩','፪','፫','፬','፭','፮','፯','፰','፱','፲','፲፩','፲፪'];
    $rows = [
        ['kirkos','ቂርቆስ','Kirkos','ቂ','K','ቅዱስ ቂርቆስ','St. Cyricus','',      0, 1],
        ['minas', 'ሚናስ', 'Minas', 'ሚ','M','ቅዱስ ሚናስ',  'St. Minas',  'minas',0, 2],
    ];
    for ($n = 1; $n <= 12; $n++) {
        $rows[] = ['g' . $n, 'ክፍል ' . $geez[$n-1], 'Grade ' . $n, $geez[$n-1], (string)$n, null, null, '', $n, $n + 2];
    }
    return $rows;
}

function sr_default_settings(): array
{
    return [
        ['school_name_am',        'ቅዱስ ሩፋኤል የሰንበት ትምህርት ቤት', 'string', 1],
        ['school_name_en',        'St. Rufael Church School',   'string', 1],
        ['school_sub_am',         'የተማሪ መገኘት አስተዳደር',        'string', 1],
        ['school_sub_en',         'Student Attendance Management','string',1],
        ['absence_alert_enabled', '1',                          'bool',   1],
        ['absence_alert_threshold','3',                         'int',    1],
        ['absence_alert_message_am','{student} ({grade}) ለ{days} ተከታታይ ቀናት አልተገኘም።', 'string', 1],
        ['absence_alert_message_en','{student} ({grade}) has been absent for {days} consecutive days.', 'string', 1],
        ['attendance_days',       'sat,sun',                    'string', 1],
        ['default_lang',          'am',                         'string', 1],
        ['id_card_footer_am',     'ይህ መታወቂያ የትምህርት ቤቱ ንብረት ነው።', 'string', 1],
        ['id_card_footer_en',     'This card remains property of the school.', 'string', 1],
        ['session_idle_minutes',  '120',                        'int',    0],
        /* v2.4 — the academic year turns over on its own start date; promotion
           no longer moves it. Ethiopian MM-DD, default Meskerem 1. */
        ['year_auto_switch',      '1',                          'bool',   1],
        ['year_start_date',       '01-01',                      'string', 1],
        /* v2.4.1 — the school name is a proper noun: keep the Amharic name
           in the header, on the sign-in page and on printed output whatever
           the interface language. Off, the name follows the language. */
        ['brand_always_amharic',  '1',                          'bool',   1],
        /* v2.4.2 — sign-in page quotation (Amharic only) shown on the left
           branding panel. Editable from Settings -> Identity; empty text
           hides the block entirely. */
        ['login_quote_am',        'እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድዬ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ እንዳይጠፋ ይልቁንም የዘላለም ሕይወት እንዲኖረው።', 'string', 1],
        ['login_quote_ref',       'ዮሐንስ 3፡16',                   'string', 1],
    ];
}
