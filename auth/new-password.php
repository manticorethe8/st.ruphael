<?php
/* ============================================================
   auth/new-password.php — forced password change on first login
   or after an admin reset. Styled to match Google Stitch design.
   ============================================================ */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

if (!sr_is_installed()) { header('Location: ../install.php'); exit; }
$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
if ((int)($user['must_change_pw'] ?? 0) !== 1) { header('Location: ../index.php'); exit; }

$next = trim((string)($_GET['next'] ?? ''));
if ($next !== '' && !preg_match('/\A[a-zA-Z0-9_-]+\.php\z/D', $next)) $next = '';
$schoolAm = setting('school_name_am', APP_NAME_AM);
$schoolEn = setting('school_name_en', APP_NAME_EN);
if (sr_brand_always_amharic() && $schoolAm !== '') $schoolEn = $schoolAm;
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
<title><?= e($schoolAm) ?> · New password</title>
<link rel="stylesheet" href="../css/fonts.min.css?v=<?= e(APP_VERSION) ?>">
<style>
  :root{
    --primary:#004C9D; --primary-container:#1E64C0; --surface-container:#E5EEFF;
    --surface-low:#EFF4FF; --surface:#F8F9FF; --card:#FFFFFF; --subtle:#E8EFF8;
    --gold-soft:#FBF6E9; --gold-container:#FED255;
    --ink:#0D2F57; --muted:#64748B; --line:#E2E8F0;
    --bad:#DC2626; --bad-bg:#FEE2E2; --bad-ink:#991B1B;
    --ok:#16A34A; --ok-bg:#DCFCE7;
    --font:'Plus Jakarta Sans','Inter','Noto Sans Ethiopic',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  }
  *{box-sizing:border-box}
  html,body{height:100%;margin:0}
  body{
    font:400 14.5px/1.55 var(--font);color:var(--ink);
    background:
      radial-gradient(640px 420px at 88% 8%, rgba(254,210,85,.18), transparent 65%),
      radial-gradient(680px 460px at 10% 18%, rgba(30,100,192,.10), transparent 65%),
      var(--surface);
    display:grid;place-items:center;padding:24px 18px;
    -webkit-font-smoothing:antialiased;
  }
  .shell{width:100%;max-width:464px;display:flex;flex-direction:column;gap:14px}
  .top-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .brand{display:flex;align-items:center;gap:12px;min-width:0}
  .seal{
    width:46px;height:46px;border-radius:50%;padding:3px;flex:0 0 auto;
    background:var(--card);box-shadow:0 4px 14px rgba(13,47,87,.12),0 0 0 1px rgba(236,194,70,.4);
    display:grid;place-items:center;
  }
  .seal img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block}
  .brand b{display:block;font-size:15px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .brand span{font-size:12px;color:var(--muted)}

  .lang{
    display:inline-flex;align-items:center;padding:3px;border-radius:9999px;
    background:var(--subtle);flex:0 0 auto;
  }
  .lang button{
    border:0;background:transparent;padding:5px 11px;border-radius:9999px;
    font:600 12px/1.2 var(--font);color:var(--muted);cursor:pointer;
  }
  .lang button.on{background:var(--card);color:var(--ink);box-shadow:0 2px 6px rgba(13,47,87,.10)}

  .card{
    background:var(--card);border:1px solid rgba(226,232,240,.65);border-radius:24px;
    padding:26px 24px;box-shadow:0 10px 32px -6px rgba(13,47,87,.08),0 2px 6px rgba(13,47,87,.03);
  }
  h1{margin:0 0 6px;font-size:20px;font-weight:700;letter-spacing:-.015em;color:var(--ink)}
  .sub{margin:0 0 20px;color:var(--muted);font-size:13.5px;line-height:1.55}
  .field{margin-bottom:14px}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:6px}
  .inp-wrap{
    position:relative;display:flex;align-items:center;
    background:var(--surface);border:1.5px solid var(--subtle);
    border-radius:14px;transition:background .16s,border-color .16s,box-shadow .16s;
  }
  .inp-wrap:focus-within{
    background:var(--card);border-color:var(--primary-container);
    box-shadow:0 0 0 3.5px rgba(30,100,192,.14);
  }
  .inp-ico{padding:0 2px 0 13px;color:var(--muted);display:grid;place-items:center;pointer-events:none}
  input{
    width:100%;height:46px;padding:0 14px 0 10px;border:0;border-radius:14px;
    font:500 14.5px var(--font);color:var(--ink);background:transparent;outline:none;
  }
  .meter{height:6px;border-radius:9999px;background:var(--subtle);overflow:hidden;margin-top:8px}
  .meter i{display:block;height:100%;width:0;background:var(--bad);border-radius:9999px;transition:width .2s,background .2s}
  .rules{margin:12px 0 18px;padding:12px 14px;border-radius:14px;background:var(--surface-low);list-style:none;font-size:12.5px;color:var(--muted)}
  .rules li{padding:3px 0;display:flex;align-items:center;gap:8px;font-weight:500}
  .rules li::before{
    content:"";width:8px;height:8px;border-radius:50%;background:#CBD5E1;flex:0 0 auto;
    transition:background .16s,transform .16s;
  }
  .rules li.ok{color:var(--ok)}
  .rules li.ok::before{background:var(--ok);transform:scale(1.15)}

  .btn{
    width:100%;height:50px;border:0;border-radius:9999px;cursor:pointer;
    background:linear-gradient(135deg,var(--primary) 0%,var(--primary-container) 100%);
    color:#fff;font:600 15px var(--font);
    box-shadow:0 8px 20px -4px rgba(0,76,157,.34);
    transition:filter .16s,transform .14s;
  }
  .btn:hover{filter:brightness(1.05)}
  .btn:active{transform:scale(.985)}
  .btn:disabled{opacity:.55;cursor:not-allowed;transform:none}

  .msg{display:none;margin:0 0 14px;padding:11px 14px;border-radius:14px;font-size:13px;
       background:var(--bad-bg);color:var(--bad-ink);border:1px solid #FECACA}
  .msg.show{display:block}
  .out{display:flex;justify-content:center;margin-top:14px;font-size:12.5px}
  .out button{border:0;background:transparent;font:inherit;cursor:pointer;color:var(--muted);text-decoration:none;font-weight:500;padding:4px 10px;border-radius:9999px}
  .out button:hover{color:var(--ink);background:var(--subtle)}
</style>
</head>
<body>
<div class="shell">
  <div class="top-row">
    <div class="brand">
      <div class="seal"><img src="../assets/logo.png" alt=""></div>
      <div>
        <b id="schoolName"><?= e($schoolAm) ?></b>
        <span id="signedAs"><?= e($user['full_name']) ?> · @<?= e($user['username']) ?></span>
      </div>
    </div>
    <div class="lang" role="group" aria-label="Language">
      <button type="button" data-lang="am">አማርኛ</button>
      <button type="button" data-lang="en">English</button>
    </div>
  </div>

  <main class="card">
    <h1 data-t="title">አዲስ የይለፍ ቃል ይምረጡ</h1>
    <p class="sub" data-t="sub">ለመቀጠል እባክዎ የራስዎን የይለፍ ቃል ያዘጋጁ። ይህ የሚጠየቀው አንድ ጊዜ ብቻ ነው።</p>

    <div class="msg" id="err" role="alert"></div>

    <form id="pwForm" novalidate>
      <div class="field">
        <label for="np" data-t="newpw">አዲስ የይለፍ ቃል</label>
        <div class="inp-wrap">
          <span class="inp-ico" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          </span>
          <input id="np" type="password" autocomplete="new-password" required autofocus>
        </div>
        <div class="meter" id="meter"><i id="bar"></i></div>
      </div>
      <div class="field">
        <label for="cp" data-t="confirm">አዲሱን የይለፍ ቃል ያረጋግጡ</label>
        <div class="inp-wrap">
          <span class="inp-ico" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20 6 9 17l-5-5"/></svg>
          </span>
          <input id="cp" type="password" autocomplete="new-password" required>
        </div>
      </div>

      <ul class="rules" id="rules">
        <li data-r="len" data-t="r_len">ቢያንስ 8 ቁምፊዎች</li>
        <li data-r="mix" data-t="r_mix">ፊደላትና ቁጥሮች ይካተቱ</li>
        <li data-r="same" data-t="r_same">ሁለቱም አዲስ የይለፍ ቃሎች ይመሳሰሉ</li>
      </ul>

      <button class="btn" id="go" type="submit" disabled data-t="save">አስቀምጥና ቀጥል</button>
    </form>

    <div class="out"><form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" data-t="signout">ውጣ</button></form></div>
  </main>
</div>

<script>
(() => {
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const NEXT = <?= json_encode($next) ?>;
  const SCHOOL = { am: <?= json_encode($schoolAm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, en: <?= json_encode($schoolEn, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> };
  const T = {
    am:{ title:'አዲስ የይለፍ ቃል ይምረጡ',
         sub:'ለመቀጠል እባክዎ የራስዎን የይለፍ ቃል ያዘጋጁ። ይህ የሚጠየቀው አንድ ጊዜ ብቻ ነው።',
         newpw:'አዲስ የይለፍ ቃል',
         confirm:'አዲሱን የይለፍ ቃል ያረጋግጡ',
         r_len:'ቢያንስ 8 ቁምፊዎች', r_mix:'ፊደላትና ቁጥሮች ይካተቱ',
         r_same:'ሁለቱም አዲስ የይለፍ ቃሎች ይመሳሰሉ',
         save:'አስቀምጥና ቀጥል', saving:'በማስቀመጥ ላይ…', signout:'ውጣ',
         net:'ከሰርቨሩ ጋር መገናኘት አልተቻለም።' },
    en:{ title:'Choose a new password',
         sub:'Set your own password before continuing. You will only be asked once.',
         newpw:'New password',
         confirm:'Confirm new password',
         r_len:'At least 8 characters', r_mix:'Contains letters and numbers',
         r_same:'Both entries match',
         save:'Save & continue', saving:'Saving…', signout:'Sign out',
         net:'Could not reach the server.' }
  };
  let lang = localStorage.getItem('sr_lang_v2') || 'am';
  if (!T[lang]) lang = 'am';
  const $ = s => document.querySelector(s);

  function apply() {
    document.documentElement.lang = lang;
    document.cookie = 'sr_lang=' + lang + ';path=/;max-age=31536000;samesite=Lax';
    document.querySelectorAll('[data-t]').forEach(el => {
      const k = el.getAttribute('data-t'); if (T[lang][k]) el.textContent = T[lang][k];
    });
    document.querySelectorAll('[data-lang]').forEach(b => b.classList.toggle('on', b.dataset.lang === lang));
    $('#schoolName').textContent = SCHOOL[lang];
  }
  document.querySelectorAll('[data-lang]').forEach(b => b.addEventListener('click', () => {
    lang = b.dataset.lang; localStorage.setItem('sr_lang_v2', lang); apply(); check();
  }));

  function check() {
    const a = $('#np').value, b = $('#cp').value;
    const ok = {
      len:  a.length >= 8,
      mix:  /[A-Za-z\u1200-\u137F]/.test(a) && /\d/.test(a),
      same: a.length > 0 && a === b
    };
    document.querySelectorAll('[data-r]').forEach(li => li.classList.toggle('ok', ok[li.dataset.r]));
    const score = Object.values(ok).filter(Boolean).length;
    const bar = $('#bar');
    bar.style.width = Math.round((score / 3) * 100) + '%';
    bar.style.background = score < 2 ? '#DC2626' : score < 3 ? '#D97706' : '#16A34A';
    $('#go').disabled = !(ok.len && ok.same);
    return ok.len && ok.same;
  }
  ['np','cp'].forEach(id => $('#' + id).addEventListener('input', check));

  function showErr(m) { const e = $('#err'); e.textContent = m; e.classList.toggle('show', !!m); }

  $('#pwForm').addEventListener('submit', async e => {
    e.preventDefault(); showErr('');
    if (!check()) return;
    const btn = $('#go'); btn.disabled = true; btn.textContent = T[lang].saving;
    try {
      const r = await fetch('../api/auth.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':CSRF,'X-Requested-With':'fetch'},
        body: JSON.stringify({ action:'change_password', new_password: $('#np').value, confirm_password: $('#cp').value })
      });
      const j = await r.json().catch(() => null);
      if (!r.ok || !j || !j.ok) { showErr((j && j.error && j.error.message) || 'Could not save.'); }
      else { location.href = '../' + (NEXT || 'index.php'); return; }
    } catch (_) { showErr(T[lang].net); }
    btn.disabled = false; btn.textContent = T[lang].save;
  });

  apply(); check();
})();
</script>
</body>
</html>
