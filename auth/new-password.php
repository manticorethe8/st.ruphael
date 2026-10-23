<?php
/* ============================================================
   auth/new-password.php — first-time password change.

   Reached only by a signed-in user whose must_change_pw flag is
   set, i.e. someone who arrived with a temporary password issued
   by an administrator. Until they choose a real password every
   other page bounces them back here (see index.php).
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

if (!sr_is_installed()) { header('Location: ../install.php'); exit; }

$user = current_user();
if (!$user)                              { header('Location: login.php'); exit; }
if ((int)$user['must_change_pw'] !== 1)  { header('Location: ../index.php'); exit; }

/* Same language source as the rest of the app: the login page stores
   sr_lang in a cookie (the SPA keeps it in sync), so a user working in
   English sees this page in English too — never a stray Amharic copy. */
$cookie = $_COOKIE['sr_lang'] ?? '';
$lang   = ($_GET['lang'] ?? '') !== '' ? $_GET['lang'] : ($cookie !== '' ? $cookie : ($user['lang'] ?? 'am'));
if ($lang !== 'am' && $lang !== 'en') $lang = 'am';

try {
    $s      = settings_all(true);
    $nameAm = $s['school_name_am'] ?? APP_NAME_AM;
    $nameEn = $s['school_name_en'] ?? APP_NAME_EN;
    /* Honour Settings -> Identity name here too (see lib/helpers.php):
       with "always Amharic" on, both faces of this page carry the
       Amharic name. */
    if (!empty($s['brand_always_amharic']) && $nameAm !== '') $nameEn = $nameAm;
} catch (Throwable $e) {
    $nameAm = APP_NAME_AM; $nameEn = APP_NAME_EN;
}
$csrf = csrf_token();
$am   = $lang === 'am';
?>
<!doctype html>
<html lang="<?= $lang ?>" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0d2f57">
<meta name="robots" content="noindex,nofollow">
<title><?= e($am ? 'አዲስ የይለፍ ቃል' : 'Set a new password') ?> · <?= e($am ? $nameAm : $nameEn) ?></title>
<link rel="icon" href="../assets/logo.png">
<link rel="stylesheet" href="../css/fonts.min.css">
<style>
:root{
  --blue-950:#081f3c; --blue-900:#0d2f57; --blue-800:#123f75; --blue-700:#1753a0;
  --blue-600:#1e64c0; --blue-500:#2f7fd4; --blue-300:#9cc2ec; --blue-100:#dbe9f8; --blue-50:#eef5fd;
  --gold:#c9a227; --gold-100:#f7efda;
  --ink:#14263a; --muted:#5c6f84; --bg:#f1f5fa; --card:#fff; --line:#e1e9f2;
  --red:#b3372b; --red-bg:#fdeceb; --green:#146c43; --green-bg:#e4f5ec;
  --amber:#8a6100; --amber-bg:#fdf3dc; --amber-line:#eed9a4;
  --shadow-md:0 4px 14px rgba(13,47,87,.10),0 1px 4px rgba(13,47,87,.07);
  --shadow-lg:0 14px 38px rgba(13,47,87,.16);
  --font:'Inter','Noto Sans Ethiopic',system-ui,'Segoe UI',Roboto,Arial,sans-serif;
}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:var(--font);color:var(--ink);font-size:15px;line-height:1.55;
  -webkit-font-smoothing:antialiased;min-height:100dvh;display:flex;align-items:center;justify-content:center;
  padding:32px 20px;background:
    radial-gradient(760px 300px at 108% -60px,rgba(47,127,212,.09),transparent 60%),
    radial-gradient(520px 260px at -80px 112%,rgba(201,162,39,.08),transparent 58%),var(--bg)}
:focus-visible{outline:2px solid var(--gold);outline-offset:2px;border-radius:6px}
img{display:block;max-width:100%}
.box{width:100%;max-width:440px}
.mark{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.mark img{width:48px;height:48px;border-radius:12px;background:#fff;padding:5px;box-shadow:var(--shadow-md)}
.mark b{font-size:1rem;display:block;letter-spacing:.1px}
.mark span{display:block;font-size:.78rem;color:var(--muted)}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px 26px;box-shadow:var(--shadow-lg)}
.key{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--blue-700),var(--blue-500));color:#fff;margin-bottom:16px;
  box-shadow:0 4px 14px rgba(23,83,160,.28)}
