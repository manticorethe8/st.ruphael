<?php
/* ============================================================
   auth/login.php — two-sided sign-in (branding left / form right)
   ------------------------------------------------------------
   This page only DRAWS the form. It never checks a password:
   the credentials are posted to api/auth.php (action=login),
   which owns hashing, the failed-attempt throttle in the
   login_attempts table, session fixation defence and the audit
   entry. Keeping the check in one place means the API and this
   page can never disagree about who is allowed in.

   Deliberate behaviours:
     * Errors are intentionally vague ("bad credentials") so the
       page cannot be used to discover which usernames exist.
     * The CSS is inlined rather than pulled from css/styles.css
       so the sign-in screen still renders correctly before the
       service worker has cached anything.
     * A stale CSRF token is refreshed silently and the user is
       invited to retry, instead of being shown a confusing
       security error after leaving the tab open overnight.
     * An account still on a temporary password is redirected by
       the SERVER to new-password.php; that decision is not made
       here, so it cannot be skipped by editing the page.
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

if (!sr_is_installed()) { header('Location: ../install.php'); exit; }
if (is_logged_in())     { header('Location: ../index.php');   exit; }

/* Language comes from the cookie the switcher sets, so the sign-in screen
   is already in the reader's language before any account exists. */
$lang   = (($_COOKIE['sr_lang'] ?? 'am') === 'en') ? 'en' : 'am';
$token  = csrf_token();

/* Branding is read from settings, but the school may not be installed or the
   database may be unreachable — fall back to the built-in names rather than
   failing to render a page whose whole purpose is to let someone in. */
$school = 'am';
$quoteAm = ''; $quoteRef = '';
try {
    $nameAm = setting('school_name_am', APP_NAME_AM);
    $nameEn = setting('school_name_en', APP_NAME_EN);
    /* Settings -> Identity name. With "always Amharic" on (the default)
       BOTH faces of this page show the Amharic name, so the in-page
       language toggle cannot undo the administrator's choice. Off, the
       name follows the page language. */
    if (sr_brand_always_amharic()) $nameEn = $nameAm !== '' ? $nameAm : $nameEn;
    /* The quotation is Amharic only — it does not switch with the page
       language. Empty text hides the block. The default is the sample so
       the panel is never blank on a database that has not been migrated. */
    $quoteAm  = trim((string)setting('login_quote_am',
        'እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድዬ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ እንዳይጠፋ ይልቁንም የዘላለም ሕይወት እንዲኖረው።'));
    $quoteRef = trim((string)setting('login_quote_ref', 'ዮሐንስ 3፡16'));
} catch (Throwable $e) {
    $nameAm = APP_NAME_AM; $nameEn = APP_NAME_EN;
}
$today = et_label(date('Y-m-d'), $lang);
?>
<!doctype html>
<html lang="<?= $lang ?>" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0d2f57">
<title><?= e($lang === 'am' ? 'ግባ' : 'Sign in') ?> · <?= e($lang === 'am' ? $nameAm : $nameEn) ?></title>
<link rel="icon" href="../assets/logo.png">
<link rel="manifest" href="../manifest.json">
<link rel="stylesheet" href="../css/fonts.min.css">
<style>
:root{
  --blue-950:#081f3c; --blue-900:#0d2f57; --blue-800:#123f75; --blue-700:#1753a0;
  --blue-600:#1e64c0; --blue-500:#2f7fd4; --blue-300:#9cc2ec; --blue-100:#dbe9f8; --blue-50:#eef5fd;
  --gold:#c9a227; --gold-600:#a8871c; --gold-100:#f7efda;
  --ink:#14263a; --muted:#5c6f84; --bg:#f1f5fa; --card:#fff; --line:#e1e9f2;
  --red:#b3372b; --red-bg:#fdeceb; --green:#146c43; --green-bg:#e4f5ec;
  --shadow-md:0 4px 14px rgba(13,47,87,.10),0 1px 4px rgba(13,47,87,.07);
  --shadow-lg:0 14px 38px rgba(13,47,87,.16);
  --font:'Inter','Noto Sans Ethiopic',system-ui,'Segoe UI',Roboto,Arial,sans-serif;
}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:var(--font);color:var(--ink);background:var(--bg);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}
:focus-visible{outline:2px solid var(--gold);outline-offset:2px;border-radius:6px}
img{display:block;max-width:100%}

.wrap{min-height:100dvh;display:grid;grid-template-columns:1.05fr .95fr}

