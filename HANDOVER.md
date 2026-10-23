# St. Rufael — AI Handover / Developer Context

> Purpose of this file: give a fresh developer or AI model everything it needs to
> continue work on this codebase without re-discovering (or accidentally
> reversing) the decisions below. **Read the "standing rules" section before
> touching anything.** Update this file whenever a new decision lands.

---

## 1. What the app is

A church **Sunday-school student + attendance system** (St. Rufael, Addis Ababa,
Ethiopia). Students, grades, Ethiopian-calendar attendance, ID cards, reports,
promotions, alerts, audit trail. Amharic-first, English secondary.

**Stack (intentionally uncomplicated):**
- PHP 8.4 + PDO MariaDB/MySQL, vanilla JS SPA (no framework, no bundler), one CSS file.
- No build step, no Composer, no npm — **except** the optional `tools/minify.sh`
  (Node + terser) that regenerates minified assets.
- Runs on a plain XAMPP/server host: any PHP box will do.

**Run locally (dev server):**
```sh
# DB: MariaDB/MySQL. In this sandbox:
sudo service mariadb start
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS st_rufael CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'sr'@'127.0.0.1' IDENTIFIED BY 'sr';
  GRANT ALL ON st_rufael.* TO 'sr'@'127.0.0.1';"

# Install (one-time): visit /install.php, then /seed.php for demo data.
SR_DB_USER=sr SR_DB_PASS=sr php -S 0.0.0.0:8080
```
DB constants live in `config/config.php` (env overrides `SR_DB_NAME/USER/PASS`,
defaults `st_rufael` / `root` / ``). `config/database.php` sets
`SET time_zone = '+03:00'` (Addis Ababa) per session — **do not remove**.

**Demo logins (seed.php):**
| user | password | role | notes |
|---|---|---|---|
| `master` | `Master@123` | master | full access, no grey cells |
| `admin1` | `AdminPass#18` | admin | same as master for attendance |
| `editor1` | `Editor#2018` | teacher | (was the demo 'editor' account; now grades 5 & 6, today-only grey) |
| `teacher1` | `Teacher#2018` | teacher | grades 3 & 4, today-only grey |
| `teacher2` | `Teacher#2018` | teacher | grades 5 & 6 |

---

## 2. Standing rules — do NOT regress these

These are explicit user decisions. "Maybe they changed their mind" is not a
reason to undo them; ask the user first.

1. **Register style = the ORIGINAL grouped design.** Year pills bar
   (`.year-bar`), year meta row (`.yr-meta`), **4 month-group tabs**:
   `[1,2,3] [4,5,6] [7,8,9] [10,11,12,13]` (13 = ጳጉሜ/Pagume) — one 3-month
   group shown at a time. Tools live in the `.reg-tools` card above the grid.
   The full-year single grid was built and then **reverted on request**.
2. **No date picker.** The register records **today** (`TODAY_ISO`). The tools
   card shows today's date statically and `markAllOn`/`copyLastWeek` target
   today. Do not reintroduce the Ethiopian calendar picker (`ETCAL/etCal*`
   code was removed).
3. **Grey logic is ROLE-based, not capability-based.** Seeded `teacher` has the
   `attendance.correct` capability, but that is irrelevant:
   - master/admin → **no greyed cells**, every day editable in the register;
   - everyone else (teacher): past days greyed/disabled, today only.
   - Client: `REG.lockPast = !(DATA.user.role==='master'||DATA.user.role==='admin')`
     (set in `renderClassAttendance`). Correction grid forces `lockPast=false`.
   - Server mirrors the same role check in `api/attendance.php` for
     `save`, `mark_all`, `copy_last_week` (non-today dates require
     master/admin).
4. **Attendance Correction page** (`#/corrections`, admin.js): starts at a live
   search (`lsqLive` on `#corQ`); picking a student opens **the same
   month-grouped grid** (month-separated style, same `grp-tabs`) for that one
   student. Nothing greyed. Every change (tick/untick/menu/excused/clear)
   goes through **one** styled WHY dialog (`srConfirm` with `input:''`) and
   calls `API.attendance.correct({student_id, date, status, reason, note:why})`.
   Cancel/error reverts the cell (`paintCell(td, td.dataset.st, null)`).
   Server updates **in place** (no duplicate rows possible).
