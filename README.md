# St. Rufael Church School — Student Attendance Platform (v2)

A production-style **XAMPP (Apache + PHP + MySQL)** rebuild of the original
localStorage demo. Every screen of the old UI is preserved — the Amharic/English
interface, Ge'ez numerals, the Ethiopian calendar register, the responsive
sidebar and bottom tab bar — but all data now lives in MySQL behind PHP
endpoints with sessions, CSRF and per-endpoint permission checks.

> **The demo was 100 % client-side.** The backend in this repository is new; the
> stylesheet and the view renderers were carried over deliberately so the app
> still looks and feels like the original.

**v2.1 adds:** a fourth **editor** role, a Master-only role/permission editor,
admin-driven password resets with a forced first-run change-password page,
per-user Ge'ez / Arabic numeral preferences, a real Ethiopian calendar date
picker, withheld (not-promoted) students in the promotion flow, and the login
pages moved under `auth/`. See §13 for the full list.

---

## 1. What changed

### New files

| Path | Purpose |
|---|---|
| `install.php` | Guided installer: creates the database, all 15 tables and the first Master account. Idempotent. |
| `auth/login.php` / `auth/logout.php` | Two-sided login page (branding left, form right) with error + loading states; session teardown. **Moved out of the web root's top level into `auth/` in v2.1.** |
| `auth/new-password.php` | Forced first-run change-password page. Server-gated on `users.must_change_pw`; the only page a flagged user can reach. |
| `index.php` | The application shell. Replaces the demo's `index.html`; injects `window.SR_BOOT` (user, capabilities, language, year, settings, CSRF token). |
| `config/config.php` | DB credentials, session policy, paths, app name/version, timezone. |
| `config/database.php` | Cached PDO factory `db()`, `sr_is_installed()`. |
| `config/schema.php` | Idempotent `CREATE TABLE` statements in FK order + default grades/settings. |
| `config/auth.php` | Session bootstrap, role ranks, capability map, teacher scoping, CSRF, guards, `audit()`. |
| `lib/ethiopic.php` | JDN-based Ethiopian calendar with **Pagume as a first-class 13th month**. |
| `lib/helpers.php` | Input readers, JSON responses, validators, settings/year helpers. |
| `lib/xlsx.php` | Dependency-free OOXML `.xlsx` writer + CSV writer with a formula-injection guard. |
| `api/*.php` | 14 JSON endpoints (see §6), including `permissions.php` (role defaults + per-user overrides). |
| `config/migrations.php` | Eight idempotent upgrade steps, applied by `migrate.php`; safe to re-run (reports *"Already up to date"*). |
| `js/api.js` | `window.API` — fetch wrapper, CSRF, offline attendance queue, event bus. |
| `js/admin.js` | The ten administrative views (students, add/edit, settings, import, promotion, users, teachers, audit, backup, permissions). |
| `js/features.js` | The `FEATURES` toggle object. |
| `manifest.json`, `sw.js`, `offline.html` | PWA: installability, shell precache, offline page, Background Sync. |
| `.gitignore` | Keeps machine-local artefacts out of the repository: `config/installed.lock`, `config/config.local.php`, and the *contents* of `backups/`, `exports/` and `uploads/` (their `.htaccess` guards are still tracked). |
| `.htaccess` (root) | `Options -Indexes`, security headers, `no-store` on `sw.js`, gzip + caching, and a deny rule for `*.lock` / `*.sql` / `*.log` / `*.md` / dot-files. |
| `config/.htaccess`, `lib/.htaccess` | Deny-all. Both folders are include-only and must never be reachable over HTTP. |
| `config/config.local.example.php` | Template for per-machine DB credentials. Copy to `config/config.local.php` (git-ignored) instead of editing the tracked `config/config.php`. |
| `uploads/photos/.gitkeep` | Keeps the student-photo folder in the repository; ID-card rendering links into it. |
| `robots.txt` | `Disallow: /` — the administration system should never be indexed. |

### Modified files

| Path | Change |
|---|---|
| `js/app.js` | Rewritten data layer: every renderer now reads from `API` instead of `localStorage`. Router gained `window.SR_VIEWS` so `js/admin.js` can contribute pages. Pagume added as a real 4th month group. |
| `css/styles.css` | Original theme kept. Added: loading/error states, account menu, sync chip, dashboard month strip, Pagume column styling, and an **ADMIN PAGES** block. Excused is now amber (it previously fell through to a blue "late" rule). |

### Preserved from the demo

Grades list, attendance register, dashboard statistics, absence alerts,
settings, global search, Amharic/English switching, Ge'ez numerals, the
responsive sidebar and mobile tab bar.

---

## 2. Requirements

* XAMPP with **PHP 8.1+** (developed against 8.4) and **MySQL/MariaDB 10.4+**
* PHP extensions: `pdo_mysql`, `mbstring`, `json`, `zip`, `fileinfo`
* No Composer, no Node, no CDN at runtime — Bootstrap's grid and both font
  families are vendored under `vendor/` and `assets/fonts/`.

**`gd` is not required.** Because the target box had neither GD nor Composer,
PDFs and ID cards are generated as print-optimised HTML that opens the
browser's own *Print → Save as PDF* dialog. This keeps Amharic glyph shaping
perfect, which most PHP PDF libraries get wrong.

---

## 3. Installation on XAMPP

1. Copy the project into `htdocs`:

   ```
   C:\xampp\htdocs\st.ruphael\        (Windows)
   /Applications/XAMPP/htdocs/st.ruphael/   (macOS)
   ```

2. Start **Apache** and **MySQL** from the XAMPP control panel.

3. If your MySQL root account has a password, or you use a non-default port,
   **do not edit the tracked `config/config.php`.** Copy the example instead:

   ```
   cp config/config.local.example.php config/config.local.php
   ```

   ```php
   <?php
   // config/config.local.php — git-ignored, wins over the defaults
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', '3306');
   define('DB_NAME', 'st_rufael');
   define('DB_USER', 'root');
   define('DB_PASS', '');        // set your password here
   ```

   `config/config.php` loads this file first and then fills in every remaining
   constant with `sr_define()`, so anything you set locally wins and your
   credentials never end up in a commit.

4. Open **<http://localhost/st.ruphael/install.php>** and complete the form:

   * it verifies the PHP version and required extensions,
   * creates the database and all tables if they are missing,
   * seeds the 14 grades (ቂርቆስ, ሚናስ, ክፍል ፩–፲፪) and the default settings,
   * creates your **Master** account with `password_hash()`,
   * **inserts no dummy students or attendance.**

   Re-running it is safe: existing tables are left alone.

5. Sign in at **<http://localhost/st.ruphael/auth/login.php>** with the Master
   username and password you just chose.

6. After a successful install, `config/installed.lock` records the install
   date. Re-opening `install.php` detects the existing Master account and shows
   an "already installed" warning instead of silently rebuilding anything.
   **Delete or password-protect `install.php` once you are live.**

### Default Master account

There is **no hardcoded default password.** The installer asks you to choose
the Master username and password (minimum 8 characters) and stores only the
hash. If you lose it, reset it directly:

```sql
UPDATE users
   SET password_hash = '<output of password_hash("NewPass", PASSWORD_DEFAULT)>'
 WHERE username = 'master';
```

---

## 4. Database schema

15 tables, all InnoDB / `utf8mb4_unicode_ci`, with primary keys, foreign keys,
indexes and `created_at` / `updated_at` timestamps. Every query in `api/` uses
prepared statements.

