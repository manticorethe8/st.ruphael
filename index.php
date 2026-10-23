<?php
/* ============================================================
   index.php — authenticated SPA shell.
   Same markup as the original index.html, plus the session
   context the front-end needs (user, capabilities, CSRF).
   ============================================================ */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/lib/helpers.php';

if (!sr_is_installed()) { header('Location: install.php'); exit; }

$user = current_user();
if (!$user) { header('Location: auth/login.php'); exit; }

/* A temporary password gets no further than the first-run screen. */
if ((int)($user['must_change_pw'] ?? 0) === 1) {
    header('Location: auth/new-password.php');
    exit;
}

$lang   = in_array(($_COOKIE['sr_lang'] ?? $user['lang'] ?? 'am'), ['am','en'], true)
            ? ($_COOKIE['sr_lang'] ?? $user['lang'] ?? 'am') : 'am';
$nameAm = setting('school_name_am', APP_NAME_AM);
$nameEn = setting('school_name_en', APP_NAME_EN);
/* Settings -> Identity decides which of the two names the interface shows.
   sr_brand_name() is the single authority; see lib/helpers.php. */
$brandAlwaysAm = sr_brand_always_amharic();
$brandName     = sr_brand_name($lang);
$year   = current_year_row();

/* Everything the SPA may read without a round-trip. Note: this is a
   convenience mirror for rendering only — every endpoint re-checks
   the session and capabilities server-side. */
$boot = [
    'user' => [
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
        'lang'      => $lang,
        'numerals'  => $user['numerals'] ?? 'mix',
        'must_change_pw' => (int)($user['must_change_pw'] ?? 0) === 1,
    ],
    'caps'  => (object)sr_effective_capabilities($user),
    'roles' => sr_roles(),
    'assignable_roles' => array_values(sr_assignable_roles($user['role'])),
    'lang'  => $lang,
    'csrf'  => csrf_token(),
    'year'  => $year ? ['id' => (int)$year['id'], 'year_ec' => (int)$year['year_ec'],
                        'label_am' => $year['label_am'] ?? null, 'label_en' => $year['label_en'] ?? null] : null,
    'school_am' => $nameAm,
    'school_en' => $nameEn,
    /* Mirrors the setting so applyStaticText() reaches the same verdict
       as PHP did and the name does not flip when the SPA takes over. */
    'brand_always_amharic' => $brandAlwaysAm,
    /* When false (the default) the Add/Edit Student form shows the
       Student ID and roll-number boxes greyed out, reading "auto".
       Flip SR_MANUAL_IDENTIFIERS in config/config.php to unlock them. */
    'manual_ids' => (bool)SR_MANUAL_IDENTIFIERS,
    'photo_max'  => (int)SR_PHOTO_MAX_BYTES,
    'settings' => [
        'absence_alert_threshold'  => (int)setting('absence_alert_threshold', 3),
        'absence_alert_message_am' => setting('absence_alert_message_am', ''),
        'absence_alert_message_en' => setting('absence_alert_message_en', ''),
        'school_name_am'           => $nameAm,
        'school_name_en'           => $nameEn,
        'brand_always_amharic'     => $brandAlwaysAm ? 1 : 0,
    ],
    'version' => APP_VERSION,
];
$initials = mb_substr(trim((string)($user['full_name'] ?: $user['username'])), 0, 1);
$roleAm   = ['master' => 'ዋና አስተዳዳሪ', 'admin' => 'አስተዳዳሪ', 'editor' => 'አርታዒ', 'teacher' => 'መምህር'][$user['role']] ?? $user['role'];
$roleEn   = ucfirst($user['role']);
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($brandName) ?> · Attendance Platform</title>
<meta name="description" content="St. Rufael Church School — Ethiopian Orthodox Tewahedo student attendance management platform.">
<meta name="theme-color" content="#0d2f57">
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/logo.png">

<link rel="stylesheet" href="vendor/bootstrap.min.css">
<link rel="stylesheet" href="css/fonts.min.css">
<link rel="stylesheet" id="themeCss" href="css/styles.min.css">
</head>
<body>
<a class="skip-link" href="#view" data-i18n-skip>Skip to main content</a>