5. **`none` (clear) is a valid correction status** (server deletes the row,
   audit records `-> none`). `correct` accepts `'none'` too.
6. **Excused reason box always starts EMPTY** — no pre-filled old reason, no
   placeholder text (user request). In correction mode the WHY text doubles as
   the excuse reason (`reason = why` for excused; server tolerates it).
7. **Correction + History pages reset on navigation.** Leaving
   `#/corrections` or `#/history` clears `A.cor`/`A.hist`
   (studentId/q) in `srBindAdminView` (admin.js) so both always start from
   search. `#corBack` (Change student) also resets.
8. **No "E.C." text anywhere** (English labels show a bare year, e.g. "2018";
   Amharic keeps ዓ.ም). Zero occurrences in the whole repo — `grep -rn "E\.C\."`
   must stay empty. Also no "Pagume is the 13th month" copy (the ጳጉሜ column
   shows just month name + day count).
9. **PRINTS:** ID cards show **no username/print time on the cards** — the
   "Printed by … – Addis date/time" line exists only once, in the `.ic-foot`
   footer. Reports: no printed date at the top (`.meta` row = summary only);
   footer `.sr-foot` only. `sr_printed_by()` = Addis time.
10. **Login quote** (Amharic only): `settings.skey = login_quote_am / login_quote_ref`,
    editable in Settings → Identity; empty `login_quote_am` hides the block;
    code fallback = ዮሐንስ 3፡16 sample so it renders before migration.
11. **Language:** SPA stores `localStorage.sr_lang_v1` and mirrors a cookie
    `sr_lang` (set by `setLang` and by login.php toggle). `auth/new-password.php`
    must read cookie → user lang (never a hardcoded Amharic copy).
12. **Zero native dialogs** (`window.confirm/prompt/alert` are banned; the
    codebase has none — the styled `window.srConfirm` replaces them).
13. **Live-search dropdowns** must not be clipped: `.set-card.lsq-card`
    has `overflow:visible; position:relative; z-index:40` — never re-add
    `overflow:hidden` to it. `lsqLive` popup has a click handler on items.
14. **Audit trail:** every correction logs `audit('attendance.corrected', …)`
    with `from → to`, `reason`, `note` (the WHY text).
15. **No git/zip artifacts in the repo; repo is the source of truth.**
    Keep folder structure; don't reset/reinstall a user DB; don't change
    credentials.
16. **Lists search WITHOUT reloading (v2.5).** Students, Users and the Audit
    trail fetch their data once per visit and filter it in place
    (`applyStuFilter/applyUsersFilter/applyAuditFilter`, `match-hide` rows +
    `#stuCount/#usCount/#auCount`) — never re-fetch per keystroke. Cache
    invalidation: set `A.stu.all/A.usr.all/A.aud.all = null` after any
    create/update/delete/purge. Students page tops out at 500 students
    (`per_page` cap); audit shows the latest 200 entries.
17. **The `editor` role is RETIRED (v2.5).** Not in `sr_roles()`, role lists,
    user forms, permission labels or seeds. Legacy `editor` rows are treated
    as `teacher` (defaults + rank + label + `role_permissions` guard);
    `editor1` was migrated to `teacher` with grades 5–6. `users.role` and
    `role_permissions.role` are now `VARCHAR(32)` so custom role names fit.
18. **Custom roles (v2.5).** Permissions page (admin + master only —
    `permissions.manage` is now granted to admin too) has **New role**;
    server `create_role` seeds teacher-level defaults and rejects built-in
    names; master-only powers (`permissions.manage`, `audit.*`,
    `backup.manage`, `features.manage`) are never grantable (`CAP_RESERVED`
    + server guard). New roles appear in Assignable-role dropdowns after a
    page reload (BOOT is rendered server-side).
19. **Master visibility (v2.5).** `master` appears in role/user dropdowns
    ONLY for the Master's own account (`isMaster()` in admin.js); non-master
    viewers never see it in filters, the By-role picker or By-user lists.
    Server double-checks: non-master `users.list` excludes master rows.
20. **Login page (v2.5).** No "Remember me", no password placeholder, no
    footer (copyright/date). Forgot password → panel titled "Recover your
    account" / "Your administrator resets your password for you" (text only;
    the Master recovery-key form is not exposed here — the API endpoint
    remains for console use).