| Table | Holds | Notable columns |
|---|---|---|
| `users` | Accounts | `username` UNIQUE, `password_hash`, `role` ENUM(**master, admin, editor, teacher**), `lang`, **`numerals` ENUM(geez, arabic, mix) DEFAULT `mix`**, `must_change_pw`, `deleted_at` |
| `academic_years` | Ethiopian academic years | `year_ec` UNIQUE, `is_current` |
| `grades` | Classes | `code` UNIQUE, `name_am`/`name_en`, `badge_am`/`badge_en`, `level_no`, `next_grade_id` FK, `sort_order`, `deleted_at` |
| `students` | **Identity only** | `student_code` UNIQUE, `full_name`, `baptism_name`, **`gender` ENUM(male, female)**, `birth_date`, guardian fields, `photo_path`, `status`, `deleted_at` |
| `enrollments` | Student × year × grade | **`UNIQUE(student_id, academic_year_id)`**, `roll_no`, `status` ENUM(active, promoted, repeated, graduated, withdrawn, **not_promoted**), `result` ENUM(pending, passed, failed) |
| `attendance` | One row per student per session | `session_date`, `et_year`/`et_month`/`et_day` (**13 = Pagume**), `day_of_week` ENUM(sat, sun, other), `status` ENUM(present, absent, excused), `excuse_reason`, `recorded_by`, `client_uuid` UNIQUE, **`UNIQUE(student_id, session_date)`** |
| `teacher_classes` | Teacher → grade assignment | `user_id`, `grade_id`, `academic_year_id`, `is_primary`, `assigned_by` |
| `promotions` | Promotion trail | `from_grade_id`, `to_grade_id` (nullable = graduated **or withheld**), `from_year_id`, `to_year_id`, `result`, `action` ENUM(promoted, repeated, graduated, **withheld**), `note`, `performed_by` |
| `settings` | Key/value config | `skey` PK, `svalue`, `vtype`, `is_public` |
| `audit_logs` | Audit trail | `user_id`, `username`, `role`, `action`, `entity_type`, `entity_id`, `summary`, `meta` JSON, `ip_address`, `user_agent` |
| `import_batches` | Import preview sessions | `token` UNIQUE, `payload` JSON, `status`, row counters |
| `alert_reads` | Dismissed alerts | `user_id`, `alert_key` |
| `login_attempts` | Throttling | `username`, `ip_address`, `success` |
| `role_permissions` | Role capability overrides set by Master | `role`, `capability` — rows **replace** the built-in defaults for that role; empty table = defaults in force |
| `user_permissions` | Per-user grants/revocations | `user_id`, `capability`, `allowed` (1 = grant, 0 = revoke) — applied on top of the role set |

### Why promotion never duplicates a student

Identity (`students`) is separated from placement (`enrollments`). Promoting
writes a **new enrollment row for the new year** and a `promotions` audit row —
it never copies the student. The `UNIQUE(student_id, academic_year_id)`
constraint makes a duplicate structurally impossible, and re-running a
promotion is reported as *"Already enrolled — skipped, no duplicate created."*
Attendance rows point at `student_id`, so history survives promotion, renaming
and soft deletion.

---

## 5. Roles and permissions

Ranks: **teacher (1) → editor (2) → admin (3) → master (4)**. Capabilities are
additive, and each level starts from the level below it.

| Role | Capabilities |
|---|---|
| **teacher** | `dashboard.view`, `students.view`, `attendance.view`, `attendance.edit`, `reports.view`, `export.students`, `idcard.view` |
| **editor** | everything a teacher has **+** `students.create`, `students.edit`, `students.import`, `grades.view`, `teachers.view` |
| **admin** | everything an editor has **+** `students.delete`, `grades.manage`, `teachers.manage`, `teachers.assign`, `promotion.manage`, `settings.view`, `settings.manage`, `years.manage`, `users.view`, `users.manage`, `users.reset_password` |
| **master** | everything an admin has **+** `backup.manage`, `features.manage`, `permissions.manage`, `audit.view`, `audit.purge` |

The **editor** role (new in v2.1) is the "data entry" tier: it can add, edit and
import students and see the class and teacher lists, but it cannot delete
students, touch settings, manage users or run a promotion.

Note what admin deliberately does **not** get: **`audit.view`** — the audit
trail is **Master only** — and **`features.manage`**, so the *Feature switches*
block in Settings is invisible to admins.

### Who may create whom

`master` is **never** an assignable role: no UI offers it and
`sr_assignable_roles()` refuses it server-side, so the account created by the
installer stays the only one unless you edit the database directly.

| Signed in as | May create / change users to |
|---|---|
| master | `admin`, `editor`, `teacher` |
| admin | `admin`, `teacher` |
| editor, teacher | — (no `users.manage`) |

`api/users.php` re-derives that list from the *session* role on both `create`
and `update` and answers `403 forbidden_role` for anything outside it — posting
`{"role":"master"}` with curl fails exactly like clicking would.

### Role / permission editor (Master only)

**Permissions** in the sidebar (`#/permissions`, capability
`permissions.manage`) edits capabilities in two layers:

* **Role defaults** — tick or untick capabilities for `admin`, `editor` or
  `teacher`. Saving *replaces* that role's set and writes it to
  `role_permissions`; every user with the role is affected immediately.
  **Reset** deletes the rows and restores the built-in defaults.
* **Per-user overrides** — pick a user and set each capability to
  *Inherit / Allow / Deny*. Grants and revocations land in `user_permissions`
  and are applied on top of the role set. **Clear** removes all of a user's
  overrides.

Five capabilities are **reserved** and can never be granted through the editor,
because doing so would let a non-master hand themselves the keys:
`permissions.manage`, `audit.view`, `audit.purge`, `backup.manage`,
`features.manage`. Attempting it returns `403 forbidden_cap`. The `master` role
itself is not editable (`editable:false`), and saving overrides against a master
account returns `409`.

Resolution order — `sr_effective_capabilities()`:

```
role defaults  →  role_permissions rows (replace, if any)  →  user_permissions (grant/revoke)
```

### Password resets and the first-run page

An admin (or master) opens **Users → Reset password**. The server mints a
random temporary password with `sr_temp_password()`, stores its hash, sets
`must_change_pw = 1` and **shows the temp password once** to the admin to pass
on — it is never emailed and never retrievable again.

When that user signs in, `index.php` redirects them to **`auth/new-password.php`**
and keeps redirecting until the flag clears. The page asks only for *new
password* + *confirm password*; `api/auth.php action=change_password` skips the
`current_password` requirement while `must_change_pw` is set, enforces the
8-character minimum and a match, clears the flag, and returns
`{changed:true, first_run:true, redirect:"../index.php"}`.

**Teacher scoping is enforced on the server**, not by hiding menus.
`sr_scope_grades()` narrows every list query to the teacher's assigned grades,
and `sr_assert_grade_access()` / `sr_assert_student_access()` reject anything
else with HTTP 403 (`forbidden_grade` / `forbidden_student`). Calling an API
directly with curl as a teacher returns the same refusal — see the test
checklist in §11.

---

## 6. API endpoints

All live under `api/`, speak JSON, and follow one convention:
`?action=<name>`, per-action HTTP method and capability checks, and a
`{ok: true, data: {...}}` / `{ok: false, error: {type, message}}` envelope.

| Endpoint | Actions |
|---|---|
| `auth.php` | `me`, `login`, `logout`, `change_password`, `update_profile` (name, email, phone, **numerals**) |
| `students.php` | `list`, `get`, `create`, `update`, `delete` (soft), `restore`, `bulk_delete` |
| `attendance.php` | `periods`, `register`, `save`, `stats`, `alerts`, `history`, `mark_alert_read`, `copy_last_week`, `mark_all`, `sync` |
| `grades.php` | `list`, `get`, `save`, `delete` |
| `users.php` | `list`, `get`, `create`, `update`, `reset_password`, `delete`, `restore` |
| `permissions.php` | `matrix`, `save_role`, `reset_role`, `user`, `save_user`, `clear_user` — all behind `permissions.manage` |
| `teachers.php` | `list`, `assignments`, `my_classes`, `assign`, `unassign` |
| `promotion.php` | `preview`, `run`, `history`, `undo` |
| `settings.php` | `get`, `save`, `years`, `add_year`, `set_current_year` |
| `import.php` | `upload`, `confirm`, `batches` |
| `export.php` | `students`, `attendance`, `template`, `idcards` |
| `audit.php` | `list`, `filters`, `export`, `purge` |
| `backup.php` | `create`, `list`, `restore`, `delete` |

Error types: `csrf` (419), `unauthenticated` (401), `bad_credentials` (401),
`forbidden_cap` / `forbidden_grade` / `forbidden_student` (403),
`forbidden_role` (403), `validation` (422), `not_found` (404),
`duplicate` (409), `too_large` (413), `server` (500).

---

## 7. Features

### Ethiopian calendar and Pagume

`lib/ethiopic.php` converts through Julian Day Numbers, so **Pagume is a real
month (13), not a label**. It carries 5 days in a common year and 6 in a leap
year (`et_month_days(2019, 13) === 6`), and it appears everywhere the other
months do: the month-group selector (**ሰኔ | ሐምሌ | ነሐሴ | ጳጉሜ**), attendance
entry, filtering, history, dashboard statistics, student records, and every
CSV / Excel / PDF export. `js/app.js` mirrors the same arithmetic so the client
and server never disagree.

### The date picker is a real calendar