.side{
  position:relative;color:#fff;padding:48px 52px;display:flex;flex-direction:column;
  background:linear-gradient(150deg,var(--blue-950) 0%,var(--blue-900) 42%,var(--blue-700) 100%);
  overflow:hidden;
}
.side::before{content:"";position:absolute;inset:0;
  background:radial-gradient(680px 320px at 88% -8%,rgba(201,162,39,.20),transparent 62%),
             radial-gradient(520px 320px at -12% 108%,rgba(93,154,222,.24),transparent 60%);}
.side::after{content:"";position:absolute;top:0;right:0;bottom:0;width:3px;
  background:linear-gradient(180deg,transparent,var(--gold) 22%,#e6c85a 50%,var(--gold) 78%,transparent)}
.side > *{position:relative;z-index:1}
.mark{display:flex;align-items:center;gap:14px}
.mark img{width:58px;height:58px;border-radius:14px;background:rgba(255,255,255,.94);padding:6px;box-shadow:var(--shadow-md)}
.mark b{display:block;font-size:1.06rem;letter-spacing:.2px}
.mark span{display:block;font-size:.82rem;color:var(--blue-300)}
.side-sp{flex:1;min-height:24px}

.side-quote{position:relative;margin:auto 0;padding:26px 4px 20px}
.side-quote::before{content:"“";position:absolute;top:-14px;left:-10px;font-size:5.2rem;line-height:1;
  font-family:Georgia,'Times New Roman',serif;color:var(--gold);opacity:.55;pointer-events:none}
.side-quote::after{content:"";display:block;width:44px;height:2px;margin:18px 0 0;
  background:linear-gradient(90deg,var(--gold),transparent)}
.side-quote p{margin:0;font-size:1.12rem;line-height:1.9;color:#eef4fc;max-width:36ch}
.side-quote cite{display:block;margin-top:10px;font-style:normal;font-size:.9rem;font-weight:700;
  color:var(--gold);letter-spacing:.4px}
.side-foot{font-size:.78rem;color:#9dbbdd;border-top:1px solid rgba(255,255,255,.13);padding-top:16px}

.panel{display:flex;align-items:center;justify-content:center;padding:40px 28px;background:
  radial-gradient(760px 300px at 110% -40px,rgba(47,127,212,.07),transparent 60%),
  radial-gradient(520px 260px at -80px 110%,rgba(201,162,39,.06),transparent 58%),var(--bg)}
.box{width:100%;max-width:410px}
.box-head{margin-bottom:24px}
.box-head h2{font-size:1.6rem;margin:0 0 6px;font-weight:800;letter-spacing:-.3px}
.box-head p{color:var(--muted);margin:0;font-size:.93rem}
.mob-mark{display:none;align-items:center;gap:11px;margin-bottom:22px}
.mob-mark img{width:44px;height:44px;border-radius:11px;box-shadow:var(--shadow-md)}
.mob-mark b{font-size:.98rem}.mob-mark span{display:block;font-size:.76rem;color:var(--muted)}

form{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:26px 24px;box-shadow:var(--shadow-lg)}
.field{margin-bottom:16px}
label{display:block;font-size:.83rem;font-weight:650;color:var(--ink);margin-bottom:6px}
.inp{position:relative;display:flex;align-items:center}
.inp svg{position:absolute;left:13px;width:17px;height:17px;stroke:var(--muted);pointer-events:none}
input[type=text],input[type=password]{
  width:100%;font-family:inherit;font-size:.97rem;color:var(--ink);
  padding:12px 13px 12px 40px;border:1.5px solid var(--line);border-radius:11px;background:#fbfdff;transition:border-color .15s,box-shadow .15s}
input:focus{outline:none;border-color:var(--blue-500);box-shadow:0 0 0 3.5px rgba(47,127,212,.16);background:#fff}
input[aria-invalid=true]{border-color:var(--red);box-shadow:0 0 0 3.5px rgba(179,55,43,.13)}
.peek{position:absolute;right:6px;background:none;border:0;padding:8px;border-radius:8px;color:var(--muted);display:flex}
.peek:hover{color:var(--blue-700);background:var(--blue-50)}
.peek svg{position:static;width:18px;height:18px;stroke:currentColor}
.row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 18px}
.hint{font-size:.82rem;color:var(--muted)}

.btn{width:100%;border:0;border-radius:11px;padding:13px 18px;font-size:.99rem;font-weight:700;color:#fff;cursor:pointer;
  background:linear-gradient(135deg,var(--blue-700),var(--blue-600));box-shadow:0 3px 12px rgba(23,83,160,.30);
  display:flex;align-items:center;justify-content:center;gap:10px;transition:filter .15s,transform .06s}
.btn:hover:not(:disabled){filter:brightness(1.07)}
.btn:active:not(:disabled){transform:translateY(1px)}
.btn:disabled{opacity:.72;cursor:progress}
.spin{width:17px;height:17px;border:2.4px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite;display:none}
.btn.loading .spin{display:block}
.btn.loading .btn-label{opacity:.92}
@keyframes sp{to{transform:rotate(360deg)}}

.alert{display:none;align-items:flex-start;gap:10px;padding:11px 13px;border-radius:11px;font-size:.88rem;margin-bottom:16px;border:1px solid transparent}
.alert.show{display:flex}
.alert.err{background:var(--red-bg);border-color:#f3c6c1;color:#7d241b}
.alert.ok{background:var(--green-bg);border-color:#bfe3ce;color:#0e4c30}
.alert svg{width:17px;height:17px;flex:none;margin-top:1px;stroke:currentColor}

.lang{display:flex;gap:4px;background:#e7eef7;border:1px solid var(--line);border-radius:999px;padding:3px;width:max-content;margin:0 auto 20px}
.lang button{border:0;background:none;padding:6px 15px;border-radius:999px;font-size:.83rem;font-weight:650;color:var(--muted);cursor:pointer}
.lang button[aria-pressed=true]{background:#fff;color:var(--blue-800);box-shadow:var(--shadow-md)}
.linkish{background:none;border:0;padding:0;font:inherit;font-size:.82rem;
  color:var(--blue-600,#2f7fd4);cursor:pointer;text-decoration:underline}
.linkish:hover{color:var(--blue-800,#1753a0)}
.recov-h{font-size:1.02rem;font-weight:700;margin-bottom:8px}
.recov-p{font-size:.85rem;color:var(--muted);line-height:1.6;margin-bottom:16px}
#recovCancel{display:block;margin:4px auto 0}

@media (max-width:900px){
  .wrap{grid-template-columns:1fr}
  .side{display:none}
  
  .mob-mark{display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;margin-bottom:24px}
  .mob-mark img{width:60px;height:60px;border-radius:16px}
  .mob-mark b{font-size:1.06rem;line-height:1.3;display:block}
  .mob-mark span{font-size:.8rem;margin-top:2px}
  .panel{padding:28px 20px;align-items:flex-start;min-height:100dvh}
  .box{margin-top:8px}
  .box-head{text-align:center}
  .lang{justify-content:center}
  form{padding:22px 18px;border-radius:16px}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}

</style></head>
<body>
<div class="wrap">

  
  <aside class="side">
    <div class="mark">
      <img src="../assets/logo.png" alt="">
      <div>
        <b data-am="<?= e($nameAm) ?>" data-en="<?= e($nameEn) ?>"><?= e($lang === 'am' ? $nameAm : $nameEn) ?></b>
        <span data-am="የተማሪዎች እና የክትትል ሥርዓት" data-en="Student &amp; Attendance System"><?=
          $lang === 'am' ? 'የተማሪዎች እና የክትትል ሥርዓት' : 'Student &amp; Attendance System' ?></span>
      </div>
    </div>

    <?php if ($quoteAm !== ''): ?>
    <blockquote class="side-quote" aria-label="<?= e($lang === 'am' ? 'የቅዱስ ጽሑፍ ጥቅስ' : 'Scripture quotation') ?>">
      <p><?= e($quoteAm) ?></p>
      <?php if ($quoteRef !== ''): ?><cite>— <?= e($quoteRef) ?></cite><?php endif; ?>
    </blockquote>
    <?php else: ?>
    <div class="side-sp" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="side-foot">
      <span id="etDate"><?= e($today) ?></span> ·
      <span data-am="ሥሪት" data-en="Version"><?= $lang === 'am' ? 'ሥሪት' : 'Version' ?></span> <?= e(APP_VERSION) ?>
    </div>
  </aside>

  
  <main class="panel">
    <div class="box">
      <div class="mob-mark">
        <img src="../assets/logo.png" alt="">
        <div>
          <b data-am="<?= e($nameAm) ?>" data-en="<?= e($nameEn) ?>"><?= e($lang === 'am' ? $nameAm : $nameEn) ?></b>
          <span data-am="የተማሪዎች እና የክትትል ሥርዓት" data-en="Student &amp; Attendance System"><?=
            $lang === 'am' ? 'የተማሪዎች እና የክትትል ሥርዓት' : 'Student &amp; Attendance System' ?></span>
        </div>
      </div>

      <div class="lang" role="group" aria-label="Language">
        <button type="button" data-lang="am" aria-pressed="<?= $lang === 'am' ? 'true' : 'false' ?>">አማርኛ</button>
        <button type="button" data-lang="en" aria-pressed="<?= $lang === 'en' ? 'true' : 'false' ?>">English</button>
      </div>

      <div class="box-head">
        <h2 data-am="እንኳን ደህና መጡ" data-en="Welcome back"><?= $lang === 'am' ? 'እንኳን ደህና መጡ' : 'Welcome back' ?></h2>
        <p data-am="ለመቀጠል በመለያዎ ይግቡ።" data-en="Sign in to continue."><?=
          $lang === 'am' ? 'ለመቀጠል በመለያዎ ይግቡ።' : 'Sign in to continue.' ?></p>
      </div>

      <form id="loginForm" method="post" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

        <div class="alert err" id="errBox" role="alert" aria-live="assertive">
          <svg fill="none" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
          <span id="errText"></span>
        </div>
<?php $bye = isset($_GET['bye']); ?>
        <div class="alert ok<?= $bye ? ' show' : '' ?>" id="okBox" role="status" aria-live="polite">
          <svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
          <span id="okText" <?= $bye ? 'data-am="በሰላም ወጥተዋል።" data-en="You have been signed out."' : '' ?>><?= $bye ? ($lang === 'am' ? 'በሰላም ወጥተዋል።' : 'You have been signed out.') : '' ?></span>
        </div>

        <div class="field">
          <label for="username" data-am="የተጠቃሚ ስም" data-en="Username"><?= $lang === 'am' ? 'የተጠቃሚ ስም' : 'Username' ?></label>
          <div class="inp">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/></svg>
            <input id="username" name="username" type="text" required autocapitalize="none" autocomplete="username"
                   spellcheck="false" autofocus>
          </div>
        </div>

        <div class="field">
          <label for="password" data-am="የይለፍ ቃል" data-en="Password"><?= $lang === 'am' ? 'የይለፍ ቃል' : 'Password' ?></label>
          <div class="inp">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            <button class="peek" type="button" id="peek" aria-label="Show password">
              <svg fill="none" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="row">
          <button type="button" class="linkish" id="forgotBtn"
                  data-am="የይለፍ ቃል ረሱ?" data-en="Forgot your password?"><?=
            $lang === 'am' ? 'የይለፍ ቃል ረሱ?' : 'Forgot your password?' ?></button>
        </div>

        <button class="btn" type="submit" id="submitBtn">
          <span class="spin" aria-hidden="true"></span>
          <span class="btn-label" data-am="ግባ" data-en="Sign in"><?= $lang === 'am' ? 'ግባ' : 'Sign in' ?></span>
        </button>
      </form>

      
      <div class="recov" id="recovPanel" hidden>
        <div class="recov-h" data-am="መለያዎን መልሶ ማግኘት" data-en="Recover your account"><?=
          $lang === 'am' ? 'መለያዎን መልሶ ማግኘት' : 'Recover your account' ?></div>

        <p class="recov-p" id="recovIntro"
           data-am="የይለፍ ቃልዎን ማስተካከል የሚችለው አስተዳዳሪዎ ነው።"
           data-en="Your administrator resets your password for you."><?=
          $lang === 'am' ? 'የይለፍ ቃልዎን ማስተካከል የሚችለው አስተዳዳሪዎ ነው።'
            : 'Your administrator resets your password for you.' ?></p>

        <button type="button" class="linkish" id="recovCancel"
                data-am="ተመለስ" data-en="Back to sign in"><?=
          $lang === 'am' ? 'ተመለስ' : 'Back to sign in' ?></button>
      </div>
    </div>
  </main>
</div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('loginForm'),
      btn  = document.getElementById('submitBtn'),
      errB = document.getElementById('errBox'), errT = document.getElementById('errText'),
      okB  = document.getElementById('okBox'),  okT  = document.getElementById('okText'),
      uEl  = document.getElementById('username'), pEl = document.getElementById('password'),
      csrf = form.csrf_token.value,
      lang = document.documentElement.lang === 'en' ? 'en' : 'am';

  var MSG = {
    am: { empty:'እባክዎ የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ።', net:'ከአገልጋዩ ጋር መገናኘት አልተቻለም። ግንኙነትዎን ያረጋግጡ።',
          ok:'ገብተዋል። በማስተላለፍ ላይ…', wait:'በመግባት ላይ…', signin:'ግባ', show:'የይለፍ ቃል አሳይ', hide:'የይለፍ ቃል ደብቅ' },
    en: { empty:'Please enter both your username and password.', net:'Could not reach the server. Check your connection.',
          ok:'Signed in. Redirecting…', wait:'Signing in…', signin:'Sign in', show:'Show password', hide:'Hide password' }
  };
  function t(k){ return MSG[lang][k]; }

  
  var ERR_AM = {
    bad_credentials : 'የተጠቃሚ ስም ወይም የይለፍ ቃል ትክክል አይደለም።',
    locked          : 'በተደጋጋሚ በተሳሳተ ሙከራ ምክንያት መለያው ለጊዜው ተቆልፏል። እባክዎ ትንሽ ቆይተው ይሞክሩ።',
    inactive        : 'ይህ መለያ ተሰናክሏል። አስተዳዳሪዎን ያነጋግሩ።',
    validation      : 'እባክዎ የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ።',
    csrf            : 'ክፍለ ጊዜው አልፎበታል። እባክዎ እንደገና ይሞክሩ።',
    bad_key         : 'የመልሶ ማግኛ ቁልፉ ትክክል አይደለም።',
    locked_out      : 'በተደጋጋሚ በተሳሳተ ሙከራ ምክንያት ለጊዜው ተቆልፏል። እባክዎ 15 ደቂቃ ቆይተው ይሞክሩ።',
    server          : 'በአገልጋዩ ላይ ችግር ተፈጥሯል። እባክዎ እንደገና ይሞክሩ።'
  };
  function errMsg(err){
    if (lang === 'am' && err.type && ERR_AM[err.type]) return ERR_AM[err.type];
    return err.message || t('net');
  }

  
  document.querySelectorAll('.lang button').forEach(function (b) {
    b.addEventListener('click', function () {
      lang = b.dataset.lang;
      document.cookie = 'sr_lang=' + lang + ';path=/;max-age=31536000;samesite=Lax';
      document.documentElement.lang = lang;
      document.querySelectorAll('.lang button').forEach(function (x) {
        x.setAttribute('aria-pressed', String(x.dataset.lang === lang));
      });
      document.querySelectorAll('[data-am][data-en]').forEach(function (el) {
        el.innerHTML = el.dataset[lang];
      });
      
      hideMsgs();
    });
  });

  
  var peek = document.getElementById('peek');
  peek.addEventListener('click', function () {
    var show = pEl.type === 'password';
    pEl.type = show ? 'text' : 'password';
    peek.setAttribute('aria-label', show ? t('hide') : t('show'));
    pEl.focus();
  });

  function hideMsgs(){ errB.classList.remove('show'); okB.classList.remove('show');
    uEl.removeAttribute('aria-invalid'); pEl.removeAttribute('aria-invalid'); }
  function fail(msg, which){
    okB.classList.remove('show');
    errT.textContent = msg; errB.classList.add('show');
    if (which !== 'none') { uEl.setAttribute('aria-invalid','true'); pEl.setAttribute('aria-invalid','true'); }
    (which === 'user' ? uEl : pEl).focus();
  }
  function busy(on){
    btn.disabled = on;
    btn.classList.toggle('loading', on);
    btn.querySelector('.btn-label').textContent = on ? t('wait') : t('signin');
  }
  [uEl, pEl].forEach(function (el) { el.addEventListener('input', hideMsgs); });

  
  var fBtn = document.getElementById('forgotBtn');
  var rPanel = document.getElementById('recovPanel');

  /* The recovery panel is informational only: password recovery is done by
     an administrator in the app. The Master recovery-key form is not
     exposed on the login screen. */
  if (fBtn) fBtn.addEventListener('click', function () {
    hideMsgs();
    form.hidden = true; rPanel.hidden = false;
  });

  var rCancel = document.getElementById('recovCancel');
  if (rCancel) rCancel.addEventListener('click', function () {
    rPanel.hidden = true; form.hidden = false; hideMsgs();
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    hideMsgs();
    var u = uEl.value.trim(), p = pEl.value;
    if (!u || !p) { fail(t('empty'), u ? 'pass' : 'user'); return; }

    busy(true);
    fetch('../api/auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ action:'login', username:u, password:p, lang: lang })
    })
    .then(function (r) { return r.json().then(function (j) { return { s:r.status, j:j }; }); })
    .then(function (res) {
      if (res.j && res.j.ok) {
        okT.textContent = t('ok'); okB.classList.add('show');
        
        location.href = (res.j.data && res.j.data.redirect) || '../index.php';
        return;
      }
      busy(false);
      var err = (res.j && res.j.error) || {};
      
      if (err.type === 'csrf') {
        fetch('../api/auth.php?action=me', { credentials:'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.ok && j.data && j.data.csrf_token) csrf = j.data.csrf_token; })
          .catch(function(){});
      }
      fail(errMsg(err), err.type === 'bad_credentials' ? 'pass' : 'none');
    })
    .catch(function () { busy(false); fail(t('net'), 'none'); });
  });
})();

</script></body>
</html>