.key svg{width:23px;height:23px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:1.42rem;margin:0 0 7px;font-weight:800;letter-spacing:-.3px}
.lede{color:var(--muted);margin:0 0 20px;font-size:.92rem}
.who{display:flex;align-items:center;gap:10px;background:var(--blue-50);border:1px solid var(--blue-100);
  border-radius:12px;padding:10px 13px;margin-bottom:18px}
.who .av{width:34px;height:34px;border-radius:50%;background:var(--blue-700);color:#fff;font-weight:700;
  font-size:.82rem;display:flex;align-items:center;justify-content:center;flex:none}
.who b{display:block;font-size:.9rem;line-height:1.3}
.who span{display:block;font-size:.78rem;color:var(--muted)}
.note{display:flex;gap:9px;background:var(--amber-bg);border:1px solid var(--amber-line);color:var(--amber);
  border-radius:12px;padding:11px 13px;font-size:.85rem;margin-bottom:20px}
.note svg{width:17px;height:17px;flex:none;margin-top:2px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.field{margin-bottom:15px}
label{display:block;font-size:.83rem;font-weight:650;margin-bottom:6px}
.inp{position:relative;display:flex;align-items:center}
.inp > svg{position:absolute;left:13px;width:17px;height:17px;stroke:var(--muted);fill:none;stroke-width:1.8;
  stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
input[type=password],input[type=text]{width:100%;font-family:inherit;font-size:.97rem;color:var(--ink);
  padding:12px 44px 12px 40px;border:1.5px solid var(--line);border-radius:11px;background:#fbfdff;
  transition:border-color .15s,box-shadow .15s}
input:focus{outline:none;border-color:var(--blue-500);box-shadow:0 0 0 3.5px rgba(47,127,212,.16);background:#fff}
input[aria-invalid=true]{border-color:var(--red);box-shadow:0 0 0 3.5px rgba(179,55,43,.13)}
.peek{position:absolute;right:6px;background:none;border:0;padding:8px;border-radius:8px;color:var(--muted);display:flex;cursor:pointer}
.peek:hover{color:var(--blue-700);background:var(--blue-50)}
.peek svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}

.meter{display:flex;gap:5px;margin-top:9px}
.meter i{flex:1;height:4px;border-radius:999px;background:#e6edf5;transition:background .2s}
.meter.s1 i:nth-child(1){background:#d1584a}
.meter.s2 i:nth-child(-n+2){background:#d99a2b}
.meter.s3 i:nth-child(-n+3){background:#4a9d6a}
.meter.s4 i{background:var(--green)}
.smsg{font-size:.78rem;color:var(--muted);margin-top:6px;min-height:1.1em}

.rules{list-style:none;margin:6px 0 20px;padding:0;font-size:.82rem;color:var(--muted)}
.rules li{display:flex;align-items:center;gap:8px;padding:3px 0}
.rules li::before{content:"";width:15px;height:15px;border-radius:50%;border:1.6px solid #cfdae7;flex:none;
  background-repeat:no-repeat;background-position:center;transition:.15s}
.rules li.ok{color:var(--green)}
.rules li.ok::before{border-color:var(--green);background-color:var(--green);
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m5 12.5 4.5 4.5L19 7'/%3E%3C/svg%3E");
  background-size:10px}

.btn{width:100%;border:0;border-radius:11px;padding:13px 18px;font-size:.99rem;font-weight:700;color:#fff;cursor:pointer;
  background:linear-gradient(135deg,var(--blue-700),var(--blue-600));box-shadow:0 3px 12px rgba(23,83,160,.30);
  display:flex;align-items:center;justify-content:center;gap:10px;transition:filter .15s,transform .06s}
.btn:hover:not(:disabled){filter:brightness(1.07)}
.btn:active:not(:disabled){transform:translateY(1px)}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.loading{cursor:progress}
.spin{width:17px;height:17px;border:2.4px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;
  animation:sp .7s linear infinite;display:none}
.btn.loading .spin{display:block}
@keyframes sp{to{transform:rotate(360deg)}}

.alert{display:none;align-items:flex-start;gap:10px;padding:11px 13px;border-radius:11px;font-size:.88rem;
  margin-bottom:16px;border:1px solid transparent}
.alert.show{display:flex}
.alert.err{background:var(--red-bg);border-color:#f3c6c1;color:#7d241b}
.alert.ok{background:var(--green-bg);border-color:#bfe3ce;color:#0e4c30}
.alert svg{width:17px;height:17px;flex:none;margin-top:1px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.foot{text-align:center;margin-top:18px;font-size:.79rem;color:var(--muted)}
.foot a{color:var(--blue-600);text-decoration:none}
.foot a:hover{text-decoration:underline}
@media (max-width:520px){
  .card{padding:22px 18px;border-radius:16px} h1{font-size:1.28rem}
  
  .mark{flex-direction:column;align-items:center;text-align:center;gap:10px;margin-bottom:24px}
  .mark img{width:58px;height:58px}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}

</style></head>
<body>
<main class="box">

  <div class="mark">
    <img src="../assets/logo.png" alt="">
    <div>
      <b><?= e($am ? $nameAm : $nameEn) ?></b>
      <span><?= e($am ? 'የተማሪ መገኘት አስተዳደር' : 'Student attendance management') ?></span>
    </div>
  </div>

  <div class="card">
    <div class="key" aria-hidden="true">
      <svg viewBox="0 0 24 24"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/><circle cx="12" cy="15.5" r="1.3" fill="currentColor" stroke="none"/></svg>
    </div>

    <h1><?= e($am ? 'አዲስ የይለፍ ቃል ያስገቡ' : 'Set your new password') ?></h1>
    <p class="lede">
      <?= e($am
        ? 'ወደ ሥርዓቱ የገቡት በጊዜያዊ የይለፍ ቃል ነው። ከመቀጠልዎ በፊት የራስዎን የይለፍ ቃል ይምረጡ።'
        : 'You signed in with a temporary password. Choose your own password before you continue.') ?>
    </p>

    <div class="who">
      <div class="av"><?= e(mb_strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1))) ?></div>
      <div>
        <b><?= e($user['full_name']) ?></b>
        <span><?= e($user['username']) ?></span>
      </div>
    </div>

    <div class="note">
      <svg viewBox="0 0 24 24"><path d="M12 3 2.5 20h19z"/><path d="M12 9.5v4.5M12 17.2v.3"/></svg>
      <span><?= e($am
        ? 'ጊዜያዊው የይለፍ ቃል ከአሁን በኋላ አይሠራም። አዲሱን የይለፍ ቃል ለማንም አያካፍሉ።'
        : 'The temporary password stops working once you finish. Keep your new password private.') ?></span>
    </div>

    <div class="alert err" id="errBox" role="alert">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16v.4"/></svg>
      <span id="errTxt"></span>
    </div>
    <div class="alert ok" id="okBox" role="status">
      <svg viewBox="0 0 24 24"><path d="m4.5 12.5 5 5 10-11"/></svg>
      <span id="okTxt"></span>
    </div>

    <form id="pwForm" autocomplete="off" novalidate>
      <div class="field">
        <label for="np"><?= e($am ? 'አዲስ የይለፍ ቃል' : 'New password') ?></label>
        <div class="inp">
          <svg viewBox="0 0 24 24"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>
          <input type="password" id="np" name="new_password" autocomplete="new-password" required>
          <button type="button" class="peek" id="np-peek" aria-label="<?= e($am ? 'አሳይ' : 'Show') ?>">
            <svg viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <div class="meter" id="meter"><i></i><i></i><i></i><i></i></div>
        <div class="smsg" id="smsg"></div>
      </div>

      <div class="field">
        <label for="cp"><?= e($am ? 'የይለፍ ቃሉን ያረጋግጡ' : 'Confirm password') ?></label>
        <div class="inp">
          <svg viewBox="0 0 24 24"><rect x="4" y="10.5" width="16" height="10" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>
          <input type="password" id="cp" name="confirm_password" autocomplete="new-password" required>
          <button type="button" class="peek" id="cp-peek" aria-label="<?= e($am ? 'አሳይ' : 'Show') ?>">
            <svg viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <ul class="rules" id="rules">
        <li data-rule="len"><?= e($am ? 'ቢያንስ ፰ ፊደላት' : 'At least 8 characters') ?></li>
        <li data-rule="mix"><?= e($am ? 'ፊደልና ቁጥር ይዟል' : 'Contains a letter and a number') ?></li>
        <li data-rule="match"><?= e($am ? 'ሁለቱ የይለፍ ቃላት ይመሳሰላሉ' : 'Both passwords match') ?></li>
      </ul>

      <button class="btn" id="go" type="submit" disabled>
        <span class="spin"></span>
        <span class="btn-label"><?= e($am ? 'የይለፍ ቃል አስቀምጥ እና ቀጥል' : 'Save password and continue') ?></span>
      </button>
    </form>
  </div>

  <p class="foot">
    <?= e($am ? 'ይህ እርስዎ አይደሉም?' : 'Not you?') ?>
    <a href="logout.php"><?= e($am ? 'ውጣ' : 'Sign out') ?></a>
  </p>
</main>

<script>
(function () {
  'use strict';
  var AM   = <?= $am ? 'true' : 'false' ?>;
  var csrf = <?= json_encode($csrf) ?>;

  var np = document.getElementById('np'),
      cp = document.getElementById('cp'),
      go = document.getElementById('go'),
      meter = document.getElementById('meter'),
      smsg  = document.getElementById('smsg'),
      rules = document.getElementById('rules'),
      errBox = document.getElementById('errBox'), errTxt = document.getElementById('errTxt'),
      okBox  = document.getElementById('okBox'),  okTxt  = document.getElementById('okTxt');

  var T = {
    weak   : AM ? 'ደካማ'        : 'Weak',
    fair   : AM ? 'መጠነኛ'       : 'Fair',
    good   : AM ? 'ጥሩ'         : 'Good',
    strong : AM ? 'በጣም ጠንካራ'  : 'Strong',
    saving : AM ? 'በማስቀመጥ ላይ…' : 'Saving…',
    saved  : AM ? 'ተቀምጧል። በማስገባት ላይ…' : 'Saved. Taking you in…',
    net    : AM ? 'አገልጋዩ ጋር መገናኘት አልተቻለም።' : 'Could not reach the server.',
    label  : AM ? 'የይለፍ ቃል አስቀምጥ እና ቀጥል' : 'Save password and continue'
  };

  
  [['np','np-peek'],['cp','cp-peek']].forEach(function (p) {
    var i = document.getElementById(p[0]), b = document.getElementById(p[1]);
    b.addEventListener('click', function () {
      i.type = i.type === 'password' ? 'text' : 'password';
      i.focus();
    });
  });

  function score(v) {
    var s = 0;
    if (v.length >= 8)  s++;
    if (v.length >= 12) s++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
    if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
    else if (/\d/.test(v) || /[^A-Za-z0-9]/.test(v)) s += 0.5;
    return Math.max(0, Math.min(4, Math.round(s)));
  }

  function check() {
    var a = np.value, b = cp.value;
    var ok = {
      len   : a.length >= 8,
      mix   : /[A-Za-z\u1200-\u137F]/.test(a) && /\d/.test(a),
      match : a.length > 0 && a === b
    };
    Array.prototype.forEach.call(rules.children, function (li) {
      li.classList.toggle('ok', !!ok[li.dataset.rule]);
    });

    var s = a ? score(a) : 0;
    meter.className = 'meter' + (a ? ' s' + s : '');
    smsg.textContent = a ? [T.weak, T.weak, T.fair, T.good, T.strong][s] : '';

    go.disabled = !(ok.len && ok.match);
    return ok;
  }

  np.addEventListener('input', function () { hide(); check(); });
  cp.addEventListener('input', function () { hide(); check(); });

  function hide() { errBox.classList.remove('show'); }
  function fail(m) { errTxt.textContent = m; errBox.classList.add('show'); }
  function busy(on) {
    go.disabled = on;
    go.classList.toggle('loading', on);
    go.querySelector('.btn-label').textContent = on ? T.saving : T.label;
  }

  document.getElementById('pwForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    hide();
    var ok = check();
    if (!ok.len || !ok.match) return;

    busy(true);
    fetch('../api/auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' },
      body: JSON.stringify({
        action: 'change_password',
        new_password: np.value,
        confirm_password: cp.value
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j && j.ok) {
        okTxt.textContent = T.saved;
        okBox.classList.add('show');
        setTimeout(function () { location.href = '../index.php'; }, 550);
        return;
      }
      busy(false);
      check();
      fail(((j || {}).error || {}).message || T.net);
    })
    .catch(function () { busy(false); check(); fail(T.net); });
  });

  np.focus();
})();

</script></body>
</html>