The register's date control is an **Ethiopian month grid**, not a dropdown of
dates (it was a `<select>` before v2.1). It renders the seven Ethiopian weekday
headers, pads the first row to the correct starting weekday, and lays out all
30 days — or Pagume's 5 / 6. Only the **markable** days (the Saturdays and
Sundays of the open month group) are buttons; every other day is greyed and
inert. Today is ringed, the active date is filled, and the month arrows are
limited to the months of the open group, so Pagume is reachable from ነሐሴ but
you cannot wander into a month that has no register.

It is keyboard- and screen-reader-friendly (`role="dialog"`, `aria-expanded`
on the toggle, `aria-current="date"` on the selection) and closes on `Escape`
or an outside click. The chosen date drives Mark-All and Copy-Last-Week exactly
as the old select did — `state.regDate` is still the single source of truth.

### Numerals: Ge'ez, Arabic or mixed

Amharic attendance **dates now use Arabic numerals by default**, because Ge'ez
digits in a dense date grid were hard to scan. Each user chooses their own
policy in **Account → Profile**, stored in `users.numerals` and mirrored to
`localStorage.sr_numerals_v1`:

| Mode | Counts, statistics, percentages | Dates, years, times, phone numbers |
|---|---|---|
| `mix` **(default for new users)** | Ge'ez — ፵፯ | Arabic — 11 ነሐሴ 2018 |
| `geez` | Ge'ez | Ge'ez — ፲፩ ነሐሴ ፳፻፲፰ |
| `arabic` | Arabic | Arabic |

Three helpers in `js/app.js` keep this honest: `langNum()` for counts,
`dateNum()` for date parts and `digitsNum()` for literal digit strings such as
phone numbers. All three are no-ops in the English UI, which is always Arabic.
Clock times are **never** converted — `14:05` in Ge'ez digits is unreadable.
Admins can also set the numeral mode on a user's behalf from the user form.

### Attendance

* Exactly three statuses: **Present · Absent · Excused** — Excused always
  requires a reason. Clearing a cell deletes the row ("not marked").
* **Saturday and Sunday are separate records.** `UNIQUE(student_id,
  session_date)` plus a `day_of_week` column keep them distinct; the register
  renders one column per weekend day with the date and day clearly labelled.
* **Bulk actions:** Mark All Present / Absent / Excused, and **Copy Last Week**,
  which previews first (source = target − 7 days, matching day kind) and only
  writes with `confirm=true`, warning about overwrites.
* Dashboard totals (total / present / absent / excused / rate) are scoped to
  the signed-in user's permissions.

### Settings

Rebuilt in v2.1 — it used to be one long wall of text. Options are now grouped
into titled, icon-led cards (school identity · absence alerts · appearance ·
academic years · feature switches), fields sit on a two-column grid with short
helper lines instead of paragraphs, the placeholder tokens `{days}`,
`{student}` and `{grade}` are click-to-read chips, and a **sticky save bar**
follows you down the page and only lights up when something actually changed.

**Admins do not see the *Feature switches* card.** It is rendered only when
`can('features.manage')` is true, and that capability belongs to Master alone —
it is also one of the five reserved capabilities the permission editor refuses
to grant (§5), so no admin can be given it. The card is read-only status
anyway: the flags themselves live in `js/features.js`, and every endpoint
re-checks permissions regardless of what the file says.

### Absence alerts

A red alert fires when a student's consecutive-absence streak reaches the
**configurable** `absence_alert_threshold` (default 3, editable in Settings —
never hardcoded). The message template supports `{days}`, `{student}` and
`{grade}`, in both languages, with `{days}` rendered in Ge'ez numerals in
Amharic. Severity escalates at 2× the threshold; dismissals are per user.

### Students

Full CRUD with search and filters (name, baptism name, ID, grade, status,
gender — **male / female only**; the old `unspecified` option was removed in
v2.1 and existing rows were migrated to `male`), a detail modal, bulk
selection, and confirmation dialogs on every destructive action. **Deletes are soft** (`deleted_at`), so attendance history
is never lost — the API says so explicitly: *"Student removed. Their
attendance history has been kept."*

### Import

Upload → validate → preview → fix → confirm. Accepts `.csv`, `.tsv` and
`.xlsx` (6 MB / 5 000 rows). The preview highlights invalid rows in red with a
per-field reason, lets you untick individual rows, and **saves nothing** until
you confirm. Handled: missing names, unknown grades, unparseable dates
(`YYYY-MM-DD`, `DD/MM/YYYY`, `YYYY/MM/DD`), unrecognised gender, UTF-8/BOM, and
Amharic or English column headers.

**The template has no ID column** — student codes are issued by the system. Only
`full_name` and `grade` are required; everything else is optional. Grades accept
several spellings (`ቂርቆስ`, `Kirkos`, `kirkos`, `Grade 5`, `g5`, `5`). If a file
happens to carry IDs anyway they are repaired rather than rejected — see §16.3.
The template is downloadable as CSV or Excel and its Instructions sheet explains
all of this in both languages.

### Export and ID cards

Students and attendance export to **CSV, Excel (.xlsx), JSON and PDF**,
filtered by grade, year, current search, an explicit selection, or everything.
ID cards render name, baptism name, student ID, grade, academic year, school
name and photo (when present), ready to print. CSV output carries a UTF-8 BOM
and escapes leading `=+-@` against formula injection.

### Promotion

Choose the target year, optionally one grade, then **preview**: every student
is listed with their computed destination and a per-student override. Nothing
is written until you confirm. `undo` rolls a batch back while the target year
still has no attendance.

**Not every student advances**, and the plan says so explicitly. There are four
outcomes:

| Outcome | Meaning | What is written |
|---|---|---|
| **promote** | Passed, has a next grade | new enrolment in the next grade, `promotions.action='promoted'` |
| **graduate** | Passed, no next grade | source enrolment closed, `action='graduated'`, `to_grade_id` NULL |
| **repeat** | Failed | new enrolment in the **same** grade, `action='repeated'` |
| **withhold** | **Left behind — not promoted** | source enrolment set to `not_promoted`, `action='withheld'`, `to_grade_id` NULL, and **no enrolment in the target year at all** |
| *skip* | Excluded from this run | nothing at all |

Withheld students are the point of the fourth row: they are *not* silently
carried forward and *not* rolled into "repeat". They stay in the old year with
no place in the new one, the preview counts them in `left_behind_count` and
lists them under `left_behind[]`, and the UI shows a banner plus a *left
behind* pill per row. They remain candidates for a later promotion run, where
the preview marks them `was_withheld` so you can see why they are still on the
old year's roll. `undo` needs no special branch — deleting the target-year
enrolment is a no-op for a student who never got one, and the source enrolment
is restored to `active`.

### Backup and restore

Master-only. `create` streams a self-contained JSON snapshot (and keeps a
server copy); **no database credentials are ever written into the file** and
password hashes stay hashed. `restore` validates format, version and a SHA-256
checksum, refuses any archive with no user accounts (which would lock you
out), shows a preview with per-table row counts, takes an automatic
pre-restore safety copy, and only then replaces the data inside a transaction
with `FOREIGN_KEY_CHECKS` disabled.

### Audit trail

**The audit trail is Master only.** `audit.view` is not part of the admin
capability set, the *Audit* menu entry is hidden for everyone else, and
`api/audit.php` refuses with `403 forbidden_cap` — so an admin cannot read (or
quietly edit) the record of their own actions.

Logins (including failures), password changes and resets, student/user/settings
changes, permission changes, imports, exports, promotions, backups and restores
are all recorded with user, role, action, target, summary, JSON metadata, IP,
user agent and timestamp. The Audit page filters by text, action, entity, role
and date range, exports to CSV, and Master can purge entries older than N
days.

### PWA

`manifest.json` (name, icons at 192/512 + maskable, theme colour, standalone
display) and `sw.js` make the app installable. The service worker precaches 23
shell assets, serves navigations network-first falling back to `index.php` then
`offline.html`, and treats `/api/*.php` as **network-only** — a cached page is
never mistaken for live data.

**Offline attendance is a real sync queue, not a cache.** Marks saved while
offline go to `sr_sync_queue_v2` in localStorage, each with a `client_uuid`
that makes replay idempotent. The header chip shows **Offline → N pending →
Syncing → Synced / Sync failed**. The queue flushes on reconnect, and a
Background Sync registration (`sr-attendance-sync`) wakes the service worker so
marks still reach MySQL even if the tab was closed.

---