<header class="top">
  <div class="top-in">
    <a class="brand" href="#/dashboard">
      <img class="brand-logo" src="assets/logo.png" alt="Seal of St. Rufael Church School">
      <span class="brand-text">
        <span class="brand-name" id="brandName"><?= e($brandName) ?></span>
        <span class="brand-sub" id="brandSub">የመገኘት አስተዳደር መድረክ</span>
      </span>
    </a>

    <div class="top-actions">
      
      <button class="icon-btn sync-chip" id="syncChip" type="button" hidden aria-live="polite">
        <span class="sync-dot" aria-hidden="true"></span>
        <span id="syncTxt">Offline</span>
      </button>

      
      <div class="dd-wrap">
        <button class="icon-btn lang-btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-label="Change language · ቋንቋ ቀይር">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 4 5.6 4 9s-1.5 6.4-4 9c-2.5-2.6-4-5.6-4-9s1.5-6.4 4-9z"/></svg>
          <span id="langCode"><?= $lang === 'am' ? 'አማ' : 'EN' ?></span>
        </button>
        <div class="dropdown lang-panel" id="langPanel" role="menu" hidden>
          <div class="dp-head" id="langHead"><strong>ቋንቋ · Language</strong></div>
          <button class="menu-item lang-opt" type="button" data-lang="am">አማርኛ <span class="lang-check" hidden>✓</span></button>
          <button class="menu-item lang-opt" type="button" data-lang="en">English <span class="lang-check" hidden>✓</span></button>
        </div>
      </div>

      
      <div class="dd-wrap">
        <button class="icon-btn bell" id="bellBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          <span class="bell-badge" id="bellBadge">0</span>
        </button>
        <div class="dropdown notif-panel" id="notifPanel" role="menu" hidden>
          <div class="dp-head">
            <strong id="notifTitle">ማሳወቂያዎች</strong>
            <button class="link-btn" id="markAllBellBtn" type="button">Mark all as read</button>
          </div>
          <ul class="notif-list" id="notifList"></ul>
          <div class="dp-foot">
            <button class="link-btn" id="viewAllAlertsBtn" type="button">View all attendance alerts</button>
          </div>
        </div>
      </div>

      
      <div class="dd-wrap">
        <button class="icon-btn user-btn" id="userBtn" aria-haspopup="true" aria-expanded="false" aria-label="Account">
          <span class="avatar" id="userInitial" aria-hidden="true"><?= e($initials) ?></span>
        </button>
        <div class="dropdown user-panel" id="userPanel" role="menu" hidden>
          <div class="dp-head user-head">
            <span class="avatar lg" aria-hidden="true"><?= e($initials) ?></span>
            <span class="uh-text">
              <strong id="userName"><?= e($user['full_name'] ?: $user['username']) ?></strong>
              <span class="uh-role" data-am="<?= e($roleAm) ?>" data-en="<?= e($roleEn) ?>"><?= e($lang === 'am' ? $roleAm : $roleEn) ?></span>
            </span>
          </div>
          <button class="menu-item" type="button" id="profileBtn" data-am="የግል መረጃ" data-en="My profile"><?= $lang === 'am' ? 'የግል መረጃ' : 'My profile' ?></button>
          <button class="menu-item" type="button" id="pwBtn" data-am="የይለፍ ቃል ቀይር" data-en="Change password"><?= $lang === 'am' ? 'የይለፍ ቃል ቀይር' : 'Change password' ?></button>
          <div class="dp-foot">
            <button class="menu-item danger" type="button" id="logoutBtn" data-am="ውጣ" data-en="Sign out"><?= $lang === 'am' ? 'ውጣ' : 'Sign out' ?></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<aside class="side" id="sidebar" aria-label="Main navigation">
  <p class="side-label" data-i18n-side>ምናሌ</p>
  <nav id="sideNav"></nav>
  <p class="side-foot" id="sideFoot">© ፳፻፲፰ ዓ.ም · <?= e($nameAm) ?></p>
</aside>

<main class="view" id="view" tabindex="-1"></main>

<footer class="app-foot">
  <span class="foot-rule" aria-hidden="true"></span>
  <p id="footNote">© ፳፻፲፰ ዓ.ም · <?= e($nameAm) ?></p>
</footer>

<nav class="tabbar" id="tabBar" aria-label="Main navigation"></nav>

<div class="sheet-scrim" id="moreScrim" hidden></div>
<div class="sheet" id="moreSheet" role="dialog" aria-modal="true" aria-labelledby="moreTitle" hidden>
  <div class="sheet-grip" aria-hidden="true"></div>
  <div class="sheet-head">
    <strong id="moreTitle">ሁሉም ገጾች</strong>
    <button class="icon-btn sheet-x" id="moreClose" type="button" aria-label="Close">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="sheet-body" id="moreBody"></div>
</div>

<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<div class="save-pill" id="savePill" role="status" aria-live="polite"><span class="sp-ic" aria-hidden="true">✓</span><span id="savePillTxt">ተቀምጧል</span></div>
<noscript><div class="noscript-msg">This platform requires JavaScript. እባክዎ JavaScript ያንቁ።</div></noscript>

<script>window.SR_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

</script><script src="js/features.min.js"></script>
<script src="js/api.min.js"></script>
<script src="js/app.min.js"></script>
<script src="js/admin.min.js"></script>
<script>
if ('serviceWorker' in navigator && window.FEATURES && window.FEATURES.pwa) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  });
}

</script></body>
</html>