21. **Mobile lists (v2.5).** Every list table stacks into padded cards
    (`tbl-stack` + `data-th`, applied by `labelTableCells`); student/audit/
    users/alert rows get extra padding and 36px-tall tap targets on
    `≤640px` media queries in css/styles.css. Do not revert to the old
    scroll-strip min-width list style.

---

## 3. File map

```
index.php              app shell: BOOT json, top bar/sidebar/tab-bar skeleton, loads .min assets
auth/login.php         login + quote + language toggle (cookie set here)
auth/new-password.php  forced first-time password change (language-aware)
auth/logout.php
install.php / seed.php setup + demo data (Amharic labels, demo users)
config/                config.php (constants), database.php (+03:00 TZ), auth.php, migrations.php, schema.php
lib/                   ethiopic.php (calendar math, et_label), helpers.php (auth/capability helpers)
api/                   auth.php, students.php, attendance.php, export.php, settings.php, users.php, permissions.php, grades.php, alerts.php, promotion.php, dashboard.php
js/app.js              CORE SPA: routing, nav, dashboard/classes/alerts/profile, the ATTENDANCE REGISTER + correction grid + cell menu + srConfirm + i18n + global L(am,en)
js/admin.js            ADMIN pages: students CRUD, settings, users, promotions, HISTORY page, CORRECTIONS page, lsqLive pickers (own L() inside its IIFE)
js/api.js              API client wrapper (API.*)
js/features.js         feature flags
js/*.min.js            MINIFIED BUILDS the browser actually loads (see §5)
css/styles.css         theme (commented source) ; css/styles.min.css = build
css/fonts.css(+.min)   Inter + Noto Sans Ethiopic @font-face
sw.js                  PWA service worker, precache list = the .min files, VERSION 'sr-v2.5.0'
tools/minify.sh        regenerates all .min files (node + terser)
HANDOVER.md            this file
```

**App version:** `APP_VERSION = 2.5.0` (config/config.php) — keep in sync with
`sw.js` `VERSION` string (bumping it invalidates cached assets).

---

## 4. Register / correction internals (app.js)

- `ATT_MONTH_GROUPS = [[1,2,3],[4,5,6],[7,8,9],[10,11,12,13]]`; `gTitle(g)`
  renders the group title; `ET_MONTHS`/`ET_MONTHS_EN` are the month names.
- `TODAY_ISO`, `ET_TODAY` — today's ISO / Ethiopian date parts.
- `REG = { gradeId, yearEc, gi, months, students, canEdit, grade, single, corrMode, todayOnly, lockPast }`.
- `loadRegister(gradeId, yearEc, gi)` fetches ONE group (3 months) and merges
  marks; sets `REG.gi`.
- `attTable(year, gi)` renders one group's grid (`.att-card` → `.att-head`
  with group title + year meta, `.table-responsive.att-scroll`, `thead`
  month colgroups + Sat/Sun day columns, `tfoot` present-counts).
  Teacher past cells get class `locked` + disabled checkbox.
- `regToolbar()` → `.reg-tools card`: “Recording for [today]” + ma-present/
  absent/excused + copy-last-week. Hidden when `!REG.canEdit || REG.corrMode ||
  REG.single`.
- `renderClassAttendance(clsId, sid)` — the register (sid = single-student
  grade-record view `#/grades/1/25`, from the profile page). Renders page-head,
  `.year-bar` (year pills + add-year), `.yr-pane` (yr-meta, grp-tabs,
  regToolbar, att-pane).
- `renderStudentCorrection(sid)` + `window.srRenderStudentCorrection` — the
  correction grid used by admin.js (same grouped style, `REG.corrMode=true`,
  no toolbar).
- `bindRegister()` — year pills, add-year, ma-btns, copyWeek, then
  `bindAttCells()`.
- `bindAttCells()` + `window.srBindAttCells` — shared by register and
  correction: reveals active group tab, centers today's column, binds
  group-tab clicks (`.grp-tab[data-gi][data-yr]` → `state.grpTab[yr]=gi;
  refresh(true)`), checkbox change (corrMode → WHY modal + `correct` + revert
  on cancel/error; register → `saveMark`), right-click/long-press cell menu
  (`cellMenu`), student name → `openProfile`.