## 8. `js/features.js`

```js
window.FEATURES = {
  studentImport: true, studentExport: true, excelExport: true,
  pdfExport: true, studentIdCard: true,
  attendance: true, attendanceAlerts: true,
  studentPromotion: true,
  backupRestore: true, auditTrail: true,
  pwa: true
};
```

Flip any flag to `false` and the matching menu entries, buttons and pages
disappear. Read it with `FEATURE('pdfExport')`.

> **These toggles control visibility only — they are not a security boundary.**
> Every endpoint independently re-checks the session, the role capability and
> (for teachers) the assigned grades. Turning a flag back on in devtools
> reveals a button, never data.

---

## 9. Loading the demo data (`seed.php`)

`seed.php` fills an installed, empty system with a believable sample school so
every screen — dashboard, register, alerts, reports, promotion, ID cards — can
be explored with realistic data. **It is a development convenience: delete the
file before going live.**

Run it by opening `http://localhost/st.ruphael/seed.php` and pressing the
button (it is POST-only; loading the URL alone changes nothing).

It creates:

| | |
|---|---|
| Staff | `admin1` / `AdminPass#18`, `editor1` / `Editor#2018`, `teacher1` and `teacher2` / `Teacher#2018` |
| Students | 28 across Kirkos, Minas and Grades 1–8, with real generated codes and roll numbers |
| Attendance | every Saturday and Sunday from Meskerem to today — roughly 2,700 marks, about 81% present |
| History | the previous academic year, closed, plus 24 promotion records |

`teacher1` and `teacher2` are each assigned two grades, so signing in as one of
them is the quickest way to see class scoping enforced. A couple of students
are deliberately given a long absence streak so the alert panel has something
to show.

**Everything it writes is tagged**, so removal is exact: students carry
`[demo-data]` in `notes`, demo users in `phone`, audit rows in `summary`.

- **Reload** — tick *Wipe first*, run again: identical data, no duplicates.
- **Remove** — tick *Wipe first* **and** *Only wipe*. Every tagged row goes;
  your `master` account and anything you created yourself are untouched.

The whole run is one transaction, so a failure leaves the database exactly as
it was. It refuses to run before installation, never overwrites an existing
username, and asks for confirmation if it finds students that are *not* demo
data.

When you are done: **delete `seed.php`**. Nothing else references it.

---

## 10. Security

* **The login and logout pages live under `auth/`**, not at the document root
  (v2.1). Everything that handles credentials sits in one directory you can
  lock down in one place — `<Directory>` rules, an IP allowlist or an extra
  `.htaccess` now cover the whole sign-in surface, and the root of the app no
  longer advertises `login.php` to a scanner. `install.php` links there,
  `index.php` redirects there, and `sw.js` bypasses `auth/*` so the service
  worker can never serve a cached login form.
* `password_hash()` / `password_verify()` with `PASSWORD_DEFAULT`; hashes are
  never returned by any endpoint. Admin resets mint a random temporary
  password, store only its hash, display it once, and force a change on the
  next sign-in (§5).
* **`master` cannot be granted through the app** — not by the user form, not by
  the API, and not through the permission editor, which additionally refuses
  the five reserved capabilities that would amount to the same thing.
* PHP sessions with a custom name, `HttpOnly` + `SameSite=Lax` cookies, session
  ID regeneration on login, a configurable idle timeout (default 2 h) and a
  12 h absolute cap. **No authentication state is stored in localStorage** —
  the only things kept client-side are the chosen language and the offline
  attendance queue.
* **CSRF** token required on every state-changing request (`X-CSRF-Token`
  header or `csrf_token` field), compared with `hash_equals()`.
* **PDO prepared statements everywhere**; no string-interpolated user input in
  SQL.
* Server-side validation on every input; all output escaped with `esc()` /
  `e()`; JSON responses sent with `X-Content-Type-Options: nosniff`.
* Per-endpoint capability checks plus teacher grade scoping — the frontend's
  claims about the user's role are never trusted.
* Login throttling (8 attempts, 15-minute lockout) recorded in
  `login_attempts`.
* Uploads: extension and size allowlists, `is_uploaded_file()` checks, and
  `uploads/`, `exports/`, `backups/` each carry a deny-all `.htaccess`
  (all three are written by `install.php`; `uploads/` additionally sets
  `php_flag engine off`).
* `config/` and `lib/` also carry deny-all `.htaccess` files — they are
  include-only and hold the DB credentials and the capability map.
* The root `.htaccess` disables directory indexes, blocks dot-files and
  `*.lock` / `*.sql` / `*.log` / `*.bak` / `*.md`, sends
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and
  `Permissions-Policy`, and serves `sw.js` with `no-store`. It ships with
  commented-out blocks that lock down `install.php` and `migrate.php` —
  uncomment them once you are live.
* DB credentials belong in `config/config.local.php`, which is git-ignored;
  `robots.txt` disallows all crawlers.

> Apache-only: these `.htaccess` rules are inert under PHP's built-in
> development server (`php -S`), which ignores them entirely.

---

## 11. Test checklist

### Authentication
- [ ] `install.php` creates the DB, tables and Master account; re-running is safe and reports clearly.
- [ ] Login succeeds with the Master credentials; a wrong password shows an inline error, not a blank page.
- [ ] The login button shows a loading state; the layout is two-sided on desktop and single-column on mobile.
- [ ] Sign out clears the session; reopening `index.php` redirects to `auth/login.php`.
- [ ] `login.php` and `logout.php` no longer exist at the document root; `auth/login.php` loads with its CSS and posts correctly.
- [ ] Admin resets a teacher's password → the temporary password is shown once. Sign in with it → you land on `auth/new-password.php` and **cannot** navigate away to `index.php`.
- [ ] The new-password page rejects a mismatch and anything under 8 characters; a valid change clears `must_change_pw` and lands on the dashboard. The old temporary password no longer works.
- [ ] Idle past the timeout → next API call returns `unauthenticated` and the UI redirects to login.

### Permissions (server-side)
- [ ] Teacher sees only 4 menu entries; Editor, Admin and Master see progressively more.
- [ ] **Editor** can add, edit and import students but sees no Delete button, no Settings, no Users and no Promotion; the matching API calls return `forbidden_cap`.
- [ ] **Audit is Master-only:** an admin has no *Audit* menu entry, `#/audit` says "Not permitted", and `curl` on `api/audit.php?action=list` as an admin returns `forbidden_cap`.
- [ ] **Admin sees no *Feature switches* card** in Settings; Master does.
- [ ] The user-create role dropdown **never** lists `master`. As master it offers admin/editor/teacher; as admin only admin/teacher.
- [ ] Posting `{"action":"create","role":"master",...}` to `api/users.php` fails with `forbidden_role`, as does an admin posting `role: "editor"`.
- [ ] **Role editor:** as master, untick `students.import` for editor → save → an editor's import page disappears and `import.php` returns `forbidden_cap`. **Reset** restores it.
- [ ] **Per-user overrides:** deny `students.edit` for one editor → only that user loses the button. Set it back to *Inherit* → restored.
- [ ] Any attempt to grant `permissions.manage`, `audit.view`, `audit.purge`, `backup.manage` or `features.manage` returns `403 forbidden_cap`; the `master` role is not editable and saving overrides on a master account returns `409`.
- [ ] Teacher opening `#/users`, `#/audit`, `#/backup` or `#/settings` gets "Not permitted".
- [ ] **Direct API as a teacher is refused**, not merely hidden:
      ```bash
      curl -b jar.txt "http://localhost/st.ruphael/api/users.php?action=list"
      # {"ok":false,"error":{"type":"forbidden_cap",...}}
      curl -b jar.txt "http://localhost/st.ruphael/api/attendance.php?action=register&grade_id=<other teacher's grade>"
      # {"ok":false,"error":{"type":"forbidden_grade",...}}
      ```
- [ ] A teacher's dashboard totals count only their own students.
- [ ] A teacher cannot export another teacher's grade (`forbidden_grade`).

### Students
- [ ] Create, edit, search (name / baptism name / ID), filter by grade and status.
- [ ] The gender control offers **male and female only** — no *unspecified* anywhere in the form, the filter, the import template or an export.
- [ ] Deleting asks for confirmation and is **soft** — the student disappears from lists but their attendance rows remain in the database.
- [ ] The detail modal shows enrolment history, promotion history and the attendance summary.

