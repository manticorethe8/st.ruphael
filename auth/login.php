<?php
/* ============================================================
   auth/login.php — sign-in page (Google Stitch UI redesign).

   Standalone page: works with JavaScript on (fetches api/auth.php
   so errors stay inline) and posts nowhere by itself — the API is
   the single authentication path.
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

if (!sr_is_installed()) {
    header('Location: ../install.php');
    exit;
}
if (is_logged_in()) {
    header('Location: ../index.php');
    exit;
}
$schoolAm = setting('school_name_am', APP_NAME_AM);
$schoolEn = setting('school_name_en', APP_NAME_EN);
if (sr_brand_always_amharic() && $schoolAm !== '') {
    $schoolEn = $schoolAm;
}
$quoteAm  = trim((string)setting('login_quote_am',
    'እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድያ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ እንዳይጠፋ ይልቁንም የዘላለም ሕይወት እንዲኖረው።'));
$quoteRef = trim((string)setting('login_quote_ref', 'ዮሐንስ 3፡16'));
$csrf     = csrf_token();
$langInit = (($_COOKIE['sr_lang'] ?? 'am') === 'en') ? 'en' : 'am';
$next     = trim((string)($_GET['next'] ?? ''));
/* Only allow a simple in-app path, never a protocol, query or traversal. */
if ($next !== '' && !preg_match('/\A[a-zA-Z0-9_-]+\.php\z/D', $next)) {
    $next = '';
}
?>
<!doctype html>
<html lang="am">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#004C9D">
<link rel="manifest" href="../manifest.json">
<link rel="icon" type="image/png" href="../assets/logo.png">
<link rel="apple-touch-icon" href="../assets/logo.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= e($schoolAm) ?> · Sign in</title>
<link rel="stylesheet" href="../css/fonts.min.css?v=<?= e(APP_VERSION) ?>">
<style>
  :root{
    --primary:#0757a6;--primary-dark:#06437e;--navy:#0d2f57;--ink:#172b43;
    --muted:#68798c;--line:#e2e8ef;--surface:#f5f7fa;--gold:#fed255;
    --danger:#a62e25;--danger-bg:#fff1ef;
    --font:'Plus Jakarta Sans','Inter','Noto Sans Ethiopic',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  }
  *,*::before,*::after{box-sizing:border-box}
  html,body{width:100%;min-height:100%;margin:0}
  body{min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;background:#f1f5fa;color:var(--ink);font:400 14px/1.55 var(--font);-webkit-font-smoothing:antialiased}
  .auth-shell{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(270px,.82fr) minmax(400px,1.18fr);width:min(980px,100%);min-height:min(640px,calc(100dvh - 48px));overflow:hidden;border:1px solid #e2e8ef;border-radius:20px;background:#fff;box-shadow:0 14px 44px rgba(13,47,87,.09)}

  .welcome-panel{position:relative;isolation:isolate;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;gap:28px;padding:40px 36px;background:var(--navy);color:#fff}
  .hero-brand{position:relative;z-index:1;display:flex;flex-direction:column;align-items:flex-start;width:100%;margin:0;text-align:left}
  .seal-wrap{position:relative;display:inline-flex;margin-bottom:22px}
  .seal-glow{position:absolute;inset:-7px;border-radius:50%;background:rgba(254,210,85,.18);filter:blur(8px);pointer-events:none}
  .seal{position:relative;display:grid;place-items:center;width:58px;height:58px;padding:3px;border:1px solid rgba(254,210,85,.65);border-radius:50%;background:#fff}
  .seal img{display:block;width:100%;height:100%;border-radius:50%;object-fit:cover}
  .seal .fallback{display:none;width:100%;height:100%;place-items:center;border-radius:50%;background:var(--primary);color:white;font-size:18px;font-weight:700}
  .seal.noimg img{display:none}.seal.noimg .fallback{display:grid}
  .hero-brand h1{max-width:360px;margin:0 0 8px;color:#fff;font-size:clamp(22px,2.5vw,30px);font-weight:700;letter-spacing:-.035em;line-height:1.2}
  .hero-brand p{max-width:340px;margin:0;color:rgba(255,255,255,.72);font-size:12.5px;line-height:1.5}
  .verse-card{position:relative;z-index:1;display:flex;align-items:flex-start;gap:10px;width:100%;padding:13px 14px;border:1px solid rgba(255,255,255,.13);border-left:2px solid var(--gold);border-radius:4px 10px 10px 4px;background:rgba(255,255,255,.055)}
  .verse-icon{display:grid;place-items:center;flex:0 0 26px;width:26px;height:26px;border-radius:7px;background:rgba(254,210,85,.13);color:var(--gold)}
  .verse-body{flex:1;min-width:0}.verse-body p{margin:0;color:rgba(255,255,255,.9);font-size:11px;font-style:italic;line-height:1.55}
  .verse-body cite{display:block;margin-top:4px;color:var(--gold);font-size:10px;font-style:normal;font-weight:700}
  .verse-watermark{position:absolute;right:-4px;bottom:-8px;color:rgba(255,255,255,.05);pointer-events:none}

  .login-panel{display:flex;flex-direction:column;min-width:0;padding:24px 34px 18px;background:#fff}
  .top-meta{display:flex;align-items:center;justify-content:flex-end;gap:10px}
  .version-pill{padding:4px 8px;border:1px solid var(--line);border-radius:999px;color:var(--muted);font-size:9px;font-weight:700}
  .lang{display:inline-flex;align-items:center;gap:2px;padding:3px;border:1px solid var(--line);border-radius:999px;background:#f5f7fa}
  .lang button{min-height:32px;padding:6px 10px;border:0;border-radius:999px;background:transparent;color:var(--muted);font:600 10.5px/1.2 var(--font);cursor:pointer;transition:background .15s,color .15s}
  .lang button.on{background:#fff;color:var(--primary);box-shadow:0 1px 4px rgba(13,47,87,.12)}
  .lang button:hover{color:var(--ink)}

  .card{align-self:center;width:min(100%,390px);margin:auto;padding:22px 0;border:0;border-radius:0;background:transparent;box-shadow:none}
  .card h2{margin:0 0 4px;color:var(--ink);font-size:25px;font-weight:700;letter-spacing:-.035em;line-height:1.25}
  .card .sub{margin:0 0 24px;color:var(--muted);font-size:12.5px}
  .field{margin-bottom:15px}
  label{display:block;margin-bottom:6px;color:#34465b;font-size:11.5px;font-weight:700}
  .inp-wrap{position:relative;display:flex;align-items:center;border:1px solid #dfe6ed;border-radius:10px;background:#f8fafc;transition:border-color .15s,background .15s,box-shadow .15s}
  .inp-wrap:focus-within{border-color:#4b89c2;background:#fff;box-shadow:0 0 0 3px rgba(7,87,166,.1)}
  .inp-ico{display:grid;place-items:center;padding:0 2px 0 12px;color:#8291a2;pointer-events:none}
  input[type=text],input[type=password]{width:100%;height:46px;padding:0 12px 0 9px;border:0;border-radius:10px;outline:0;background:transparent;color:var(--ink);font:500 13px var(--font)}
  input::placeholder{color:#98a5b2;font-weight:400}
  .pw .eye{display:grid;place-items:center;flex:0 0 38px;width:38px;height:38px;margin-right:3px;border:0;border-radius:8px;background:transparent;color:#8291a2;cursor:pointer}
  .pw .eye:hover{background:#edf2f7;color:var(--ink)}
  .caps-hint{margin:-8px 0 12px;color:#755b00;font-size:12px;font-weight:600}
  .row-between{display:flex;align-items:center;justify-content:flex-end;margin:0 0 16px}
  .link{padding:4px 0;border:0;background:transparent;color:var(--primary);font:600 11px var(--font);cursor:pointer}
  .link:hover{color:var(--primary-dark);text-decoration:underline}
  .btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:47px;border:0;border-radius:9px;background:var(--primary);color:#fff;font:700 13px var(--font);cursor:pointer;transition:background .15s,transform .12s}
  .btn:hover{background:var(--primary-dark)}.btn:active{transform:translateY(1px)}.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
  .btn.alt{min-height:44px;margin-top:8px;background:#edf2f7;color:var(--ink)}.btn.alt:hover{background:#e4ebf2}
  .msg{display:none;align-items:flex-start;gap:8px;margin:0 0 14px;padding:10px 12px;border:1px solid #ffd2cc;border-radius:9px;background:var(--danger-bg);color:var(--danger);font-size:11.5px;line-height:1.5}
  .msg.show{display:flex}.msg svg{flex:0 0 auto;margin-top:2px}
  .recov{display:none}.recov.show{display:block}
  .recov-note{display:flex;align-items:flex-start;gap:9px;margin:0 0 12px;padding:12px;border:1px solid var(--line);border-radius:9px;background:#f7f9fb;color:#485a6e;font-size:12px;line-height:1.55}
  .recov-note svg{flex:0 0 auto;margin-top:2px;color:var(--primary)}
  .foot{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:auto;padding:12px 0 0;color:#8290a0;font-size:10px;text-align:center}
  .foot svg{flex:0 0 auto;color:var(--primary);opacity:.75}

  @media(max-width:760px){
    body{align-items:stretch;padding:0;background:#fff}
    .auth-shell{display:flex;flex-direction:column;gap:0;width:100%;max-width:560px;min-height:100vh;min-height:100dvh;margin:0 auto;border:0;border-radius:0;overflow:visible;box-shadow:none}
    .welcome-panel{flex:none;min-height:202px;gap:14px;padding:22px 22px 16px;border-radius:0 0 18px 18px}
    .hero-brand{display:grid;grid-template-columns:48px minmax(0,1fr);grid-template-rows:auto auto;align-items:center;column-gap:12px}
    .seal-wrap{grid-column:1;grid-row:1/3;margin:0}.seal{width:44px;height:44px;padding:2px}
    .hero-brand h1{grid-column:2;grid-row:1;margin:0 0 3px;font-size:20px}
    .hero-brand p{grid-column:2;grid-row:2;font-size:10.5px}
    .verse-card{display:none;padding:8px 10px;gap:8px}.verse-icon{width:23px;height:23px;flex-basis:23px;border-radius:6px}
    .verse-body p{font-size:9.5px;line-height:1.45}.verse-body cite{margin-top:2px;font-size:9px}
    .login-panel{flex:1;min-height:0;padding:15px 22px max(16px,env(safe-area-inset-bottom))}
    .top-meta{justify-content:space-between}
    .card{width:min(100%,390px);padding:20px 0}
    .card h2{font-size:23px}.card .sub{margin-bottom:20px;font-size:12px}
    .field{margin-bottom:13px}.row-between{margin-bottom:14px}
    input[type=text],input[type=password]{height:46px}
    .btn{min-height:47px}.foot{padding-top:10px}
  }
  @media(max-width:360px){
    .welcome-panel{padding:16px}
    .hero-brand{grid-template-columns:42px minmax(0,1fr);column-gap:9px}
    .seal{width:40px;height:40px}.hero-brand h1{font-size:18px}
    .login-panel{padding-inline:18px}
    .card{padding-top:15px}.card h2{font-size:21px}
    .version-pill{font-size:8.5px}.lang button{min-height:30px;padding-inline:8px;font-size:9.5px}
  }
  @media(max-width:760px) and (max-height:620px){
    .welcome-panel{gap:9px;padding-block:14px 11px}
    .seal{width:38px;height:38px}.hero-brand h1{font-size:18px}
    .verse-card{padding:7px 9px}.verse-body p{font-size:9px}
    .login-panel{padding-top:10px}.card{padding:10px 0}
    .card h2{font-size:20px}.card .sub{margin-bottom:12px}
    .field{margin-bottom:8px}label{margin-bottom:4px;font-size:10.5px}
    input[type=text],input[type=password]{height:46px}.row-between{margin-bottom:8px}.btn{min-height:46px}
  }
  /* Larger labels and help text remain readable in both scripts. */
  .card .sub,.recov-note{font-size:14px}
  label{font-size:13px}
  input[type=text],input[type=password]{font-size:16px}
  .link{font-size:13px;min-height:44px}
  .msg{font-size:13px}
  .lang button{min-height:40px;font-size:12px}
  :focus-visible{outline:3px solid #d09919;outline-offset:3px}
  @media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
</style>
</head>
<body>

<main class="auth-shell">
  <!-- Image-led welcome pane paired with the focused sign-in panel -->
  <section class="welcome-panel" aria-label="St. Rufael Church School">
  <!-- Sacred Emblem Seal & School Heading -->
  <div class="hero-brand">
    <div class="seal-wrap">
      <div class="seal-glow" aria-hidden="true"></div>
      <div class="seal" id="sealMark">
        <img src="../assets/logo.png" alt="" onerror="this.parentNode.classList.add('noimg')">
        <span class="fallback" aria-hidden="true">ቅሩ</span>
      </div>
    </div>
    <h1 id="schoolTitle"><?= e($schoolAm) ?></h1>
    <p data-t="tagline">የተማሪዎች እና የክትትል ሥርዓት</p>
  </div>

  <?php if ($quoteAm !== ''): ?>
  <!-- Warm Liturgical Gold Scripture Banner -->
  <div class="verse-card">
    <div class="verse-icon" aria-hidden="true">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 4h7a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H2z"/>
        <path d="M22 4h-7a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H22z"/>
      </svg>
    </div>
    <div class="verse-body">
      <p id="verseText">“<?= e($quoteAm) ?>”</p>
      <?php if ($quoteRef !== ''): ?>
      <cite id="verseCite">— <?= e($quoteRef) ?></cite>
      <?php endif; ?>
    </div>
    <svg class="verse-watermark" width="68" height="68" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
      <path d="M12 3v18M6 8h12"/>
    </svg>
  </div>
  <?php endif; ?>
  </section><!-- /.welcome-panel -->

  <section class="login-panel" aria-label="Sign in">
    <!-- Application version and existing language switcher -->
    <div class="top-meta">
      <div class="version-pill" aria-label="Application version">v<?= e(APP_VERSION) ?></div>
      <div class="lang" role="group" aria-label="Language">
        <button type="button" data-lang="am">አማርኛ</button>
        <button type="button" data-lang="en">English</button>
      </div>
    </div>

  <!-- Sign-In Card -->
  <section class="card">
    <div id="signView">
      <h2 data-t="welcome">እንኳን ደህና መጡ</h2>
      <p class="sub" data-t="sub">ለመቀጠል በመለያዎ ይግቡ።</p>

      <div class="msg err" id="errBox" role="alert" aria-live="assertive">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="errText"></span>
      </div>

      <form id="loginForm" autocomplete="on" novalidate>
        <div class="field">
          <label for="u" data-t="username">የተጠቃሚ ስም</label>
          <div class="inp-wrap">
            <span class="inp-ico" aria-hidden="true">
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg>
            </span>
            <input id="u" name="username" type="text" autocomplete="username" maxlength="64" autocapitalize="none" spellcheck="false" required autofocus>
          </div>
        </div>
        <div class="field">
          <label for="p" data-t="password">የይለፍ ቃል</label>
          <div class="inp-wrap pw">
            <span class="inp-ico" aria-hidden="true">
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </span>
            <input id="p" name="password" type="password" autocomplete="current-password" placeholder="••••••••" required>
            <button class="eye" type="button" id="eyeBtn" aria-label="Show password" aria-pressed="false">
              <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>
        <p id="capsHint" class="caps-hint" hidden></p>
        <div class="row-between">
          <button type="button" class="link" id="forgotBtn" data-t="forgot">የይለፍ ቃል ረሱ?</button>
        </div>
        <button class="btn" id="submitBtn" type="submit">
          <span id="submitLbl" data-t="signin">ግባ</span>
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>
          </svg>
        </button>
      </form>
    </div>

    <div class="recov" id="recovPanel">
      <h2 data-t="recov_title">መለያዎን መልሶ ማግኘት</h2>
      <p class="sub" data-t="recov_sub">የይለፍ ቃልዎን ማስተካከል የሚችለው አስተዳዳሪዎ ነው።</p>
      <div class="recov-note">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span data-t="recov_body">እባክዎ የሰንበት ትምህርት ቤቱን አስተዳዳሪ ወይም ዋና አስተዳዳሪ ያነጋግሩ፤ አዲስ ጊዜያዊ የይለፍ ቃል ይሰጡዎታል፣ በመጀመሪያ መግቢያዎም የራስዎን ይመርጣሉ።</span>
      </div>
      <button type="button" class="btn alt" id="backBtn" data-t="back">ተመለስ</button>
    </div>
  </section>

  <footer class="foot">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 2v5M9.5 4.5h5M5 21V11l7-4 7 4v10M9 21v-5h6v5"/>
    </svg>
    <span id="footText"><?= e($schoolAm) ?></span>
  </footer>
  </section><!-- /.login-panel -->
</main>

<script>
(() => {
  const BOOT = {
    school_am: <?= json_encode($schoolAm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    school_en: <?= json_encode($schoolEn, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrf:      <?= json_encode($csrf) ?>,
    lang:      <?= json_encode($langInit) ?>,
    next:      <?= json_encode($next) ?>
  };

  const T = {
    am: {
      tagline:'የተማሪዎች እና የክትትል ሥርዓት',
      welcome:'እንኳን ደህና መጡ', sub:'ለመቀጠል በመለያዎ ይግቡ።',
      username:'የተጠቃሚ ስም', password:'የይለፍ ቃል', uPh:'የተጠቃሚ ስምዎን ያስገቡ',
      signin:'ግባ', signing:'በመግባት ላይ…', forgot:'የይለፍ ቃል ረሱ?',
      recov_title:'መለያዎን መልሶ ማግኘት',
      recov_sub:'የይለፍ ቃልዎን ማስተካከል የሚችለው አስተዳዳሪዎ ነው።',
      recov_body:'እባክዎ የሰንበት ትምህርት ቤቱን አስተዳዳሪ ወይም ዋና አስተዳዳሪ ያነጋግሩ፤ አዲስ ጊዜያዊ የይለፍ ቃል ይሰጡዎታል፣ በመጀመሪያ መግቢያዎም የራስዎን ይመርጣሉ።',
      back:'ተመለስ',
      empty:'እባክዎ የተጠቃሚ ስምና የይለፍ ቃል ያስገቡ።',
      net:'ከሰርቨሩ ጋር መገናኘት አልተቻለም። ኢንተርኔትዎን ያረጋግጡ።',
      caps:'Caps Lock በርቷል',
      verse:'“እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድያ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ የዘላለም ሕይወት እንዲኖረው እንጂ እንዳይጠፋ።”',
      cite:'— ዮሐንስ 3፡16',
      months:['መስከረም','ጥቅምት','ኅዳር','ታኅሣሥ','ጥር','የካቲት','መጋቢት','ሚያዝያ','ግንቦት','ሰኔ','ሐምሌ','ነሐሴ','ጳጉሜ'],
      days:['እሑድ','ሰኞ','ማክሰኞ','ረቡዕ','ሐሙስ','ዓርብ','ቅዳሜ'], ec:'ዓ.ም'
    },
    en: {
      tagline:'Student & Attendance Management System',
      welcome:'Welcome back', sub:'Sign in with your account to continue.',
      username:'Username', password:'Password', uPh:'Enter your username',
      signin:'Sign in', signing:'Signing in…', forgot:'Forgot your password?',
      recov_title:'Account recovery',
      recov_sub:'Passwords can only be reset by an administrator.',
      recov_body:'Ask a school administrator or the master account holder to reset your password. You will receive a temporary password and be asked to choose your own on next sign-in.',
      back:'Back to sign in',
      empty:'Please enter both your username and password.',
      net:'Could not reach the server. Check your connection and try again.',
      caps:'Caps Lock is on',
      verse:'“For God so loved the world that he gave his one and only Son, that whoever believes in him shall not perish but have eternal life.”',
      cite:'— John 3:16',
      months:['Meskerem','Tikimt','Hidar','Tahsas','Tir','Yekatit','Megabit','Miazia','Ginbot','Sene','Hamle','Nehase','Pagume'],
      days:['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], ec:'E.C.'
    }
  };

  let csrf = BOOT.csrf;
  let lang = localStorage.getItem('sr_lang_v2') || BOOT.lang || 'am';
  if (!T[lang]) lang = 'am';

  const $ = s => document.querySelector(s);
  function applyLang() {
    const t = T[lang];
    document.documentElement.lang = lang;
    document.cookie = 'sr_lang=' + lang + ';path=/;max-age=31536000;samesite=Lax';
    document.querySelectorAll('[data-t]').forEach(el => {
      const k = el.getAttribute('data-t');
      if (t[k] !== undefined) el.textContent = t[k];
    });
    document.querySelectorAll('[data-lang]').forEach(b => b.classList.toggle('on', b.dataset.lang === lang));
    const nm = lang === 'am' ? BOOT.school_am : BOOT.school_en;
    $('#schoolTitle').textContent = nm;
    $('#footText').textContent    = nm;
    $('#u').placeholder           = t.uPh;
    $('#capsHint').textContent = t.caps;
    document.title = nm + ' · ' + t.signin;
  }
  document.querySelectorAll('[data-lang]').forEach(b => b.addEventListener('click', () => {
    lang = b.dataset.lang; localStorage.setItem('sr_lang_v2', lang); applyLang();
  }));

  /* password visibility */
  const pw = $('#p');
  pw.addEventListener('keyup', e => { $('#capsHint').hidden = !e.getModifierState('CapsLock'); });
  pw.addEventListener('blur', () => { $('#capsHint').hidden = true; });
  $('#eyeBtn').addEventListener('click', () => {
    pw.type = pw.type === 'password' ? 'text' : 'password';
    $('#eyeBtn').setAttribute('aria-pressed', String(pw.type === 'text'));
    $('#eyeBtn').setAttribute('aria-label', pw.type === 'text' ? 'Hide password' : 'Show password');
    pw.focus();
  });

  /* forgot-password panel */
  $('#forgotBtn').addEventListener('click', () => {
    $('#signView').style.display = 'none';
    $('#recovPanel').classList.add('show');
    $('#backBtn').focus();
  });
  $('#backBtn').addEventListener('click', () => {
    $('#recovPanel').classList.remove('show');
    $('#signView').style.display = '';
    $('#forgotBtn').focus();
  });

  const ERR_AM = {
    bad_credentials : 'የተጠቃሚ ስም ወይም የይለፍ ቃል ትክክል አይደለም።',
    locked          : 'በተደጋጋሚ በተሳሳተ ሙከራ ምክንያት መለያው ለጊዜው ተቆልፏል። እባክዎ ትንሽ ቆይተው ይሞክሩ።',
    locked_out      : 'ብዙ ሙከራዎች ተደርገዋል። ከ15 ደቂቃ በኋላ ይሞክሩ።',
    disabled        : 'ይህ መለያ ተሰናክሏል። አስተዳዳሪዎን ያነጋግሩ።',
    inactive        : 'ይህ መለያ ተሰናክሏል። አስተዳዳሪዎን ያነጋግሩ።',
    validation      : 'እባክዎ የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ።',
    csrf            : 'ክፍለ ጊዜው አልፎበታል። እባክዎ እንደገና ይሞክሩ።',
    server          : 'በአገልጋዩ ላይ ችግር ተፈጥሯል። እባክዎ እንደገና ይሞክሩ።'
  };

  function showErr(m) {
    const b = $('#errBox'), txt = $('#errText');
    if (m) { txt.textContent = m; b.classList.add('show'); }
    else   { txt.textContent = ''; b.classList.remove('show'); }
  }

  $('#loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    showErr('');
    const username = $('#u').value.trim();
    const password = $('#p').value;
    if (!username || !password) { showErr(T[lang].empty); (!username ? $('#u') : $('#p')).focus(); return; }

    const btn = $('#submitBtn'), lbl = $('#submitLbl');
    btn.disabled = true; btn.setAttribute('aria-busy', 'true'); lbl.textContent = T[lang].signing;
    try {
      const r = await fetch('../api/auth.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf,'X-Requested-With':'fetch'},
        body: JSON.stringify({ action:'login', username, password, lang })
      });
      const j = await r.json().catch(() => null);
      if (!r.ok || !j || !j.ok) {
        const err = (j && j.error) || {};
        if (err.type === 'csrf') {
          fetch('../api/auth.php?action=me', { credentials:'same-origin' })
            .then(rr => rr.json())
            .then(jj => { if (jj && jj.ok && jj.data && jj.data.csrf_token) csrf = jj.data.csrf_token; })
            .catch(() => {});
        }
        const msg = (lang === 'am' && err.type && ERR_AM[err.type]) ? ERR_AM[err.type] : (err.message || 'Sign-in failed.');
        showErr(msg);
        $('#p').select();
      } else {
        location.href = (j.data && j.data.redirect) || ('../' + (BOOT.next || 'index.php'));
        return;
      }
    } catch (_) {
      showErr(T[lang].net);
    }
    btn.disabled = false; btn.removeAttribute('aria-busy'); lbl.textContent = T[lang].signin;
  });

  applyLang();
})();
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('../sw.js', { scope: '../' }).catch(() => {}));
}
</script>
</body>
</html>