- `cellMenu(td, ev)` — present/absent/excused/none; excused asks reason only
  OUTSIDE corrMode (input starts empty); inside corrMode one WHY modal only.
- `markAllOn(status)` — today only; `copyLastWeek()` — today, preview + confirm.
- `srConfirm(opts)` — the single styled modal (resolve string when
  `opts.input` given, else boolean; Esc/overlay/cancel = null/false).
- i18n: `t(key)`, plus **global `L(am, en)`** in app.js (admin.js has its own
  identical `const L` inside its IIFE — do not "merge" them).
- Exports admin.js relies on: `window.srRenderStudentCorrection`,
  `srBindAttCells`, `srCenterTodayCol`, `srBindAdminView` (admin.js),
  `srConfirm`, `srOpenProfile`, `srMountModal`, `srBindModal`, `srRefresh`,
  `srRoute`.

## 5. THE MINIFIED-BUILD CONTRACT (critical)

The browser loads **only the `.min.*` files** (`index.php`, auth pages,
`sw.js` precache). The commented `js/app.js`, `js/admin.js`, `js/api.js`,
`js/features.js`, `css/styles.css`, `css/fonts.css` are the **real source**.

- **After ANY edit to a source file, regenerate:** `sh tools/minify.sh`
  (needs `npm i -g terser`; sandbox copy at
  `/home/user/.srtest/node_modules/.bin/terser`, so in the sandbox:
  `PATH="/home/user/.srtest/node_modules/.bin:$PATH" sh tools/minify.sh`).
- minify.sh = terser `-c` (compress only, **no** variable mangling — safe) +
  a PHP comment-stripper for the two CSS files (comments only, nothing else).
- If the min files get stale, the app “works in source but not in browser”.
  This is the #1 cause of “why doesn't my change show up?”.
- HTML comments and inline `<style>`/`<script>` comments in PHP pages were
  already stripped. If you strip comments with regex again: **preserve the
  `</style>`/`</script>` closing tags** (a past attempt dropped them and
  blanked the pages; the fix was re-inserting closers before `</head>`/
  `</body>` — then verify with a browser).

---

## 6. Sandbox test setup (this workspace)

- `/home/user/.srtest` — playwright-core + a Chromium at `/usr/bin/chromium`.
- `check.js` — smoke suite (login → register grp-tabs/tools → single record →
  correction pick+WHY → history → teacher greys → EN locale → 0 dialogs/errors).
- Useful restarts (the sandbox does NOT persist packages or services across
  sessions — reinstall MariaDB/php/chromium and `npm i playwright-core terser`,
  recreate DB, re-run install.php + seed.php, then start the dev server; test
  DB is disposable, the USER database is not touched).
- Current date context: “today” in the app = Ethiopian date
  (2026-08-30 Gregorian ≈ 2018-12-24 ET at the time of writing).
- Grade 1 has students ቤዛዊት ሙላቱ (18-0025) & አሮን (18-0026) — use ሙላቱ for
  search tests; teacher1's classes are grades 3/4 (አበበ ከበደ is in grade 3).
- `php -l` every touched PHP file; `node --check` touched JS.
- MariaDB CLI shows UTC timestamps (13:18) while the app shows +03:00 (16:18)
  — that is intended, not a bug.

## 7. Getting the current picture (quick commands)

```sh
grep -rn "E\.C\." . --include=*.php --include=*.js --include=*.html --include=*.css   # must be empty
grep -rn "window.confirm\|window.alert\|window.prompt" js/                              # must be empty
sh tools/minify.sh                                                                      # after edits
```

## 8. Open items / recent history

- Latest completed round (v2.5.0): dashboard-style no-reload lists
  (students/users/audit), mobile list padding, correction picker label =
  "Choose the student" (no number), `editor` role removed everywhere
  (editor1 → teacher, grades 5–6), custom-role creation on the Roles &
  access page (admin + master), master hidden from non-master dropdowns,
  login cleanup (no Remember me / placeholder / footer; recovery panel is
  text-only), version bumped to 2.5.0. Schema change applied to the live
  DB: `users.role` + `role_permissions.role` → VARCHAR(32) (mirrored in
  config/schema.php + config/migrations.php).
- **Nothing is pending** — the last user instruction was to stop and let the
  user test. If they report issues, fix them on top of the current state.