### Import / export / PDF / ID card
- [ ] Download the CSV and Excel templates.
- [ ] Upload a file containing a duplicate ID, a missing name, an unknown grade and a malformed date → the preview flags exactly those rows in red with reasons, and the valid count matches.
- [ ] Nothing is written until Confirm; unticked rows are skipped.
- [ ] Export students as CSV, Excel, JSON and PDF; Amharic renders correctly in all four.
- [ ] Export filtered by grade, and by an explicit checkbox selection.
- [ ] ID cards preview and print with name, baptism name, ID, grade, year and school name.

### Attendance
- [ ] Mark Present / Absent / Excused; Excused demands a reason.
- [ ] **Pagume appears as the 4th month group** after ነሐሴ, with 5 columns (6 in a leap year) and real dates — check the register, filters, dashboard month strip and exports.
- [ ] **Saturday and Sunday stay separate** — marking Saturday does not change Sunday.
- [ ] The date control opens a **month grid**, not a dropdown: weekday headers, correct leading offset, weekends clickable, weekdays greyed out, today ringed, selection filled.
- [ ] Its arrows walk only the months of the open group and stop at both ends; opening the ጳጉሜ group shows 5 cells (6 in a leap year).
- [ ] Picking a day updates the toggle label and the subsequent Mark-All / Copy-Last-Week write to that date. `Escape` and an outside click close the popup.
- [ ] **Numerals:** with the default `mix`, Amharic dates read `11 ነሐሴ 2018` while counts stay Ge'ez. Switch the profile to *Ge'ez only* → dates become `፲፩ ነሐሴ ፳፻፲፰`; switch to *Arabic only* → counts become `47`. The choice survives a reload and follows the account to another browser. Clock times stay Arabic in every mode, and the English UI is always Arabic.
- [ ] Mark All Present / Absent / Excused fills the visible column.
- [ ] Copy Last Week previews first, is editable, and warns before overwriting.
- [ ] Set the alert threshold to 2 in Settings → a student with 2 consecutive absences turns red; set it back to 3 and the alert clears. Confirm `{days}`, `{student}` and `{grade}` all interpolate.

### Promotion
- [ ] Preview lists every student with their destination grade and a per-student Passed / Failed / Skip override.
- [ ] Run the promotion, then verify **no student identity was duplicated**:
      ```sql
      SELECT student_id, academic_year_id, COUNT(*) c
        FROM enrollments GROUP BY student_id, academic_year_id HAVING c > 1;   -- empty
      SELECT COUNT(*) FROM students WHERE deleted_at IS NULL;                  -- unchanged
      ```
- [ ] A student marked Failed repeats the same grade in the new year.
- [ ] A student marked **not promoted / withheld** gets **no enrolment in the target year**, their old enrolment reads `not_promoted`, and `promotions.action` is `withheld` with a NULL `to_grade_id`:
      ```sql
      SELECT action, to_grade_id FROM promotions
       WHERE student_id = <id> ORDER BY id DESC LIMIT 1;          -- withheld | NULL
      SELECT status FROM enrollments
       WHERE student_id = <id> AND academic_year_id = <source>;   -- not_promoted
      SELECT COUNT(*) FROM enrollments
       WHERE student_id = <id> AND academic_year_id = <target>;   -- 0
      ```
- [ ] The preview reports `left_behind_count`, the banner and the *left behind* pill appear, and the counters (promote / repeat / graduate / withhold / skip) add up to the roll.
- [ ] Re-previewing next year shows the withheld student again, flagged `was_withheld`.
- [ ] Undo restores a batch that contained withheld students without error.
- [ ] Attendance rows from the previous year are still present and still attached to the same students.
- [ ] Re-running the same promotion reports "already enrolled — skipped".
- [ ] Undo rolls the batch back (only while the target year has no attendance).

### Backup / audit
- [ ] Create a backup; the JSON downloads and contains no DB credentials.
- [ ] Restoring shows a preview with row counts before touching anything, and a pre-restore copy appears in `backups/`.
- [ ] Uploading a truncated or edited backup is rejected (`checksum_failed`).
- [ ] Audit page lists logins, student edits, imports, exports and promotions; filters and CSV export work.

### PWA
- [ ] Chrome DevTools → Application → Manifest shows no errors; the install icon appears.
- [ ] Service worker reaches "activated"; the shell cache holds the CSS, JS, fonts and icons.
- [ ] Reload with DevTools set to Offline → the shell still renders (not the browser error page).
- [ ] Save attendance while offline → the chip reads "N pending sync" and the queue is in `localStorage.sr_sync_queue_v2`.
- [ ] Go back online → the chip moves to "Syncing" then "Synced", the queue empties, and the marks are in MySQL.

---

## 12. Known limitations

* **PDFs are print-to-PDF.** With no GD and no Composer on the target box, PDF
  and ID-card output is print-optimised HTML that opens the browser's print
  dialog. This renders Amharic correctly, but it is not a server-side PDF file.
* **Offline covers attendance only.** Creating students, imports, promotions
  and settings changes all require a connection; the offline queue is
  deliberately limited to attendance marks.
* **Backup/restore is whole-database.** There is no per-table or
  point-in-time restore, and restore replaces everything.
* **No photo upload UI yet.** The schema and ID cards support `photo_path`, but
  images must currently be placed in `uploads/photos/` and the column set
  directly.
* **The permission editor cannot create new capabilities or new roles.** It
  redistributes the fixed catalogue in `sr_capability_catalog()` across the
  four built-in roles; adding a fifth role is still a code change. Five
  capabilities are permanently reserved to Master (§5).
* **Temporary passwords are shown once.** If the admin closes the dialog before
  copying it, the only remedy is another reset — the plaintext is never stored.
* **Numerals are a display preference, not a data format.** Everything is
  stored and exported in Arabic digits; Ge'ez rendering happens in the browser,
  so a CSV or Excel export is always Arabic regardless of the setting.
* **Single school / single tenant.**
* Import is capped at 6 MB and 5 000 rows per file; split larger sheets.
* `install.php` should be deleted or protected on a public server. This app is
  designed for a LAN/XAMPP deployment — put it behind HTTPS before exposing it
  to the internet, and set a MySQL root password.

---

## 13. v2.1 change log

Thirteen changes requested after the first delivery. All of them are live; the
sections in brackets carry the detail.

| # | Change | Where |
|---|---|---|
| 1 | **New `editor` role** — a fourth tier between teacher and admin: may create, edit and import students, may not delete them or touch settings, users or promotion. | §5 |
| 2 | **`master` is never offered when creating a user.** An admin may create only `admin` and `teacher` accounts. Enforced by `sr_assignable_roles()` inside `api/users.php` on both create and update — not just in the dropdown. | §5 |
| 3 | **Password reset with a forced first-run page.** The reset mints a temporary password shown once to the admin; the user is then locked onto `auth/new-password.php` (new password + confirm) until they change it. | §5 |
| 4 | **Gender is male / female only.** `unspecified` removed from the ENUM, the forms, the filters, the import parser and the exports; existing rows migrated. | §7 |
| 5 | **Promotion handles students who were not promoted.** A fourth outcome, *withhold*, leaves them in the old year with no target-year enrolment, counts them in `left_behind[]`, and surfaces them again in the next run as `was_withheld`. | §7 |
| 6 | **The audit trail is Master only.** `audit.view` was removed from the admin capability set and is one of the five capabilities the permission editor refuses to grant. | §5, §7 |
| 7 | **Role / access editor in the Master account** — edit the capability defaults per role *and* grant/deny per user, with a reserved-capability guard. | §5 |
| 8 | **Admins no longer see the Feature switches section** in Settings. | §7 |
| 9 | **Settings page restyled** — grouped icon cards, a two-column field grid, short helper lines instead of paragraphs, token chips and a sticky save bar. | §7 |
| 10 | **Per-user numerals.** Amharic dates default to Arabic digits; each user picks Ge'ez / Arabic / mixed in their profile, and admins can set it on their behalf. Default for new users is `mix`. | §7 |
| 11 | **`login.php` and `logout.php` moved into `auth/`**, so the whole credential surface can be hardened in one directory. | §10 |
| 12 | **The date picker is a real Ethiopian calendar** — a month grid with weekday headers and Pagume, replacing the `<select>` list of dates. | §7 |
| 13 | **The `.cr-sub` class was removed** from `css/styles.css` and every template that referenced it. | — |

### Upgrading an existing v2.0 install

1. Pull the new files.
2. Run **`migrate.php`** once in the browser. It is idempotent — it adds
   `users.numerals`, extends `users.role` with `editor`, narrows
   `students.gender`, extends the promotion/enrolment ENUMs and creates
   `role_permissions` / `user_permissions`. Re-running reports *"Already up to
   date"*.
3. Update any bookmark or link that pointed at `login.php` — it is now
   **`auth/login.php`**. Hard-refresh once (or unregister the service worker)
   so the old shell cache is dropped.
4. Nothing else is required: with `role_permissions` and `user_permissions`
   empty, the built-in defaults in §5 are in force, and every existing user
   keeps their role and gets `numerals = 'mix'`.

---

## 14 · v2.2 change log — credentials & UI/UX pass

Six follow-up items, all UI-facing. No schema change, so **no migration step is
required**: pull the files and hard-refresh once (the service worker version was
bumped to `sr-v2.2.0`, which drops the old shell cache automatically).

| # | Change | Where |
|---|--------|-------|
| 1 | **Reset password now mints a temporary password.** `api/users.php action=reset_password` always generates a `Word-Word-NNN` password via `sr_temp_password()`, sets `must_change_pw = 1` and ignores any password sent by the client. The admin gets a one-time hand-off card with copy buttons; the user signs in with it and is taken straight to the change-password page to choose their own. | §6, §10 |
| 2 | **Import, Teachers and Backup pages realigned and made responsive.** The import upload row is a proper `.up-row` grid with a separate template strip; backup gained a labelled create/restore row; the teacher roster is now a card grid (`.tc-grid`) instead of a five-column table that overflowed on phones. All three fit a 390 px viewport with no horizontal scroll. | §7 |
| 3 | **Student profile redesigned.** The old six-tile grid plus bare month table was replaced with: a header carrying the grade as a pill and the code in monospace, an overview block with an attendance donut and four stat tiles, a two-column detail list that hides empty fields, tappable contact rows, and per-month stacked bars with a legend. Actions moved to a sticky footer. | §7 |
| 4 | **App-wide alignment and padding fixed.** Root cause: the generic `card()` helper emitted `<section class="card set-card">`, but `.set-card` is declared `padding:0` (the settings page paints its own bands), so every admin card rendered flush to its border. `card()` now emits an optional `.set-head.plain` and a padded `.set-body`. Separately, `.view` no longer carries `min-height:calc(100vh - var(--head-h))` — `body` is a flex column and `.app-foot` has `margin-top:auto`, which removed 217–268 px of dead space under every short page. Wide tables now stack into labelled cards on phones (`.tbl-stack`, populated by `labelTableCells()`), and row actions collapse to icons via `.row-acts`. | §7 |
| 5 | **New users get a generated password.** Creating a user with the password field left empty mints a temporary password and returns it once in the same hand-off card. `must_change_pw` is set, so the account must choose its own password at first sign-in. | §6 |
| 6 | **Add-User modal polished.** The Active toggle became a labelled switch row with a helper line, and the teacher grade picker is a chip grid with select-all / clear-all and a live count. Hidden inputs overlaid on labels needed `position:relative` on `.uf-gr-lbl` / `.uf-tog`. | §7 |

### CSS layout contract (important for future work)

`.set-card` is `padding:0` **by design** — the settings page paints its own
`.set-head` / `.set-body` bands. Anything rendered into it must supply those
wrappers. Both `card()` and `setCard()` in `js/admin.js` do this now. Use
`tblIn()` for a table **inside** a card body and `tblCard()` only for a
standalone table card; nesting `tblCard()` in a card body produces a
double border.

---

## 15 · Repository hygiene files (v2.2.1)

An audit of "which files does the code reference that the repository does not
actually ship?" turned up seven. Everything the app *executes* was present —
these are the files that make a checkout deployable and safe rather than
merely runnable.

| Path | Why it now exists |
|---|---|
| `.gitignore` | The repository was tracking `config/installed.lock` — a per-machine artefact written by `install.php` — and had no rule for `backups/`, `exports/` or `uploads/`. A backup or an export dropped into a working copy would have been committed. Contents are ignored; the folders and their `.htaccess` files are kept. |
| `.htaccess` (root) | `Options -Indexes`, security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`), `no-store` on `sw.js` so a deploy is never pinned to a stale shell, gzip + far-future caching for fonts and icons, and a deny rule for `*.lock`, `*.sql`, `*.log`, `*.md` and dot-files. Commented-out blocks at the bottom lock down `install.php` and `migrate.php` for a live server. |
| `config/.htaccess` | `config/` holds the DB credentials, the schema and the capability map, and is include-only. Deny-all, matching the rule the other write-able folders already had. |
| `lib/.htaccess` | Same reasoning for the Ethiopian calendar, the helpers and the xlsx writer — includes, never entry points. |
| `config/config.local.example.php` | §3 told you to edit `config/config.php` with your MySQL password, which puts a live credential in a tracked file. `config.php` now loads `config/config.local.php` first (ignored by git) and guards every default with `sr_define()`, so a local copy wins with no merge conflicts. Copy the example, edit, done. |
| `uploads/photos/.gitkeep` | `install.php` creates `uploads/photos/` and `api/export.php` renders `<img src="../uploads/photos/…">` on ID cards, but git cannot track an empty directory — a fresh clone that had not run the installer produced broken images. |
| `robots.txt` | A private school administration system should never be indexed. |

### Also fixed

* `install.php` wrote a deny-all `.htaccess` into `backups/` and `uploads/` but
  **skipped `exports/`**, even though §10 claimed all three were covered.
  It now writes all three.
* `config/config.php` was re-include-unsafe in one edge case: every constant used
  a bare `define()`, so a second `require` (rather than `require_once`) raised
  notices. All constants now go through `sr_define()`, and `sr_define()` itself
  is wrapped in `function_exists()`.

### What the audit checked and found clean

Manifest icons (11) · service-worker `SHELL` and `OPTIONAL` precache lists (15) ·
`@font-face` sources (20) · every `api/*.php` endpoint named by `js/api.js` (12) ·
every `API.<group>.<method>` called from `js/app.js` and `js/admin.js` (0 missing) ·
every `ICON.<key>` reference (27 defined, 0 missing) · every `t('key')` lookup
against both language tables (305 keys each, in perfect parity) · `php -l` on all
26 PHP files and `node --check` on all 5 JS files · every CSS class used in markup
against `styles.css` + `fonts.css` + inline `<style>` + vendored Bootstrap (the
only unmatched names are 20 deliberate JS-hook classes such as `.us-edit`,
`.stu-open` and `.imp-ck`, which carry behaviour, not style).

---

## 16 · v2.3 change log — identifiers, photos, navigation, demo data

Ten requested changes. **No schema migration is required**: the one new column
(`students.photo_path`) is created by `config/schema.php` on a fresh install and
added by `migrate.php` on an existing one.

| # | Change | Where |
|---|--------|-------|
| 1 | **Every file is commented** — headers explaining what each file owns, section banners, and notes on the non-obvious parts (the hand-built `.xlsx` container, the promotion decision vocabulary, the shared-string pool, the CSRF and capability gates). | all |
| 2 | **The school name is always Amharic**, in both interface languages — it is a proper noun, not UI text. | §16.1 |
| 3 | **Student photos**, captured on the Add/Edit form and printed on the ID card. | §16.2 |
| 4 | **Student ID and roll number are generated by the server** and shown locked. IDs use the `18-0042` format and imports repair foreign IDs instead of failing. | §16.3 |
| 5 | **Add Student and Import Students are now sub-items under Students** in the sidebar, and every page is reachable on a phone via the More sheet. | §16.4 |
| 6 | **The localStorage-demo migration is gone** — JavaScript, API action, installer step, UI and documentation. | — |
| 7 | Production-readiness pass. | §16.5 |
| 8 | **`seed.php`** — a deletable sample-school loader. | §9 |
| 9 | **Alignment pass** across every page at 1440 / 1024 / 768 / 390 px. | §16.6 |
| 10 | No screenshots are kept in the repository. | — |

### 16.1 Brand language

The school name renders identically whichever language is active. The rule is
enforced in two places, and **both must change together** or the name will flip
once the SPA takes over from the server-rendered markup:

* `index.php` — the `<header>` prints `school_name_am` directly.
* `js/app.js` → `applyStaticText()` → **`const BRAND_ALWAYS_AMHARIC = true`**.

Set that constant to `false` to make the name follow the interface language.
The names themselves are settings (`school_name_am` / `school_name_en`), edited
under Settings — not hard-coded.

### 16.2 Student photos

Optional, on the Add/Edit Student form and the ID card.

* **Resized in the browser** before upload — longest edge 600 px, JPEG quality
  0.82 (`photoResize()` in `js/admin.js`). This is deliberate: the server has no
  `gd` extension, so it cannot resize. It also means a 4 MB phone photo leaves
  the browser at roughly 60 KB.
* Accepted: JPEG, PNG, WebP, up to `SR_PHOTO_MAX_BYTES` (2 MB, in
  `config/config.php`). The server re-verifies the bytes are a real image with
  `getimagesizefromstring()` — the declared MIME type is never trusted.
* Stored as `uploads/photos/s<id>-<8 hex>.<ext>`; the random suffix stops a
  guessable URL and busts the browser cache on replacement.
* Replacing a photo unlinks the old file. `sr_delete_photo()` refuses any path
  outside `uploads/photos/`.
* The directory is protected by a deny-all `.htaccess`; images are served
  through the app, never listed.

Students without a photo show a neutral silhouette on the card, so a printed
sheet stays uniform.

### 16.3 Automatic identifiers

Student codes and roll numbers can no longer be typed. Both boxes still appear
on the form — greyed out and reading **ራስ-ሰር** ("auto") — so the layout is
honest about what will happen rather than hiding the fields.

#### The student ID format

```
18-0042
││ └──┴─ sequence, 4 digits, restarts at 0001 every academic year
└┴────── last two digits of the Ethiopian year (2018 EC -> 18)
```

Short enough to read aloud and write on a form, sortable, and it carries the
intake year. The whole format lives in **`lib/helpers.php` and nowhere else**:

| Piece | What it does |
| --- | --- |
| `SR_CODE_SEQ_WIDTH` | digits after the dash (4) |
| `sr_code_prefix($yearEc)` | builds `18-` from `2018` |
| `sr_is_auto_code($code)` | matches `^\d{2}-\d{4}$` |
| `next_student_code()` | next free code, skipping ones already taken |

To change the format — wider sequence, four-digit year, a school prefix —
edit those helpers; the API, the importer, the export template and the ID card
all read through them.

Codes are **never reused**. Deleting a student is a soft delete and their row
keeps the code, so `18-0007` cannot be handed to somebody else later.

* **Roll number** — `next_roll_no()`, restarting at 1 in each grade each year.
  Re-saving a student keeps their number; moving them to another grade mints a
  new one.

The server ignores any `student_code` or `roll_no` a client sends, so a crafted
request cannot set them either.

**To re-enable manual entry**, set `SR_MANUAL_IDENTIFIERS` to `true` in
`config/config.php`. The flag travels to the browser as `SR_BOOT.manual_ids`,
unlocking both inputs and switching the API back to accepting supplied values.

#### Imports never fail over an ID

The **sample spreadsheet has no ID column** — IDs are issued by the system, so
asking whoever fills in the sheet to invent one only creates clashes.

If an uploaded file *does* carry a `student_code` column, the import repairs it
instead of rejecting the row (`sr_import_resolve_code()` in `api/import.php`):

| The file says | What happens |
| --- | --- |
| a valid, unused code such as `18-0031` | kept as-is |
| a foreign scheme such as `STU/0012` or `SR-2018-0100` | renumbered; the original is appended to Notes as `[imported ID: STU/0012]` |
| a code already used by another student | renumbered, original kept in Notes |
| the same code twice in one file | the first keeps it, the rest are renumbered |
| blank | a code is minted |

These are **warnings, not errors** — the preview counts them under
*IDs auto-assigned* and the import still commits. Nothing a registrar can type
in a spreadsheet can block an upload.

### 16.4 Sidebar sub-menu

`NAV_ITEMS` in `js/app.js` now supports a `children` array; **Students** owns
*Add Student* and *Import Students*. The group expands automatically whenever
any page inside it is open, and the parent is marked `.active-parent` so the
trail stays visible. Adding another sub-item is one entry in that array — the
renderer, the active-state logic and the mobile navigation all follow.

Behaviour worth knowing:

* clicking a parent that is already open **collapses it**, and that manual
  choice is remembered (`navManual`) until you navigate out of the group;
* the group is a CSS grid that animates between `0fr` and `1fr`. That trick
  only collapses **one** grid row, so the links live in a single
  `.side-subs-in` wrapper. Adding a second direct child of `.side-subs` would
  leave a strip of dead space behind when closed — this was a real bug;
* a rail runs down the sub-list and turns gold beside the active child;
* hidden children get `tabindex="-1"` so Tab skips a collapsed group, and the
  parent carries `aria-expanded`.

#### Mobile navigation

The bottom bar has room for five items but a master can reach thirteen pages.
It now shows **four pages plus More**, and More opens a bottom sheet listing
everything else the signed-in user is allowed to open — rendered from the same
`NAV_ITEMS` list, so a page can never exist on desktop and be unreachable on a
phone. Sub-items are labelled with their parent ("Add" under "Students"), and
the More tab lights up while one of its pages is open.

### 16.5 Production readiness

* `APP_VERSION` and the service-worker cache are both **2.3.0**. The cache name
  must change on every deploy or returning browsers keep the old shell.
* `install.php` writes deny-all `.htaccess` files into `backups/`, `uploads/`
  and `exports/`.
* After installing, **delete `install.php` and `seed.php`**.
* Detailed error messages are **off unless you ask for them**: the API returns
  a generic *"A server error occurred."* and writes the real SQLSTATE to the
  PHP error log. Set the `SR_DEBUG=1` environment variable to surface the real
  message while developing, and never set it on a live server.
* Bootstrap is vendored locally — the platform works on a LAN with no internet.
  Never reintroduce the CDN link.

### 16.6 Alignment

Every route was measured in a real browser at four widths (1440, 1024, 768 and
390 px) and checked for horizontal overflow, content escaping its container,
ragged card tops, mismatched control heights, clipped text, tables wider than
their wrapper, collisions with the sidebar, and **buttons that do not line up
with the input beside them**. All clean.

Two defects that this last check caught, and the rules that prevent them:

* **`align-items:flex-end` does not align a button to an input** when the
  input's column also holds a hint line below it — the column is taller than
  the input, so the button sank 26 px on the import page. Helper text now sits
  on its own full-width line (`.up-hint` is a direct child of `.up-row`).
* **Buttons are 40 px, inputs are 47 px.** Any button sharing a line with a
  form control needs the **`.btn-field`** class, which pins it to `--ctl-h`.
  This fixed the students filter *Reset*, the promotion *Preview* and the
  settings *Add year* buttons.

Two behaviours look like faults but are deliberate, and should be left alone:

* the dashboard month strip scrolls sideways on purpose (`.month-strip`);
* row-action buttons drop their text labels below 1180 px and keep only icons,
  using the standard visually-hidden idiom so screen readers still read them.

### Upgrading from v2.2

1. Pull the new files.
2. Run **`migrate.php`** once — it adds `students.photo_path` and is idempotent.
3. Hard-refresh once (or unregister the service worker) so the 2.2 shell cache
   is dropped.
4. Existing students keep their codes and roll numbers; only newly created
   records use the generator.
5. **The ID format changed** to `18-0042` (§16.3). Rows already in your database
   keep whatever code they have — nothing breaks, because `student_code` is just
   a unique string — but new students will be numbered in the new style, so you
   will have a mix. If you would rather not, renumber once while the system is
   quiet:

   ```sql
   -- back up first, then, per academic year:
   SET @n := 0;
   UPDATE students
      SET student_code = CONCAT('18-', LPAD(@n := @n + 1, 4, '0'))
    WHERE deleted_at IS NULL
    ORDER BY id;
   ```

   Reprint any ID cards afterwards. The bundled demo data (`seed.php`) is
   already numbered in the new format.

---

## 17 · v2.4 change log — corrections, recovery, history, charts

The largest round since the original build: sixteen items covering attendance
corrections and everything downstream of them, a new Student History page, a
Master recovery path, and a dashboard redesign.

**Upgrading:** pull the files, run `php migrate.php` (migrations 8 and 9 — three
settings rows and the `recovery_keys` table), and hard-refresh once. The service
worker is at `sr-v2.4.0`, so the old shell cache is dropped automatically.

| # | Change | Where |
|---|--------|-------|
| 1 | **Soft-deleted students leave the dashboard.** Every aggregate in `attendance.php action=stats` joins `students s ON … AND s.deleted_at IS NULL`, so removing a student drops their headcount *and* their attendance out of every figure while the row and its history stay in the database. Restoring brings both back. | §7 |
| 2 | **Excused is a first-class status.** Teachers mark it directly, and an Absent record becomes Excused when the excuse arrives (with a reason). The enum was already `present,absent,excused`; the work was in the marking UI and in making excused count separately everywhere it is summarised. | §7 |
| 3 | **Attendance Correction page** (`#/corrections`; the old `#/correction` still works and redirects). Look a student up by date, see the record that exists today, and change it in any direction among Present/Absent/Excused. It **updates the row by primary key** — `UNIQUE(student_id, session_date)` plus an update-in-place means a correction can never duplicate a record. Future dates are refused. Gated on the new `attendance.correct` capability: teachers get it for their own grades, admin and master unrestricted. | §6, §7 |
| 4 | **Corrections propagate everywhere.** Nothing caches a derived attendance figure — percentages, absence and excused counts, streaks, alerts, dashboard stats, reports and exports are all computed from the `attendance` rows at read time, so a correction is reflected the moment it is saved. Verified by correcting a record and watching absent/excused move by exactly one in both directions. | §7 |
| 5 | **Alerts track live streaks.** `sr_absence_streaks()` recomputes consecutive absences from current records on every read, so an unread alert grows as the streak grows, and a correction adjusts it **both ways** — breaking a 15-day streak drops the alert entirely, restoring the record brings it back at 15. | §7 |
| 6 | **ID cards: print one, all, or a chosen few.** The row button prints that student, the top button prints everyone, and a new select-students mode prints exactly the ticked cards. `export.php type=idcards` honours `ids[]` and is scope-checked, so a teacher cannot print a card for a student outside their grades. | §8 |
| 7 | **Promotion never changes the academic year.** `promotion.php` does not touch `is_current`. The year moves on either automatically on the configured start date (`year_auto_switch` + `year_start_date`, forward-only, skipping closed years) or manually via **Make current** on the year row. | §7 |
| 8 | **Grade records fetch one student.** `students.php action=get` returns that student with their own enrolments, promotions and attendance and nothing else. | §7 |
| 9 | **Student History is its own page** (`#/history`), a sub-item under Students. Search or filter by class to pick a student, or deep-link with `#/history/<id>`. Shows the identity banner, the enrolment trail, promotion outcomes, per-month attendance bars and the day-by-day log. The old panel inside Edit Student is gone, replaced by a single link — reading a record no longer means opening an editor. | §7 |
| 10 | **PDF prints have no footer.** The `St. Rufael School v2.0` strip is gone from every print view. | §8 |
| 11 | **English brand name toggle.** `Settings → Identity → Use the English name` switches branding system-wide: header, sign-in page, printed output. `sr_brand_use_english()` / `sr_brand_name()` are the single authority in PHP, mirrored in JS from `DATA.settings`. Off by default. ID cards deliberately print **both** names — they are bilingual by design. | §7 |
| 12 | **Fuzzy search.** `sr_search_clause()` splits the query into up to 8 tokens, ANDs them, and ORs each across the searchable fields, matching on prefix, word-prefix and substring. `a s` finds `Aron Shinles`; `አ ሽ` finds `አሮን ሽመልስ`. Escapes `\ % _`, so a literal `%` searches for a percent sign. | §7 |
| 13 | **Dashboard redesigned.** The scrolling strip of thirteen month cards is replaced by three inline-SVG charts: a donut of the present/absent/excused split with the rate in the middle, a line/area chart of the rate across the Ethiopian months, and a Saturday-vs-Sunday comparison. Hand-built SVG — there is no Composer and Bootstrap is local-only, so a charting library was never an option, and four shapes do not justify one. Pure geometry, no scripting, `aria-label`s and `<title>` tooltips throughout, and it honours `prefers-reduced-motion`. | §7 |
| 14 | **Language and numerals removed from user create and edit.** They are personal preferences, so each user sets them in their own Settings; an admin creating an account no longer guesses on their behalf. | §6 |
| 15 | **Master account recovery** — see §17.1. | §10 |
| 16 | **Master-only options are hidden from everyone else** — see §17.2. | §10 |

### 17.1 Master recovery keys

The Master is the one account no one else can reset, so forgetting the password
used to mean editing the `users` table by hand. That is exactly the raw bypass
worth avoiding, so recovery runs on a printed key instead.

* `install.php` mints the first key and shows it **once** on the completion
  screen. A signed-in Master creates and revokes replacements under
  **Settings → Recovery keys**, capped at five unused at a time.
* Only a bcrypt **hash** is stored (`recovery_keys.key_hash`), so reading the
  table gets an attacker nothing.
* The sign-in page has a real **Forgot your password?** branch. It only offers
  the key form when the installation actually holds an unused key — a fresh
  install that never printed one says "ask your admin" instead of leading the
  Master down a dead end.
* Spending a key **does not sign anyone in.** It issues a temporary password and
  forces `new-password.php` on the next sign-in, so a key is worth exactly one
  password reset and nothing more.

Security properties, all verified against the running system:

| Property | How |
|---|---|
| Single use | `used_at` is stamped by an `UPDATE … WHERE id = ? AND used_at IS NULL`; the row is only accepted when that statement affects exactly one row, so two simultaneous requests cannot both spend one key. |
| Master only | The lookup filters `role = 'master' AND is_active = 1 AND deleted_at IS NULL`. A valid key presented with a non-Master username fails. |
| No username disclosure | A wrong username and a wrong key return the identical `bad_key` error. |
| Not brute-forceable | The same throttle as login (`LOGIN_MAX_ATTEMPTS` per username+IP over `LOGIN_LOCKOUT_SECONDS`), checked **before** the attempt, and every try is written to `login_attempts`. |
| Readable over the phone | Crockford base32 (no I, L, O, U) in five groups of four; input is uppercased, stripped of punctuation, and O→0 / I,L→1 are folded, so a correctly-typed key always normalises to itself. |
| Auditable | `auth.recovered`, `auth.recovery_failed` and `auth.recovery_locked` are written to `audit_logs`, along with `user.recovery_key_created` / `…_revoked`. |

### 17.2 Master-only visibility

Capabilities already kept `audit.*`, `backup.manage`, `features.manage` and
`permissions.manage` with the Master, and the UI gates each of those on `can()`.
The gap was the user list itself: an admin could enumerate the Master account,
and because `delete` only refused the *last* Master, could soft-delete one
whenever a second existed. `restore` was a blind `UPDATE … WHERE id = ?`, so a
deleted Master could be revived by a non-Master too.

`lib/helpers.php` now provides `sr_is_master()` and `sr_assert_user_target()` as
the single answer to "may the caller act on this row", and every `users.php`
branch — `list`, `get`, `update`, `delete`, `restore`, `reset_password` — goes
through it. Master rows are filtered out **in SQL** for non-Masters, and
targeted actions answer `404 not_found` rather than `403 forbidden`, because a
403 would confirm the account exists.

The recovery-key actions check `sr_is_master()` directly rather than a
capability, deliberately: no permission edit should ever be able to hand account
recovery to an admin.

### 17.3 New in this round

**Migrations.** 8 — settings `year_auto_switch` (bool, `1`), `year_start_date`
(string, `01-01`, validated `^(0[1-9]|1[0-3])-(0[1-9]|[12]\d|30)$`) and
`brand_use_english` (bool, `0`). 9 — the `recovery_keys` table. Both are
forward-only and idempotent; `php migrate.php` reports "Already up to date" on a
second run.

**Helpers** (`lib/helpers.php`): `sr_brand_use_english()`, `sr_brand_name()`,
`sr_search_clause()`, `sr_year_autoswitch()`, `sr_is_master()`,
`sr_assert_user_target()`, `sr_recovery_key_generate()`,
`sr_recovery_key_normalise()`, `sr_recovery_key_issue()`,
`sr_recovery_key_count()`, `sr_recovery_key_spend()`. `sr_temp_password()`
moved here from `api/users.php` so the admin reset and Master recovery share one
implementation.

**API.** `attendance.php` gains `lookup` and `correct`. `auth.php` gains
`recovery_status` and `recover`. `users.php` gains `recovery_keys`,
`recovery_key_create` and `recovery_key_revoke`.

**CSS.** `.cor-*` (correction), `.hs-*` (history), `.ch-*` / `.dn-*` / `.tr-*` /
`.dc-*` (dashboard charts), `.rk-*` (recovery keys). The dead `.month-strip` /
`.mo-*` block was removed with the strip it styled.
