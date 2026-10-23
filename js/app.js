/* ============================================================
   St. Rufael Church School — Student Attendance Platform
   js/app.js · the application shell
   ------------------------------------------------------------
   Vanilla JS on the Bootstrap 5 grid. No build step, no
   framework, no CDN: vendor/bootstrap.min.css ships with the
   project so the platform works on a school LAN with no
   internet connection at all.

   Bilingual Amharic / English, Ge'ez numerals, and a real
   Ethiopian calendar (see lib/ethiopic.php for the server side
   and the register toolbar below).

   WHAT LIVES WHERE
     js/api.js     · fetch wrapper, CSRF, offline queue
     js/app.js     · this file — shell, router, dashboard,
                     attendance register, alerts, i18n, numerals
     js/admin.js   · the management pages, registered onto
                     window.SR_VIEWS and mounted by the router here
     js/features.js· feature on/off switches

   DATA
     Every byte comes from MySQL through api/. localStorage
     holds exactly three things: the chosen language, the
     numeral preference, and the offline attendance queue.

   READING ORDER
     Utilities → BOOT/capabilities → i18n (I18N) → numerals →
     ICON glyphs → register toolbar → views → router → nav →
     chrome bindings at the bottom.
   ============================================================ */
'use strict';

/* ------------------------------ Utilities ------------------------------ */
const $  = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
const esc = s => String(s === null || s === undefined ? '' : s)
  .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const BOOT = window.SR_BOOT || {};
const CAPS = BOOT.caps || {};
const can  = c => !!CAPS[c];
const FEAT = n => (window.FEATURES ? window.FEATURES[n] !== false : true);

const store = {
  get(k, fallback){ try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : fallback; } catch(e){ return fallback; } },
  set(k, v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch(e){} }
};
const LS = { LANG: 'sr_lang_v1', NUM: 'sr_numerals_v1' };

/* --------------------------- Ge'ez numerals ---------------------------- */
const GEEZ_1_12 = ['፩','፪','፫','፬','፭','፮','፯','፰','፱','፲','፲፩','፲፪'];
const geez = n => GEEZ_1_12[n - 1] || String(n);
const G_UNITS = ['','፩','፪','፫','፬','፭','፮','፯','፰','፱'];
const G_TENS  = ['','፲','፳','፴','፵','፶','፷','፸','፹','፺'];
function toGeez(n){
  n = Math.round(Number(n) || 0);
  if (n === 0) return '0';
  if (n < 0) return '-' + toGeez(-n);
  const pairs = [];
  while (n > 0){ pairs.push(n % 100); n = Math.floor(n / 100); }
  pairs.reverse();
  let out = '';
  const L = pairs.length;
  pairs.forEach((p, i) => {
    const pos = L - 1 - i;
    let s = G_TENS[Math.floor(p / 10)] + G_UNITS[p % 10];
    if (pos > 0){ if (p === 1) s = ''; out += s + (pos % 2 === 1 ? '፻' : '፼'); }
    else out += s;
  });
  return out;
}

/* -------------------- Ethiopian calendar (JDN based) -------------------
   Mirrors lib/ethiopic.php exactly, Pagume (month 13) included, with 5
   days in a common year and 6 in a leap year.                           */
const ET_MONTHS    = ['መስከረም','ጥቅምት','ኅዳር','ታህሣሥ','ጥር','የካቲት','መጋቢት','ሚያዝያ','ግንቦት','ሰኔ','ሐምሌ','ነሐሴ','ጳጉሜ'];
const ET_MONTHS_EN = ['Meskerem','Tikemet','Hidar','Tahsas','Tir','Yekatit','Megabit','Miyazya','Ginbot','Sene','Hamle','Nehase','Pagume'];
const ET_DAYS      = ['እሁድ','ሰኞ','ማክሰኞ','ረቡዕ','ሐሙስ','ዓርብ','ቅዳሜ'];
const ET_DAYS_EN   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const ET_EPOCH     = 1723856;   // JDN of Meskerem 1, year 1 (Amete Mihret)

const imod = (a, b) => ((a % b) + b) % b;
function gregToJdn(y, m, d){
  const a = Math.floor((14 - m) / 12), yy = y + 4800 - a, mm = m + 12 * a - 3;
  return d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4)
           - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
}
function jdnToGreg(jdn){
  let a = jdn + 32044, b = Math.floor((4 * a + 3) / 146097), c = a - Math.floor(146097 * b / 4);
  let d = Math.floor((4 * c + 3) / 1461), e = c - Math.floor(1461 * d / 4);
  let m = Math.floor((5 * e + 2) / 153);
  const day = e - Math.floor((153 * m + 2) / 5) + 1;
  const month = m + 3 - 12 * Math.floor(m / 10);
  const year = 100 * b + d - 4800 + Math.floor(m / 10);
  return { y: year, m: month, d: day };
}
function etToJdn(y, m, d){ return ET_EPOCH + 365 + 365 * (y - 1) + Math.floor(y / 4) + 30 * m + d - 31; }
function jdnToEt(jdn){
  const r = imod(jdn - ET_EPOCH, 1461);
  const n = imod(r, 365) + 365 * Math.floor(r / 1460);
  return {
    y: 4 * Math.floor((jdn - ET_EPOCH) / 1461) + Math.floor(r / 365) - Math.floor(r / 1460),
    m: Math.floor(n / 30) + 1,
    d: imod(n, 30) + 1
  };
}
const pad2 = n => String(n).padStart(2, '0');
function isoToEt(iso){
  const p = String(iso).split('-');
  return jdnToEt(gregToJdn(+p[0], +p[1], +p[2]));
}
function etToIso(y, m, d){
  const g = jdnToGreg(etToJdn(y, m, d));
  return g.y + '-' + pad2(g.m) + '-' + pad2(g.d);
}
const etIsLeap    = y => imod(y, 4) === 3;
const etMonthDays = (y, m) => m === 13 ? (etIsLeap(y) ? 6 : 5) : 30;
function isoDow(iso){ const p = String(iso).split('-'); return imod(gregToJdn(+p[0], +p[1], +p[2]) + 1, 7); } // 0 = Sunday
const isoDayKind = iso => { const w = isoDow(iso); return w === 6 ? 'sat' : w === 0 ? 'sun' : 'other'; };
/* Every Saturday and Sunday of one Ethiopian month — Pagume gives 5 or 6 days */
function monthWeekendCols(etY, etM){
  const cols = [];
  const days = etMonthDays(etY, etM);
  for (let d = 1; d <= days; d++){
    const iso = etToIso(etY, etM, d);
    const k = isoDayKind(iso);
    if (k === 'sat' || k === 'sun') cols.push({ etD: d, et_day: d, dow: k, kind: k, iso: iso, m: etM });
  }
  return cols;
}

/* ------------------------------ i18n ----------------------------------- */
const I18N = {
  am: {
    skip:'ወደ ዋናው ይዘት ዝለል',
    brand:'ቅዱስ ሩፋኤል የሰንበት ትምህርት ቤት', brandSub:'የተማሪ መገኘት አስተዳደር',
    navDashboard:'ዳሽቦርድ', navClasses:'ክፍሎች', navStudents:'ተማሪዎች', navAdd:'ተማሪ ጨምር',
    navAlerts:'ማንቂያዎች', navSettings:'ቅንብሮች', navImport:'ከፋይል አስገባ', navPromotion:'ክፍል ማሳደግ',
    navHistory:'የተማሪ ታሪክ',
    navCorrection:'የመገኘት ማስተካከያ',
    navMore:'ተጨማሪ', moreTitle:'ሁሉም ገጾች',
    navUsers:'ተጠቃሚዎች', navTeachers:'መምህራን', navAudit:'የሥራ መዝገብ', navBackup:'ምትኬ',
    notifTitle:'ማሳወቂያዎች', markAllRead:'ሁሉንም እንደተነባ ምልክት', viewAllAlerts:'ሁሉንም የመገኘት ማንቂያዎች እይ',
    allCaught:'ሁሉም ተነቧል — ምንም ያልተነባ ማንቂያ የለም!',
    absentTodayMsg:'ዛሬ አልመጣም',
    wTitle:'እንኳን ደህና መጡ!',
    searchPh:'ተማሪ፣ ክፍል ወይም ቀን ይፈልጉ…', searchAria:'ተማሪ፣ ክፍል ወይም ቀን ፍለጋ', searchClear:'አጽዳ', search:'ፍለጋ',
    clT:'ክፍሎች', clSub:'ቂርቆስ · ሚናስ · ክፍል ፩–፲፪', clOrder:'ቂርቆስ → ሚናስ → ፩ … ፲፪',
    sumT:'የመገኘት ማጠቃለያ',
    stTotal:'ጠቅላላ ተማሪዎች', stPresent:'ተገኝተዋል', stAbsent:'ጠፍተዋል', stLate:'በፈቃድ',
    stRate:'የመገኘት መጠን', stUnread:'ያልተነቡ ማንቂያዎች',
    alT:'የመገኘት ማንቂያዎች',
    alHead:'ተከታታይ የቀሩ ተማሪዎች', alEmpty:'ምንም ማንቂያ የለም — ሁሉም በሥርዓት ነው!',
    alViewAll:'ሁሉንም እይ', fAll:'ሁሉም', fUnread:'ያልተነቡ',
    markAllBtn:'ሁሉንም እንደተነባ ምልክት ✓',
    thStudent:'ተማሪ', thClass:'ክፍል', thDate:'ቀን', thStatus:'ሁኔታ', thLast:'የመጨረሻ መገኘት', thAction:'ድርጊት',
    thDays:'ተከታታይ ቀናት', thMessage:'መልእክት',
    markRead:'እንደተነባ ምልክት', readOk:'ተነቧል ✓', unreadChip:'ያልተነባ',
    present:'ተገኝቷል', absent:'ጠፍቷል', excused:'በፈቃድ', notMarked:'ያልተመዘገበ',
    attPresent:'መጥቷል', attLate:'በፈቃድ', attAbsent:'አልመጣ',
    srStudents:'ተማሪዎች', srClasses:'ክፍሎች', srAlerts:'የመገኘት ማንቂያዎች', srEmpty:'ምንም ውጤት አልተገኘም',
    lastAtt:'የመጨረሻ መገኘት',
    backDash:'‹ ዳሽቦርድ', backClasses:'‹ ክፍሎች',
    clsListSub:'ክፍሉን ይምረጡ።',
    stuSuffix:'ተማሪዎች',
    curYear:'የሚካሄድ', addYear:'ዓመት ጨምር',
    addYearToast:n=>'አዲስ ዓመት ታክሏል · ' + toGeez(n) + ' ዓ.ም',
    addStudent:'ተማሪ ጨምር', saved:'ተቀምጧል', asYear:'የምዝገባ ዓመት',
    nFnd:'ገጹ አልተገኘም', nFndSub:'የጠየቁት ገጽ አይገኝም።',
    noAccess:'ፈቃድ የለዎትም', noAccessSub:'ይህን ገጽ ለማየት ፈቃድ አልተሰጠዎትም።',
    pmId:'መለያ ቁጥር', pmRoll:'የክፍል ቁጥር', pmRollV:'ተማሪ ቁጥር', pmPhone:'ስልክ', pmGuardian:'ወላጅ',
    pmEnrolled:'የተመዘገበበት ዓ.ም', pmClass:'ክፍል', pmRate:'የመገኘት መጠን', pmBatches:'የወራት ቡድኖች',
    thGroup:'የወራት ቡድን', pmAttended:'ተገኝቷል', attTot:'ጠቅላላ', pmClose:'ዝጋ',
    pmViewClass:'የክፍል ሪፖርት ክፈት', pmRemove:'ተማሪውን ሰርዝ', pmCard:'መታወቂያ',
    /* profile v2.2 */
    pmOverview:'አጠቃላይ እይታ', pmDetails:'የተማሪው መረጃ', pmMonths:'በወር የመገኘት ሁኔታ',
    pmContact:'የመገኛ አድራሻ', pmNoContact:'የመገኛ መረጃ አልተመዘገበም።',
    pmPresentN:'የተገኘባቸው', pmAbsentN:'የጠፋባቸው', pmExcusedN:'በፈቃድ',
    pmOf:(a,b)=>`${langNum(a)} ከ${langNum(b)}`,
    pmStreak:n=>`${langNum(n)} ተከታታይ ቀናት ጠፍቷል`,
    pmNoMarks:'በዚህ ዓመት ገና የመገኘት መዝገብ የለም።',
    pmGuardPh:'የወላጅ ስልክ', pmAddress:'አድራሻ', pmBirth:'የልደት ቀን', pmStatus:'ሁኔታ',
    pmMore:'ተጨማሪ', pmYear:'ዓመት',
    removeConfirm:n=>`«${n}»ን ማሰረዝ ይፈልጋሉ? የመገኘት መዝገቡ ግን ተጠብቆ ይቆያል።`, removedToast:'ተማሪው ተሰርዟል',
    asT:'አዲስ ተማሪ መዝግብ', asSub:'ክፍሉን እና የምዝገባ ዓመቱን ይምረጡ — መለያ ቁጥሩ ራስ-ሰር ይሰጣል።',
    asName:'የተማሪ ሙሉ ስም', asNamePh:'ለምሳሌ ሐና ተስፋዬ', asPhone:'ስልክ (አማራጭ)', asPhonePh:'ለምሳሌ 0911 23 45 67',
    asGuardian:'ወላጅ (አማራጭ)', asGuardianPh:'ለምሳሌ አቶ ተስፋዬ ከበደ',
    asBaptism:'የክርስትና ስም', asBaptismPh:'ለምሳሌ ወልደ ሚካኤል', asGender:'ጾታ', asBirth:'የልደት ቀን',
    asGuardianPhone:'የወላጅ ስልክ', asAddress:'አድራሻ', asNotes:'ማስታወሻ',
    gMale:'ወንድ', gFemale:'ሴት',
    asClass:'ክፍል', asAutoId:'መለያ ቁጥር (ራስ-ሰር)', asAutoRoll:'የክፍል ቁጥር (ራስ-ሰር)',
    asSave:'አስመዝግብ', asCancel:'ሰርዝ', errName:'እባክዎ የተማሪ ስም ያስገቡ።',
    addedToast:n=>`ተማሪ ተመዝግቧል · ${n}`,
    setT:'ቅንብሮች', setSub:'',
    setSave:'ለውጦቹን አስቀምጥ', setSavedToast:'ለውጦቹ ተቀምጠዋል',
    pmEdit:'አስተካክል', sideMenu:'ምናሌ',
    langName:'አማርኛ', langTitle:'ቋንቋ · Language',
    notifAria:'ማሳወቂያዎች', langAria:'ቋንቋ ቀይር',
    loading:'እየተጫነ ነው…', errLoad:'መጫን አልተቻለም።', retry:'እንደገና ሞክር',
    /* register */
    regTools:'የመዝገብ መሣሪያዎች', markAll:'ሁሉንም ምልክት አድርግ',
    maPresent:'ሁሉም ተገኝቷል', maAbsent:'ሁሉም ጠፍቷል', maExcused:'ሁሉም በፈቃድ',
    copyWeek:'ያለፈውን ሳምንት ቅዳ', copyWeekAsk:'ያለፈው ሳምንት ምዝገባ ወደዚህ ቀን ይገለበጥ?',
    copyWeekNone:'ለመገልበጥ ምንም መዝገብ የለም።', copyWeekOk:n=>`${toGeez(n)} መዝገቦች ተገልብጠዋል`,
    overwriteAsk:n=>`${toGeez(n)} ቀደም ብለው የተመዘገቡ አሉ። ይተኩ?`,
    cellPresent:'ተገኝቷል', cellAbsent:'ጠፍቷል', cellExcused:'በፈቃድ (ምክንያት)', cellClear:'አጽዳ',
    reasonAsk:'የፈቃዱ ምክንያት:',
    /* sync */
    syncOnline:'መስመር ላይ', syncOffline:'ከመስመር ውጭ', syncPending:n=>`${toGeez(n)} በመጠባበቅ ላይ`,
    syncSyncing:'እየተመሳሰለ…', syncDone:'ተመሳስሏል', syncFail:'መመሳሰል አልተሳካም',
    /* account */
    accProfile:'መገለጫዬ', accPassword:'የይለፍ ቃል ቀይር', accLogout:'ውጣ',
    navPerms:'ፈቃዶች', prWithheld:'አልተሳደገም',
    numT:'የቁጥር አጻጻፍ', numMix:'ቅልቅል', numGeez:'ግዕዝ ብቻ', numArabic:'አረብኛ ብቻ',
    numMixEg:'ቀናት ፲፩ → 11 · ብዛት ፳፭', numGeezEg:'ቀናት ፲፩ · ብዛት ፳፭', numArabicEg:'ቀናት 11 · ብዛት 25',
    numHint:'በ«ቅልቅል» ላይ ቀኖችና ሰዓቶች በአረብኛ አኃዝ፣ ብዛትና ስታቲስቲክስ በግዕዝ ይጻፋሉ። ይህ ምርጫ የእርስዎ ብቻ ነው።',
    roleMaster:'ዋና አስተዳዳሪ', roleAdmin:'አስተዳዳሪ', roleTeacher:'መምህር',
    pwOld:'የአሁኑ የይለፍ ቃል', pwNew:'አዲስ የይለፍ ቃል', pwNew2:'አዲሱን ድገመው',
    pwMismatch:'የይለፍ ቃሎቹ አይመሳሰሉም።', pwSaved:'የይለፍ ቃል ተቀይሯል።',
    profSaved:'መገለጫው ተቀምጧል።', fullName:'ሙሉ ስም', email:'ኢሜይል', phone:'ስልክ',
    save:'አስቀምጥ', cancel:'ተወው', close:'ዝጋ', confirm:'አረጋግጥ', del:'ሰርዝ', edit:'አስተካክል',
    yes:'አዎ', no:'የለም', all:'ሁሉም', none:'ምንም',
    /* students page */
    stuT:'ተማሪዎች', stuSub:'', stuNew:'አዲስ ተማሪ',
    stuFilterGrade:'ክፍል', stuFilterStatus:'ሁኔታ', stuSearchPh:'በስም፣ በክርስትና ስም ወይም በመለያ ይፈልጉ…',
    stuEmpty:'ምንም ተማሪ አልተገኘም።', stuCount:n=>`${toGeez(n)} ተማሪዎች`,
    stActive:'ንቁ', stInactive:'ንቁ ያልሆነ', stGraduated:'የተመረቀ', stTransferred:'የተዛወረ',
    exportT:'አውጣ', exportCsv:'CSV', exportExcel:'Excel', exportJson:'JSON', exportPdf:'PDF (ማተሚያ)',
    idCard:'መታወቂያ ካርድ', printCards:'መታወቂያ አትም', tmplDownload:'ናሙና ፋይል አውርድ',
    /* import */
    impT:'ተማሪዎችን ከፋይል አስገባ', impSub:'',
    impPick:'ፋይል ይምረጡ', impUpload:'ስቀል እና አረጋግጥ', impPreview:'ቅድመ እይታ',
    impValid:'ትክክለኛ', impInvalid:'ስህተት ያለባቸው', impConfirm:'አስገባ', impDone:n=>`${toGeez(n)} ተማሪዎች ገብተዋል`,
    impRow:'ረድፍ', impErrors:'ስህተቶች', impSkipBad:'ስህተት ያለባቸውን ዝለል',
    /* promotion */
    prT:'ክፍል ማሳደግ', prSub:'',
    prFrom:'ከዓመት', prTo:'ወደ ዓመት', prPreview:'ቅድመ እይታ', prRun:'አሳድግ', prHistory:'ታሪክ',
    prPassed:'አልፏል', prFailed:'ወድቋል', prSkip:'ዝለል', prGraduate:'ተመርቋል',
    prDone:n=>`${toGeez(n)} ተማሪዎች ተሳድገዋል`, prUndo:'መልስ',
    /* users / teachers */
    usT:'ተጠቃሚዎች', usSub:'',
    usNew:'አዲስ ተጠቃሚ', usUsername:'የተጠቃሚ ስም', usRole:'ሚና', usActive:'ንቁ',
    usReset:'የይለፍ ቃል ዳግም አስጀምር', usPassword:'የይለፍ ቃል',
    /* generated / temporary credentials */
    cdTitle:'ጊዜያዊ የይለፍ ቃል', cdReady:'መለያው ተዘጋጅቷል',
    cdSub:'ይህ የይለፍ ቃል አንድ ጊዜ ብቻ ነው የሚታየው። ገልብጠው ለተጠቃሚው ይስጡት።',
    cdUser:'የተጠቃሚ ስም', cdPw:'ጊዜያዊ የይለፍ ቃል', cdCopy:'ገልብጥ', cdCopied:'ተገልብጧል',
    cdCopyBoth:'ሁለቱንም ገልብጥ', cdDone:'ጨርሻለሁ',
    cdNote:'ተጠቃሚው በዚህ የይለፍ ቃል ሲገባ ወዲያውኑ የራሱን አዲስ የይለፍ ቃል እንዲያስገባ ይጠየቃል።',
    cdResetSub:'ለዚህ መለያ አዲስ ጊዜያዊ የይለፍ ቃል ይፈጠራል። የድሮው ወዲያውኑ አይሠራም።',
    cdGen:'ጊዜያዊ የይለፍ ቃል ፍጠር', cdAuto:'የይለፍ ቃል በራስ-ሰር ይፈጠራል',
    cdAutoSub:'መለያው ሲፈጠር ጊዜያዊ የይለፍ ቃል ይሰጥዎታል።',
    ufOpts:'የመለያ አማራጮች', ufActiveSub:'ንቁ ያልሆነ መለያ መግባት አይችልም።',
    ufMustSub:'በመጀመሪያ መግቢያ የራሱን የይለፍ ቃል እንዲያስገባ ይገደዳል።',
    ufGradesSub:'መምህሩ የሚያያቸው ክፍሎች — ለመምህራን መለያ ብቻ።',
    ufAll:'ሁሉንም ምረጥ', ufNone:'ሁሉንም አጥፋ', ufPicked:n=>`${toGeez(n)} ተመርጠዋል`,
    tcT:'የመምህራን ምደባ', tcSub:'', tcAssign:'መድብ', tcClasses:'ክፍሎች',
    /* audit / backup */
    auT:'የሥራ መዝገብ', auSub:'',
    auUser:'ተጠቃሚ', auAction:'ድርጊት', auTarget:'ዒላማ', auWhen:'ጊዜ', auExport:'መዝገቡን አውጣ', auPurge:'አጽዳ',
    bkT:'ምትኬ እና መልሶ ማግኛ', bkSub:'',
    bkCreate:'ምትኬ ፍጠር', bkRestore:'መልስ', bkFile:'የምትኬ ፋይል', bkWarn:'መልሶ ማግኘት አሁን ያለውን ውሂብ ይተካል።'
  },
  en: {
    skip:'Skip to main content',
    brand:'St. Rufael Church School', brandSub:'Student Attendance Management',
    navDashboard:'Dashboard', navClasses:'Grades', navStudents:'Students', navAdd:'Add Student',
    navAlerts:'Alerts', navSettings:'Settings', navImport:'Import', navPromotion:'Promotion',
    navHistory:'Student history',
    navCorrection:'Attendance correction',
    navMore:'More', moreTitle:'All pages',
    navUsers:'Users', navTeachers:'Teachers', navAudit:'Audit Trail', navBackup:'Backup',
    notifTitle:'Notifications', markAllRead:'Mark all as read', viewAllAlerts:'View all attendance alerts',
    allCaught:'You’re all caught up — no unread alerts!',
    absentTodayMsg:'is absent today',
    wTitle:'Welcome back!',
    searchPh:'Search students, grades, or dates…', searchAria:'Search students, grades, or dates', searchClear:'Clear', search:'Search',
    clT:'Grades', clSub:'Kirkos · Minas · Grades 1–12', clOrder:'Kirkos → Minas → 1 … 12',
    sumT:'Attendance Summary',
    stTotal:'Total Students', stPresent:'Present', stAbsent:'Absent', stLate:'Excused',
    stRate:'Attendance Rate', stUnread:'Unread Alerts',
    alT:'Attendance Alerts',
    alHead:'Students missing repeatedly', alEmpty:'No alerts — everyone is on track!',
    alViewAll:'View all', fAll:'All', fUnread:'Unread',
    markAllBtn:'✓ Mark All as Read',
    thStudent:'Student', thClass:'Grade', thDate:'Date', thStatus:'Status', thLast:'Last Attendance', thAction:'Action',
    thDays:'Days in a row', thMessage:'Message',
    markRead:'Mark as Read', readOk:'Read ✓', unreadChip:'unread',
    present:'Present', absent:'Absent', excused:'Excused', notMarked:'Not Marked',
    attPresent:'Present', attLate:'Excused', attAbsent:'Absent',
    srStudents:'Students', srClasses:'Grades', srAlerts:'Attendance Alerts', srEmpty:'No results found',
    lastAtt:'Last attended',
    backDash:'‹ Dashboard', backClasses:'‹ Grades',
    clsListSub:'Choose a grade.',
    stuSuffix:'students',
    curYear:'Current', addYear:'Add year',
    addYearToast:n=>'New year added · ' + n,
    addStudent:'Add Student', saved:'Saved', asYear:'Enrollment year',
    nFnd:'Page not found', nFndSub:'The requested page does not exist.',
    noAccess:'Not permitted', noAccessSub:'Your account cannot open this page.',
    pmId:'ID Number', pmRoll:'Roll №', pmRollV:'Student №', pmPhone:'Phone', pmGuardian:'Guardian',
    pmEnrolled:'Enrolled', pmClass:'Grade', pmRate:'Attendance rate', pmBatches:'Month groups',
    thGroup:'Month group', pmAttended:'Attended', attTot:'Total', pmClose:'Close',
    pmViewClass:'Open grade report', pmRemove:'Remove student', pmCard:'ID card',
    /* profile v2.2 */
    pmOverview:'Overview', pmDetails:'Student details', pmMonths:'Attendance by month',
    pmContact:'Contact', pmNoContact:'No contact details on file.',
    pmPresentN:'Present', pmAbsentN:'Absent', pmExcusedN:'Excused',
    pmOf:(a,b)=>`${langNum(a)} of ${langNum(b)}`,
    pmStreak:n=>`${langNum(n)} days absent in a row`,
    pmNoMarks:'No attendance has been recorded this year yet.',
    pmGuardPh:'Guardian phone', pmAddress:'Address', pmBirth:'Date of birth', pmStatus:'Status',
    pmMore:'More', pmYear:'Year',
    removeConfirm:n=>`Remove “${n}”? Their attendance history is kept.`, removedToast:'Student removed',
    asT:'Register New Student', asSub:'Choose the grade and enrollment year — the ID is assigned automatically.',
    asName:'Full name', asNamePh:'e.g. Hana Tesfaye', asPhone:'Phone (optional)', asPhonePh:'e.g. 0911 23 45 67',
    asGuardian:'Guardian (optional)', asGuardianPh:'e.g. Ato Tesfaye Kebede',
    asBaptism:'Baptism name', asBaptismPh:'e.g. Welde Mikael', asGender:'Gender', asBirth:'Date of birth',
    asGuardianPhone:'Guardian phone', asAddress:'Address', asNotes:'Notes',
    gMale:'Male', gFemale:'Female',
    asClass:'Grade', asAutoId:'ID number (auto)', asAutoRoll:'Roll number (auto)',
    asSave:'Register', asCancel:'Cancel', errName:'Please enter the student’s name.',
    addedToast:n=>`Student registered · ${n}`,
    setT:'Settings', setSub:'',
    setSave:'Save changes', setSavedToast:'Changes saved',
    pmEdit:'Edit', sideMenu:'MENU',
    langName:'English', langTitle:'Language · ቋንቋ',
    notifAria:'Notifications', langAria:'Change language',
    loading:'Loading…', errLoad:'Could not load.', retry:'Try again',
    regTools:'Register tools', markAll:'Mark all',
    maPresent:'All present', maAbsent:'All absent', maExcused:'All excused',
    copyWeek:'Copy last week', copyWeekAsk:'Copy last week’s register into this date?',
    copyWeekNone:'Nothing to copy from last week.', copyWeekOk:n=>`${n} records copied`,
    overwriteAsk:n=>`${n} records already exist. Overwrite them?`,
    cellPresent:'Present', cellAbsent:'Absent', cellExcused:'Excused (reason)', cellClear:'Clear',
    reasonAsk:'Reason for the excuse:',
    syncOnline:'Online', syncOffline:'Offline', syncPending:n=>`${n} pending sync`,
    syncSyncing:'Syncing…', syncDone:'Synced', syncFail:'Sync failed',
    accProfile:'My profile', accPassword:'Change password', accLogout:'Sign out',
    navPerms:'Roles & access', prWithheld:'Not promoted',
    numT:'Numerals', numMix:'Mixed', numGeez:'Ge\u2019ez only', numArabic:'Arabic only',
    numMixEg:'Dates 11 \u00b7 counts \u1372\u1373', numGeezEg:'Dates \u1373\u1371 \u00b7 counts \u1372\u1373', numArabicEg:'Dates 11 \u00b7 counts 25',
    numHint:'With “Mixed”, dates and times use Arabic numerals while counts and statistics use Ge\u2019ez. This setting applies to your account only.',
    roleMaster:'Master', roleAdmin:'Admin', roleTeacher:'Teacher',
    pwOld:'Current password', pwNew:'New password', pwNew2:'Repeat new password',
    pwMismatch:'The passwords do not match.', pwSaved:'Password changed.',
    profSaved:'Profile saved.', fullName:'Full name', email:'Email', phone:'Phone',
    save:'Save', cancel:'Cancel', close:'Close', confirm:'Confirm', del:'Delete', edit:'Edit',
    yes:'Yes', no:'No', all:'All', none:'None',
    stuT:'Students', stuSub:'', stuNew:'New student',
    stuFilterGrade:'Grade', stuFilterStatus:'Status', stuSearchPh:'Search by name, baptism name or ID…',
    stuEmpty:'No students found.', stuCount:n=>`${n} students`,
    stActive:'Active', stInactive:'Inactive', stGraduated:'Graduated', stTransferred:'Transferred',
    exportT:'Export', exportCsv:'CSV', exportExcel:'Excel', exportJson:'JSON', exportPdf:'PDF (print)',
    idCard:'ID card', printCards:'Print ID cards', tmplDownload:'Download template',
    impT:'Import students', impSub:'',
    impPick:'Choose file', impUpload:'Upload & validate', impPreview:'Preview',
    impValid:'Valid', impInvalid:'With errors', impConfirm:'Import', impDone:n=>`${n} students imported`,
    impRow:'Row', impErrors:'Errors', impSkipBad:'Skip invalid rows',
    prT:'Promotion', prSub:'',
    prFrom:'From year', prTo:'To year', prPreview:'Preview', prRun:'Promote', prHistory:'History',
    prPassed:'Passed', prFailed:'Failed', prSkip:'Skip', prGraduate:'Graduated',
    prDone:n=>`${n} students promoted`, prUndo:'Undo',
    usT:'Users', usSub:'',
    usNew:'New user', usUsername:'Username', usRole:'Role', usActive:'Active',
    usReset:'Reset password', usPassword:'Password',
    cdTitle:'Temporary password', cdReady:'Account ready',
    cdSub:'This password is shown once. Copy it and hand it to the user.',
    cdUser:'Username', cdPw:'Temporary password', cdCopy:'Copy', cdCopied:'Copied',
    cdCopyBoth:'Copy both', cdDone:'Done',
    cdNote:'When they sign in with this password the app immediately asks them to choose their own.',
    cdResetSub:'A fresh temporary password will be generated for this account. The old one stops working right away.',
    cdGen:'Generate temporary password', cdAuto:'Password is generated automatically',
    cdAutoSub:'You will be given a temporary password as soon as the account is created.',
    ufOpts:'Account options', ufActiveSub:'An inactive account cannot sign in.',
    ufMustSub:'Forces them to pick their own password at first sign-in.',
    ufGradesSub:'The grades this teacher can see — teacher accounts only.',
    ufAll:'Select all', ufNone:'Clear all', ufPicked:n=>`${n} selected`,
    tcT:'Teacher assignments', tcSub:'', tcAssign:'Assign', tcClasses:'Grades',
    auT:'Audit trail', auSub:'',
    auUser:'User', auAction:'Action', auTarget:'Target', auWhen:'When', auExport:'Export log', auPurge:'Purge',
    bkT:'Backup & restore', bkSub:'',
    bkCreate:'Create backup', bkRestore:'Restore', bkFile:'Backup file', bkWarn:'Restoring replaces the current data.'
  }
};
let LANG = store.get(LS.LANG, BOOT.lang || 'am');
if (LANG !== 'am' && LANG !== 'en') LANG = 'am';
const t = k => I18N[LANG][k] !== undefined ? I18N[LANG][k] : (I18N.en[k] !== undefined ? I18N.en[k] : k);
/* Amharic / English pair helper — same convention as the admin views. */
function L(am, en){ return LANG === 'am' ? am : en; }

/* ------------------------------ Constants ------------------------------ */
const STATUS_KEYS = ['present', 'absent', 'excused'];
const statusLabel = st => t(st === 'notMarked' || !st ? 'notMarked' : st);
/* ---------------------------------------------------------------------
   Numeral preference (per user, item 10)
     geez   — Ge'ez everywhere
     arabic — Arabic everywhere
     mix    — counts and statistics in Ge'ez, dates/times/phone in Arabic
   English always uses Arabic; the preference only shapes the Amharic UI.
   --------------------------------------------------------------------- */
const NUM_MODES = ['geez', 'arabic', 'mix'];
let NUMERALS = store.get(LS.NUM, (BOOT.user && BOOT.user.numerals) || 'mix');
if (NUM_MODES.indexOf(NUMERALS) === -1) NUMERALS = 'mix';

/* counts, totals, percentages, roll numbers */
const langNum = n => (LANG === 'am' && NUMERALS !== 'arabic') ? toGeez(n) : String(n);
/* day / month / year numbers inside a date, and clock-adjacent figures */
const dateNum = n => (LANG === 'am' && NUMERALS === 'geez') ? toGeez(n) : String(n);
const G_DIGITS = ['0','፩','፪','፫','፬','፭','፮','፯','፰','፱'];
const geezDigits = s => String(s).replace(/\d/g, d => G_DIGITS[+d]);
/* literal digit strings (phone numbers, codes) — never Ge'ez in mix mode */
const digitsNum = s => (LANG === 'am' && NUMERALS === 'geez') ? geezDigits(s) : String(s);
window.srNumerals = () => NUMERALS;
window.srDateNum = n => dateNum(n);
window.srDigits  = s => digitsNum(s);
const yrText = y => LANG === 'am' ? dateNum(y) + ' ዓ.ም' : String(y);
const enrolledText = y => yrText(y);
const rollTxt = n => t('pmRollV') + ' ' + langNum(n);

/* ------------------------- Server-backed state -------------------------- */
const DATA = {
  user   : BOOT.user || {},
  year   : BOOT.year || null,          // {id, year_ec, label, ...}
  years  : [],
  grades : [],                          // [{id, code, name_am, ...}]
  gradeById : {},
  gradeByCode : {},
  settings : BOOT.settings || {},
  alerts : [],
  alertsUnread : 0,
  loaded : false
};

const NOW_D = new Date();
const isoOf = d => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
const TODAY_ISO = isoOf(NOW_D);
const ET_TODAY_R = isoToEt(TODAY_ISO);
const ET_TODAY = { year: ET_TODAY_R.y, month: ET_TODAY_R.m, day: ET_TODAY_R.d };

const state = {
  search:'', openYear: (BOOT.year && +BOOT.year.year_ec) || ET_TODAY.year,
  grpTab:{}, modal:null, pendingProfile:null, alertFilter:'all',
  regDate:null, busy:false
};

/* Month groups — the fourth one carries ጳጉሜ / Pagume as a real period */
const ATT_MONTH_GROUPS = [ [1,2,3], [4,5,6], [7,8,9], [10,11,12,13] ];
const gTitle = g => {
  const M = LANG === 'am' ? ET_MONTHS : ET_MONTHS_EN;
  return M[g[0]-1] + ' – ' + M[g[g.length-1]-1];
};

/* ---------------------------- Class helpers ----------------------------- */
/* A "cls" keeps the shape the original renderers expect: {id, num, ord, g, students} */
function gradeToCls(g, ord){
  return {
    id: String(g.id),
    code: g.code,
    num: g.level_no || 0,
    ord: ord,
    count: g.student_count || 0,
    teachers: g.teachers || [],
    students: [],
    g: {
      id: String(g.id), code: g.code, num: g.level_no || 0,
      am: g.name_am, en: g.name_en,
      badge: g.badge_am || g.name_am.slice(0,1), badgeEn: g.badge_en || g.name_en.slice(0,1),
      tone: g.tone === 'gold' ? '' : (g.tone || ''),
      saintAm: g.saint_am || '', saintEn: g.saint_en || ''
    }
  };
}
let CLASSES = [], CLASS_BY_ID = {};
function setGrades(grades){
  DATA.grades = grades;
  DATA.gradeById = {}; DATA.gradeByCode = {};
  grades.forEach(g => { DATA.gradeById[g.id] = g; DATA.gradeByCode[g.code] = g; });
  CLASSES = grades.map(gradeToCls);
  CLASS_BY_ID = Object.fromEntries(CLASSES.map(c => [c.id, c]));
}
const classLabel = cls => LANG === 'am' ? cls.g.am : cls.g.en;
function classLabelLong(cls){
  const g = cls.g;
  if (!g.saintAm && !g.saintEn) return classLabel(cls);
  return LANG === 'am' ? (g.saintAm ? `የ${g.saintAm} ክፍል` : g.am) : (g.saintEn ? `${g.saintEn} — ${g.en}` : g.en);
}
const geezOf = g => LANG === 'am' ? g.badge : g.badgeEn;
const classHref = cls => '#/grades/' + cls.id;

/* ------------------------------ Date labels ----------------------------- */
const todayLbl = () => LANG === 'am'
  ? ET_DAYS[isoDow(TODAY_ISO)] + ' ' + dateNum(ET_TODAY.day) + ' ' + ET_MONTHS[ET_TODAY.month-1] + ' ' + dateNum(ET_TODAY.year) + ' ዓ.ም'
  : NOW_D.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric', year:'numeric' });
function isoLabel(iso, withDow){
  if (!iso) return '—';
  const et = isoToEt(iso);
  const w = isoDow(iso);
  if (LANG === 'am')
    return (withDow ? ET_DAYS[w] + ' ' : '') + dateNum(et.d) + ' ' + ET_MONTHS[et.m-1] + ' ' + dateNum(et.y) + ' ዓ.ም';
  return (withDow ? ET_DAYS_EN[w] + ', ' : '') + ET_MONTHS_EN[et.m-1] + ' ' + et.d + ', ' + et.y;
}
const dLbl = iso => iso ? isoLabel(iso, false) : '—';

/* ------------------------------- Helpers -------------------------------- */
function initials(name){
  const p = String(name || '?').trim().split(/\s+/);
  return ((p[0] ? p[0][0] : '?') + (p[1] ? p[1][0] : '')).toUpperCase();
}
function toast(msg, kind){
  const box = $('#toasts'); if (!box) return;
  const el = document.createElement('div');
  el.className = 'toast' + (kind === 'error' ? ' toast-error' : '');
  el.innerHTML = '<span class="t-ic">' + (kind === 'error' ? '!' : '✓') + '</span><span>' + esc(msg) + '</span>';
  box.appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, kind === 'error' ? 4200 : 2700);
}
/* Amharic wording for the error types the API returns */
const ERR_AM = {
  bad_credentials:'የተጠቃሚ ስም ወይም የይለፍ ቃል ትክክል አይደለም።',
  unauthenticated:'ክፍለ ጊዜው አልቋል። እባክዎ እንደገና ይግቡ።',
  forbidden:'ይህን ለማድረግ ፈቃድ የለዎትም።',
  forbidden_grade:'ይህ ክፍል ለእርስዎ አልተመደበም።',
  forbidden_student:'ይህ ተማሪ ለእርስዎ አልተመደበም።',
  validation:'የገቡት መረጃ ትክክል አይደለም።',
  duplicate:'ይህ መረጃ አስቀድሞ አለ።',
  not_found:'አልተገኘም።',
  csrf:'የደኅንነት ማረጋገጫ አልተሳካም። ገጹን ያድሱ።',
  too_large:'ፋይሉ ወይም ጥያቄው በጣም ትልቅ ነው።',
  network:'አገልጋዩ ጋር መገናኘት አልተቻለም።',
  server:'የአገልጋይ ችግር ተከስቷል።'
};
function errMsg(err){
  if (!err) return t('errLoad');
  if (LANG === 'am' && ERR_AM[err.type]) return ERR_AM[err.type];
  return err.message || t('errLoad');
}
function fail(err){ console.warn('[SR]', err); toast(errMsg(err), 'error'); }

let savePillT = null;
function indicateSaved(){
  const el = $('#savePill'); if (!el) return;
  el.classList.add('on');
  clearTimeout(savePillT);
  savePillT = setTimeout(() => el.classList.remove('on'), 1100);
}

/* ------------------------------- Icons ---------------------------------- */
const ICON = {
  users:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.4"/><path d="M3.5 20c.7-3.1 2.8-4.8 5.5-4.8s4.8 1.7 5.5 4.8"/><circle cx="16.8" cy="9" r="2.5"/><path d="M15.6 15.2c2.5.3 4.4 1.8 5 4"/></svg>',
  check:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 12.5 5 5 10-11"/></svg>',
  userx:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="7.5" r="3.2"/><path d="M4 20.5c.7-4.2 3-6.5 6-6.5 1.4 0 2.7.5 3.7 1.4"/><path d="m15.5 15.5 5 5M20.5 15.5l-5 5"/></svg>',
  clock:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  target:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>',
  bell:'<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
  search:'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.4-4.4"/></svg>',
  grid:'<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/></svg>',
  home:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/></svg>',
  cal:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>',
  warn:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.5 20h19z"/><path d="M12 9.5v4.5M12 17.2v.3"/></svg>',
  plus:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
  back:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>',
  gear:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87 1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z"/></svg>',
  uplus:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="7.5" r="3.2"/><path d="M4 20.5c.7-4.2 3-6.5 6-6.5 1.4 0 2.7.5 3.7 1.4"/><path d="M18 14.5v5M15.5 17h5"/></svg>',
  upload:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>',
  up:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19h14"/><path d="M12 16V5"/><path d="m7 10 5-5 5 5"/></svg>',
  shield:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7.5 3v6c0 4.6-3.1 8.2-7.5 9.4C7.6 20.2 4.5 16.6 4.5 12V6z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>',
  list:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>',
  /* "More" — a 3x3 dot grid, the usual phone idiom for "everything else". */
  menu:'<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><circle cx="5" cy="5" r="1.7"/><circle cx="12" cy="5" r="1.7"/><circle cx="19" cy="5" r="1.7"/><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/><circle cx="5" cy="19" r="1.7"/><circle cx="12" cy="19" r="1.7"/><circle cx="19" cy="19" r="1.7"/></svg>',
  db:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5.5" rx="7.5" ry="3"/><path d="M4.5 5.5v13c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-13"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/></svg>',
  card:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5 16.4c.6-1.5 1.9-2.3 3.5-2.3s2.9.8 3.5 2.3"/><path d="M15 10h4M15 13.5h4"/></svg>',
  down:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 20h14"/></svg>',
  key:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4.2"/><path d="m11 12 8.5-8.5"/><path d="m17 6 2.3 2.3"/><path d="m14.4 8.6 2.3 2.3"/></svg>',
  copy:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5V5a2 2 0 0 1 2-2h8.5A1.5 1.5 0 0 1 15 4.5V5"/></svg>',
  pencil:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m14.5 6 3 3"/></svg>',
  phone:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 3h3l1.5 4-2 1.5a12 12 0 0 0 5.5 5.5L16 12l4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 3.5 5.2 2 2 0 0 1 5.5 3z"/></svg>',
  trash:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9.5 7V4.8A.8.8 0 0 1 10.3 4h3.4a.8.8 0 0 1 .8.8V7"/><path d="M6.5 7 7.4 20a1 1 0 0 0 1 .9h7.2a1 1 0 0 0 1-.9L17.5 7"/><path d="M10.5 11v6M13.5 11v6"/></svg>',
  info:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/></svg>',
  camera:'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h2.2l1.2-2h8.2l1.2 2h2.2A1.5 1.5 0 0 1 21 8.5v9A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/><circle cx="12" cy="13" r="3.6"/></svg>'
};

/* ====================================================================== */
/*  Shared page furniture                                                  */
/* ====================================================================== */
function welcomeSection(){
  const name = DATA.user.full_name || '';
  return `
  <section class="hello" id="sec-welcome">
    <div class="hello-txt">
      <h1>${t('wTitle')}${name ? ` <span class="hello-name">${esc(name.split(' ')[0])}</span>` : ''}</h1>
      <span class="date-chip">${ICON.cal}${todayLbl()}</span>
    </div>
    <img class="hello-logo" src="assets/logo.png" alt="${esc(t('brand'))}">
  </section>`;
}
function searchSection(){
  return `
  <section class="search-hero">
    <div class="search-box">
      ${ICON.search}
      <input id="globalSearch" type="search" placeholder="${t('searchPh')}" aria-label="${t('searchAria')}" autocomplete="off" value="${esc(state.search)}">
      <button class="mini-btn search-clear" id="searchClear" type="button" ${state.search ? '' : 'hidden'}>${t('searchClear')}</button>
    </div>
    <div class="search-results" id="searchResults" hidden></div>
  </section>`;
}
function classesEntrySection(){
  return `
  <section id="sec-classesentry">
    <a class="go-grades" href="#/grades">
      <span class="gg-ic" aria-hidden="true">${ICON.grid}</span>
      <span class="gg-txt"><strong>${t('clT')}</strong><span>${t('clSub')}</span></span>
      <span class="gg-arrow" aria-hidden="true">→</span>
    </a>
  </section>`;
}
const loadingHtml = () => `<div class="load-wrap"><span class="spin" aria-hidden="true"></span><span>${t('loading')}</span></div>`;
function errorHtml(err){
  return `<div class="card card-pad text-center nf-card">
    <h2 class="nf-h">${t('errLoad')}</h2>
    <p class="text-muted nf-sub">${esc(errMsg(err))}</p>
    <button class="btn btn-primary" id="retryBtn" type="button">${t('retry')}</button>
  </div>`;
}
function renderNotFound(){
  return `<div class="card card-pad text-center nf-card">
    <h2 class="nf-h">${t('nFnd')}</h2>
    <p class="text-muted nf-sub">${t('nFndSub')}</p>
    <a class="btn btn-primary" href="#/grades">${t('backClasses').replace('‹ ','')}</a>
  </div>`;
}
function renderNoAccess(){
  return `<div class="card card-pad text-center nf-card">
    <h2 class="nf-h">${t('noAccess')}</h2>
    <p class="text-muted nf-sub">${t('noAccessSub')}</p>
    <a class="btn btn-primary" href="#/dashboard">${t('navDashboard')}</a>
  </div>`;
}

/* ====================================================================== */
/*  DASHBOARD                                                              */
/* ====================================================================== */
async function renderDashboard(){
  const [stats, al] = await Promise.all([
    API.attendance.stats({ }),
    FEAT('attendanceAlerts') ? API.attendance.alerts({ lang: LANG }).catch(() => ({ alerts: [], unread: 0 })) : Promise.resolve({ alerts: [], unread: 0 })
  ]);
  DATA.alerts = al.alerts || [];
  DATA.alertsUnread = al.unread || 0;
  updateBell();

  const card = (icon, cls, val, lbl, sub) => `
    <div class="col-6 col-md-4 col-xl">
      <div class="stat-card h-100">
        <span class="stat-ic ${cls}">${icon}</span>
        <span class="stat-txt"><span class="stat-val">${val}</span><span class="stat-lbl">${lbl}</span>${sub ? `<span class="stat-sub">${sub}</span>` : ''}</span>
      </div>
    </div>`;
  const rate = LANG === 'am' ? toGeez(Math.round(stats.rate)) : stats.rate;
  const summary = `
  <section id="sec-summary">
    <div class="sec-head"><h2>${t('sumT')}</h2><p>${yrText(stats.et_year)}${stats.scoped ? ' · ' + t('tcClasses') : ''}</p></div>
    <div class="row g-2 g-md-3 summary-row">
      ${card(ICON.users, 'blue',  langNum(stats.students), t('stTotal'),   '')}
      ${card(ICON.check, 'green', langNum(stats.present),  t('stPresent'), '')}
      ${card(ICON.userx, 'red',   langNum(stats.absent),   t('stAbsent'),  '')}
      ${card(ICON.clock, 'amber', langNum(stats.excused),  t('stLate'),    '')}
      ${card(ICON.target,'gold',  rate + '%', t('stRate'), langNum(stats.present) + ' / ' + langNum(stats.marked))}
      ${card(ICON.bell,  'blue',  langNum(DATA.alertsUnread), t('stUnread'), '')}
    </div>
  </section>`;

  /* ---------------------------------------------------------------
     Charts. Everything below is hand-built inline SVG: there is no
     charting library on this project (no Composer, no CDN — see the
     Bootstrap rule), and a dependency would dwarf the four shapes we
     actually need. Every figure comes from attendance.stats, which
     joins `s.deleted_at IS NULL` and reads live rows, so corrections
     and soft deletes are already reflected.
     --------------------------------------------------------------- */

  /* Donut: the present/absent/excused split, drawn with stroke-dasharray
     on three arcs of one circle. r=54 keeps the maths readable. */
  const donut = (() => {
    const marked = stats.marked || 0;
    if (!marked) return '';
    const R = 54, C = 2 * Math.PI * R;
    const seg = [
      { v: stats.present, cls: 'g', lbl: t('stPresent') },
      { v: stats.absent,  cls: 'r', lbl: t('stAbsent')  },
      { v: stats.excused, cls: 'a', lbl: t('stLate')    },
    ];
    let off = 0;
    const arcs = seg.map(x => {
      const frac = x.v / marked, len = frac * C;
      const a = `<circle class="dn-arc ${x.cls}" cx="70" cy="70" r="${R}"
                   stroke-dasharray="${len.toFixed(2)} ${(C - len).toFixed(2)}"
                   stroke-dashoffset="${(-off).toFixed(2)}"/>`;
      off += len;
      return a;
    }).join('');
    const keys = seg.map(x => `
      <li><i class="dn-k ${x.cls}"></i><span>${x.lbl}</span>
          <b>${langNum(x.v)}</b>
          <em>${langNum(Math.round((x.v / marked) * 100))}%</em></li>`).join('');
    return `
      <div class="ch-card">
        <div class="ch-h"><h3>${LANG === 'am' ? 'የመገኘት ስብጥር' : 'Attendance split'}</h3>
          <span class="ch-sub">${langNum(marked)} ${LANG === 'am' ? 'መዝገቦች' : 'records'}</span></div>
        <div class="dn-wrap">
          <svg class="dn" viewBox="0 0 140 140" role="img"
               aria-label="${esc(t('stPresent'))} ${langNum(stats.present)}, ${esc(t('stAbsent'))} ${langNum(stats.absent)}, ${esc(t('stLate'))} ${langNum(stats.excused)}">
            <circle class="dn-bg" cx="70" cy="70" r="${R}"/>
            ${arcs}
          </svg>
          <div class="dn-mid"><b>${rate}%</b><span>${t('stRate')}</span></div>
        </div>
        <ul class="dn-keys">${keys}</ul>
      </div>`;
  })();

  /* Trend: attendance rate per Ethiopian month as a filled area + line.
     Months with nothing recorded are dropped so an empty tail does not
     drag the line to zero. */
  const trend = (() => {
    const ms = (stats.by_month || []).filter(m => m.total > 0);
    if (ms.length < 2) return '';
    const W = 640, H = 190, PL = 34, PR = 12, PT = 14, PB = 26;
    const iw = W - PL - PR, ih = H - PT - PB;
    const x = i => PL + (ms.length === 1 ? iw / 2 : (i * iw) / (ms.length - 1));
    const y = v => PT + ih - (Math.max(0, Math.min(100, v)) / 100) * ih;
    const pts = ms.map((m, i) => [x(i), y(m.rate)]);
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
    const area = `${line} L${pts[pts.length - 1][0].toFixed(1)},${(PT + ih).toFixed(1)} L${pts[0][0].toFixed(1)},${(PT + ih).toFixed(1)} Z`;
    const grid = [0, 25, 50, 75, 100].map(v => `
      <line class="tr-grid" x1="${PL}" x2="${W - PR}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}"/>
      <text class="tr-ax" x="${PL - 7}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${langNum(v)}</text>`).join('');
    const dots = pts.map((p, i) => `
      <circle class="tr-dot" cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3.4">
        <title>${esc(LANG === 'am' ? ms[i].name_am : ms[i].name_en)}: ${langNum(Math.round(ms[i].rate))}%</title>
      </circle>`).join('');
    /* Label every month when they fit, every other one when they do not. */
    const step = ms.length > 8 ? 2 : 1;
    const labs = ms.map((m, i) => (i % step) ? '' : `
      <text class="tr-ax" x="${x(i).toFixed(1)}" y="${H - 7}" text-anchor="middle">${esc((LANG === 'am' ? m.name_am : m.name_en).slice(0, 3))}</text>`).join('');
    return `
      <div class="ch-card wide">
        <div class="ch-h"><h3>${LANG === 'am' ? 'የመገኘት አዝማሚያ' : 'Attendance trend'}</h3>
          <span class="ch-sub">${LANG === 'am' ? 'በወር፣ በመቶኛ' : 'Rate by month'}</span></div>
        <svg class="tr" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img"
             aria-label="${LANG === 'am' ? 'የወርሃዊ የመገኘት አዝማሚያ' : 'Monthly attendance rate trend'}">
          <defs><linearGradient id="trFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--blue-500)" stop-opacity=".26"/>
            <stop offset="100%" stop-color="var(--blue-500)" stop-opacity="0"/>
          </linearGradient></defs>
          ${grid}
          <path class="tr-area" d="${area}"/>
          <path class="tr-line" d="${line}"/>
          ${dots}${labs}
        </svg>
      </div>`;
  })();

  /* Saturday vs Sunday: the two session days this school runs. */
  const dayCmp = (() => {
    const bd = stats.by_day || {};
    const rows = [['sat', LANG === 'am' ? 'ቅዳሜ' : 'Saturday'],
                  ['sun', LANG === 'am' ? 'እሁድ'  : 'Sunday']]
      .map(([k, lbl]) => {
        const d = bd[k] || {}; const tot = (d.present || 0) + (d.absent || 0) + (d.excused || 0);
        if (!tot) return '';
        const pc = Math.round(((d.present || 0) / tot) * 100);
        return `
          <div class="dc-row">
            <span class="dc-l">${lbl}</span>
            <span class="dc-t"><i style="width:${pc}%"></i></span>
            <span class="dc-v">${langNum(pc)}%</span>
          </div>`;
      }).join('');
    if (!rows) return '';
    return `
      <div class="ch-card">
        <div class="ch-h"><h3>${LANG === 'am' ? 'በቀን' : 'By session day'}</h3>
          <span class="ch-sub">${LANG === 'am' ? 'የመገኘት መጠን' : 'Attendance rate'}</span></div>
        <div class="dc">${rows}</div>
      </div>`;
  })();

  const charts = (donut || trend || dayCmp) ? `
  <section id="sec-charts">
    <div class="ch-grid">${donut}${dayCmp}${trend}</div>
  </section>` : '';

  return `
    ${welcomeSection()}
    ${searchSection()}
    ${classesEntrySection()}
    ${summary}
    ${charts}
    ${FEAT('attendanceAlerts') ? alertsSection(DATA.alerts) : ''}`;
}

/* ------------------------------- Alerts --------------------------------- */
function alertsTableHtml(alerts){
  if (!alerts.length) return `<div class="card alerts-empty">${t('alEmpty')}</div>`;
  const rows = alerts.map(a => {
    const q = (a.full_name + ' ' + a.grade + ' ' + a.last_date).toLowerCase();
    return `
    <tr class="alert-row ${a.read ? 'read' : 'unread'}${a.severity === 'high' ? ' sev-high' : ''}" data-q="${esc(q)}"
        data-profile="${esc(a.grade_id)}|${esc(a.student_id)}" role="button" tabindex="0" aria-label="${esc(a.full_name)}">
      <td><div class="stu-cell"><span class="stu-av">${esc(initials(a.full_name))}</span><span class="d-inline-block"><span class="nm">${esc(a.full_name)}</span><span class="id d-block">${esc(a.student_code)}</span></span></div></td>
      <td><strong class="d-inline-block me-1">${esc(a.grade)}</strong></td>
      <td>${dLbl(a.last_date)}</td>
      <td><span class="pill absent">${langNum(a.days)} ${LANG === 'am' ? 'ቀናት' : 'days'}</span></td>
      <td class="al-msg">${esc(a.message)}</td>
      <td>${a.read ? `<span class="badge-check">✓ ${t('readOk').replace(' ✓','')}</span>`
                   : `<button class="mini-btn mark-read" data-alert="${esc(a.key)}" type="button">${t('markRead')}</button>`}</td>
    </tr>`;
  }).join('');
  return `
  <div class="table-responsive card tbl-card"><table class="table table-hover align-middle data mb-0">
    <thead><tr>
      <th>${t('thStudent')}</th><th>${t('thClass')}</th><th>${t('thLast')}</th>
      <th>${t('thDays')}</th><th>${t('thMessage')}</th><th>${t('thAction')}</th>
    </tr></thead>
    <tbody>${rows}</tbody>
  </table></div>`;
}
function alertsSection(alerts){
  const unread = alerts.filter(a => !a.read).length;
  return `
  <section id="sec-alerts">
    <div class="sec-head"><h2>${t('alT')}</h2></div>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <h3 class="alerts-title h6 mb-0">${t('alHead')}<span class="count-chip">${langNum(unread)} ${t('unreadChip')}</span></h3>
      <div class="d-flex align-items-center gap-2">
        ${alerts.length > 5 ? `<a class="mini-btn al-viewall" href="#/alerts">${t('alViewAll')} (${langNum(alerts.length)}) →</a>` : ''}
        <button class="btn btn-soft" id="markAllTableBtn" type="button" ${unread ? '' : 'disabled'}>${t('markAllBtn')}</button>
      </div>
    </div>
    ${alertsTableHtml(alerts.slice(0, 5))}
  </section>`;
}
async function renderAlertsPage(){
  const al = await API.attendance.alerts({ lang: LANG });
  DATA.alerts = al.alerts || [];
  DATA.alertsUnread = al.unread || 0;
  updateBell();
  const alerts = DATA.alerts;
  const unread = alerts.filter(a => !a.read).length;
  const list = state.alertFilter === 'unread' ? alerts.filter(a => !a.read) : alerts;
  const thr = LANG === 'am' ? toGeez(al.threshold) : al.threshold;
  return `
    <div class="sec-head first"><h2>${t('alT')}</h2><p>${t('alHead')} — ${LANG === 'am' ? `ከ${thr} ቀናት በላይ` : `${thr}+ days in a row`}</p></div>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
      <div class="grp-tabs mb-0" role="tablist">
        <button class="grp-tab al-filter ${state.alertFilter === 'all' ? 'active' : ''}" data-f="all" type="button">${t('fAll')} · ${langNum(alerts.length)}</button>
        <button class="grp-tab al-filter ${state.alertFilter === 'unread' ? 'active' : ''}" data-f="unread" type="button">${t('fUnread')} · ${langNum(unread)}</button>
      </div>
      <button class="btn btn-soft" id="markAllTableBtn" type="button" ${unread ? '' : 'disabled'}>${t('markAllBtn')}</button>
    </div>
    ${alertsTableHtml(list)}`;
}
async function markRead(key){
  try { await API.attendance.markAlertRead(key); } catch (e) { return fail(e); }
  const a = DATA.alerts.find(x => x.key === key);
  if (a) a.read = true;
  DATA.alertsUnread = DATA.alerts.filter(x => !x.read).length;
  updateBell();
  refresh(true);
  const panel = $('#notifPanel');
  if (panel && !panel.hidden) renderNotifList();
}
async function markAllRead(){
  const keys = DATA.alerts.filter(a => !a.read).map(a => a.key);
  if (!keys.length){ toast(LANG === 'am' ? 'ምንም ያልተነቡ ማንቂያዎች የሉም' : 'No unread alerts'); return; }
  try { await API.attendance.markAlertRead(keys); } catch (e) { return fail(e); }
  DATA.alerts.forEach(a => a.read = true);
  DATA.alertsUnread = 0;
  updateBell();
  toast(LANG === 'am' ? `${langNum(keys.length)} ማንቂያዎች እንደተነቡ ምልክት ተደርጓል` : `${keys.length} alerts marked as read`);
  refresh(true);
  const panel = $('#notifPanel');
  if (panel && !panel.hidden) renderNotifList();
}

/* ====================================================================== */
/*  GRADES LIST                                                            */
/* ====================================================================== */
async function renderClassesList(){
  const res = await API.grades.list({ with_counts: 1 });
  setGrades(res.grades || []);
  if (!CLASSES.length){
    return `<div class="card card-pad text-center nf-card"><h2 class="nf-h">${t('clT')}</h2>
      <p class="text-muted nf-sub">${LANG === 'am' ? 'ምንም ክፍል አልተመደበልዎትም።' : 'No grades are assigned to you.'}</p></div>`;
  }
  const rows = CLASSES.map(cls => `
    <a class="cls-row" href="${classHref(cls)}">
      <span class="geez-badge ${cls.g.tone}">${geezOf(cls.g)}</span>
      <span class="cr-main"><strong>${classLabel(cls)}</strong></span>
      <span class="cr-count">${langNum(cls.count)} ${t('stuSuffix')}</span>
      <span class="cr-chev" aria-hidden="true">›</span>
    </a>`).join('');
  return `
    <div class="page-top">
      <div class="sec-head mt-0 mb-0"><h2>${t('clT')} <span class="en">${t('clOrder')}</span></h2><p>${t('clsListSub')}</p></div>
      ${can('students.create') ? `<button class="btn btn-primary" id="addStudentBtn" type="button">${ICON.plus} ${t('addStudent')}</button>` : ''}
    </div>
    <div class="cls-list">${rows}</div>`;
}

/* ====================================================================== */
/*  ATTENDANCE REGISTER — year pills → 4 month groups (Pagume included)    */
/* ====================================================================== */
const REG = { gradeId:null, yearEc:null, gi:null, months:{}, students:[], canEdit:false };

function colTitle(c, etY){
  const dowAm = c.dow === 'sat' ? 'ቅዳሜ' : 'እሁድ';
  const dowEn = c.dow === 'sat' ? 'Saturday' : 'Sunday';
  return LANG === 'am'
    ? `${dowAm} ${dateNum(c.etD)} ${ET_MONTHS[c.m-1]} ${dateNum(etY)} ዓ.ም`
    : `${dowEn}, ${ET_MONTHS_EN[c.m-1]} ${c.etD}, ${etY} · ${c.iso}`;
}
const markOf = (stu, iso) => (stu.marks && stu.marks[iso]) || null;
const statusAt = (stu, iso) => { const m = markOf(stu, iso); return m ? m.status : 'none'; };

function attTable(year, gi){
  const grp = ATT_MONTH_GROUPS[gi];
  const monthCols = grp.map(m => ({ m, cols: monthWeekendCols(year, m), pagume: m === 13 }));
  const allCols = [];
  monthCols.forEach(x => x.cols.forEach(c => allCols.push(Object.assign({}, c, { m: x.m }))));
  const students = REG.students;

  const mh = monthCols.map(({m, cols, pagume}) =>
    `<th colspan="${Math.max(1, cols.length)}" scope="colgroup" class="${pagume ? 'pagume-h' : ''}">${LANG === 'am' ? ET_MONTHS[m-1] : ET_MONTHS_EN[m-1]}${pagume ? ` <span class="pg-tag">${langNum(etMonthDays(year, 13))}${LANG === 'am' ? ' ቀናት' : 'd'}</span>` : ''}</th>`).join('');
  const dh = allCols.map(c => `
    <th class="dcol ${c.dow}${c.iso === TODAY_ISO ? ' is-today' : ''}${c.m === 13 ? ' pagume' : ''}" scope="col" title="${esc(colTitle(c, year))}">
      <span>${LANG === 'am' ? (c.dow === 'sat' ? 'ቅ' : 'እ') : (c.dow === 'sat' ? 'Sat' : 'Sun')}</span>
      <b>${dateNum(c.etD)}</b>
    </th>`).join('');

  const rows = students.length ? students.map(stu => {
    const cells = allCols.map(c => {
      const m = markOf(stu, c.iso);
      const st = m ? m.status : 'none';
      const checked = st === 'present';
      const cls = st === 'absent' ? ' absent' : st === 'excused' ? ' excused late' : '';
      const rsn = m && m.reason ? ' · ' + m.reason : '';
      /* teachers in the grade register may mark TODAY only — every other
         day is shown greyed and changed from the Correction page. Masters
         and admins (attendance.correct) may touch any day. */
      const locked = REG.lockPast && !REG.corrMode && c.iso !== TODAY_ISO;
      return `<td class="acell ${c.dow}${cls}${c.iso === TODAY_ISO ? ' is-today' : ''}${c.m === 13 ? ' pagume' : ''}${locked ? ' locked' : ''}"
        data-stu="${stu.id}" data-diso="${c.iso}" data-st="${st}" title="${esc(colTitle(c, year) + ' — ' + statusLabel(st === 'none' ? 'notMarked' : st) + rsn)}">
        <input type="checkbox" class="att-check" data-stu="${stu.id}" data-diso="${c.iso}"
          ${checked ? 'checked' : ''} ${REG.canEdit && !locked ? '' : 'disabled'}
          aria-label="${esc(stu.full_name)} — ${esc(colTitle(c, year))}">
      </td>`;
    }).join('');
    return `<tr>
      <th class="att-name" scope="row" role="button" tabindex="0" data-stu="${stu.id}" data-byear="${year}">
        <span class="stu-av">${esc(initials(stu.full_name))}</span>
        <span class="an-txt"><span class="an-nm">${esc(stu.full_name)}</span><span class="an-id">№ ${esc(stu.student_code)}</span></span>
      </th>${cells}
    </tr>`;
  }).join('') : `<tr><td class="att-empty" colspan="${allCols.length + 1}">${t('stuEmpty')}</td></tr>`;

  const foot = allCols.map(c => {
    const n = students.reduce((acc, s) => acc + (statusAt(s, c.iso) === 'present' ? 1 : 0), 0);
    return `<td class="acell fcell ${c.dow}${c.iso === TODAY_ISO ? ' is-today' : ''}">${langNum(n)}</td>`;
  }).join('');

  return `<div class="att-card">
    <div class="att-head">
      <strong>${gTitle(grp)}</strong>
      <span class="att-meta">${yrText(year)}${year === ET_TODAY.year ? ` · ${t('curYear')}` : ''} · ${langNum(allCols.length)} ${L('ቀናት', 'sessions')}</span>
    </div>
    <div class="table-responsive att-scroll">
      <table class="att-table">
        <thead>
          <tr><th class="att-name hname" rowspan="2" scope="col">${t('thStudent')}</th>${mh}</tr>
          <tr>${dh}</tr>
        </thead>
        <tbody>${rows}</tbody>
        <tfoot><tr><th class="att-name fname" scope="row">${t('attTot')}</th>${foot}</tr></tfoot>
      </table>
    </div>
  </div>`;
}

/* Load one month group for a grade: students + their marks, merged */
async function loadRegister(gradeId, yearEc, gi){
  const grp = ATT_MONTH_GROUPS[gi];
  const results = await Promise.all(grp.map(m =>
    API.attendance.register({ grade_id: gradeId, month: m, year_ec: yearEc })));
  const byId = {};
  const order = [];
  results.forEach(res => {
    (res.students || []).forEach(s => {
      if (!byId[s.id]){ byId[s.id] = Object.assign({}, s, { marks: {} }); order.push(s.id); }
      const marks = s.marks || {};
      Object.keys(marks).forEach(iso => { byId[s.id].marks[iso] = marks[iso]; });
    });
  });
  REG.gradeId = gradeId; REG.yearEc = yearEc; REG.gi = gi;
  REG.students = order.map(id => byId[id]);
  REG.canEdit = !!(results[0] && results[0].can_edit) && can('attendance.edit');
  REG.grade = results[0] && results[0].grade;
  return REG;
}

/* ------------------------------------------------------------------
   Toolbar card — the register records TODAY (no date picker), so the
   card names today and the buttons act on today's column. Hidden on
   the correction page, where every change is a single-cell correction.
   ------------------------------------------------------------------ */
function regToolbar(){
  /* Hidden for the single-student record and on the correction page:
     bulk actions belong to the class register, not one student's grid. */
  if (!REG.canEdit || REG.corrMode || REG.single) return '';
  return `
  <div class="reg-tools card" role="group" aria-label="${t('regTools')}">
    <span class="rt-lbl">${L('ዛሬ የሚመዘገበው', 'Recording for')}</span>
    <span class="rt-date" id="regDate">${esc(isoLabel(TODAY_ISO, true))}</span>
    <div class="rt-btns">
      <button class="btn btn-soft ma-btn" data-ma="present" type="button">${t('maPresent')}</button>
      <button class="btn btn-soft ma-btn" data-ma="absent" type="button">${t('maAbsent')}</button>
      <button class="btn btn-soft ma-btn" data-ma="excused" type="button">${t('maExcused')}</button>
      <button class="btn btn-ghost" id="copyWeekBtn" type="button">${t('copyWeek')}</button>
    </div>
  </div>`;
}

/* ------------------------------------------------------------------
   Grade register — one full-year grid, no toolbar card, no tabs.
   Teachers mark today only (past days greyed); masters and admins
   may touch any day. The same grid, in correction mode, is what the
   Attendance Correction page uses after a student is picked.
   ------------------------------------------------------------------ */
async function renderClassAttendance(clsId, sid){
  if (!CLASSES.length){
    const res = await API.grades.list({ with_counts: 1 });
    setGrades(res.grades || []);
  }
  const cls = CLASS_BY_ID[String(clsId)];
  if (!cls) return renderNotFound();

  const years = DATA.years.length ? DATA.years : (await API.settings.years()).years;
  DATA.years = years;
  const yAsc = years.map(y => +y.year_ec).sort((a, b) => a - b);
  if (!yAsc.includes(state.openYear)) state.openYear = Math.max.apply(null, yAsc);
  const year = state.openYear;
  const defGi = year === ET_TODAY.year ? Math.min(3, Math.floor((ET_TODAY.month - 1) / 3)) : 0;
  const gi = state.grpTab[year] !== undefined ? state.grpTab[year] : defGi;

  await loadRegister(cls.id, year, gi);

  REG.corrMode  = false;
  REG.todayOnly = false;
  /* Master and admin may mark any day in the register; everyone else
     (teachers) records TODAY only — past days stay greyed and are
     fixed from the Attendance Correction page. */
  REG.lockPast  = !(DATA.user.role === 'master' || DATA.user.role === 'admin');
  state.regDate = TODAY_ISO;
  REG.single = sid ? String(sid) : null;
  if (REG.single){
    const one = REG.students.find(s => String(s.id) === REG.single);
    if (!one) return renderNotFound();
    REG.students = [one];
  }

  const yearPills = yAsc.map(y =>
    `<button class="grp-tab yr-tab ${state.openYear === y ? 'active' : ''}" data-year="${y}" type="button" role="tab" aria-selected="${state.openYear === y}">${yrText(y)}</button>`).join('');
  const tabs = ATT_MONTH_GROUPS.map((grp, i) =>
    `<button class="grp-tab ${i === gi ? 'active' : ''}" data-gi="${i}" data-yr="${year}" type="button" role="tab" aria-selected="${i === gi}">${gTitle(grp)}</button>`).join('');

  const backHref = REG.single ? `#/grades/${clsId}` : '#/grades';
  const heading = REG.single
    ? `<span class="geez-badge ${cls.g.tone}">${esc(initials(REG.students[0].full_name))}</span>
       <div class="ph-txt">
         <h1>${esc(REG.students[0].full_name)}</h1>
         <p>${esc(REG.students[0].student_code || '—')} · ${classLabelLong(cls)}</p>
       </div>`
    : `<span class="geez-badge ${cls.g.tone}">${geezOf(cls.g)}</span>
       <div class="ph-txt">
         <h1>${classLabelLong(cls)}</h1>
         <p>${langNum(REG.students.length)} ${t('stuSuffix')}</p>
       </div>`;

  return `
    <a class="back-link" href="${backHref}">${ICON.back} ${t('backClasses').replace('‹ ','')}</a>
    <div class="page-head">
      ${heading}
      ${!REG.single && can('students.create') ? `<button class="btn btn-primary" id="addStudentBtn" type="button">${ICON.plus}<span class="ph-btn-txt">${t('addStudent')}</span></button>` : ''}
    </div>
    <div class="year-bar grp-tabs" role="tablist">${yearPills}
      ${!REG.single && can('years.manage') ? `<button class="grp-tab yr-add" id="addYearBtn" type="button" title="${t('addYear')}">${ICON.plus} ${langNum(Math.max.apply(null, yAsc) + 1)}</button>` : ''}
    </div>
    <div class="yr-pane" data-year="${year}" role="tabpanel">
      <div class="yr-meta">
        <span class="yr-meta-yr">${yrText(year)}${year === ET_TODAY.year ? ` <span class="cur-chip">${t('curYear')}</span>` : ''}</span>
        <span class="yr-meta-n">${langNum(REG.students.length)} ${t('stuSuffix')}</span>
      </div>
      <div class="grp-tabs" role="tablist">${tabs}</div>
      ${regToolbar()}
      <div class="att-pane" data-gi="${gi}" role="tabpanel">${attTable(year, gi)}</div>
    </div>`;
}

/* ------------------------------------------------------------------
   Correction grid — one student, every day editable, every change
   asks why. Rendered by the Attendance Correction page after a
   student is picked from the live search.
   ------------------------------------------------------------------ */
async function renderStudentCorrection(sid){
  let det;
  try {
    det = await API.students.get(sid, { year_ec: state.openYear || undefined });
  } catch (e) {
    return `<div class="card card-pad tbl-empty">${esc((e && e.message) || String(e))}</div>`;
  }
  const stu = det.student || {};
  const enr = (det.enrollments || []).find(e => +e.year_ec === +((det.year && det.year.year_ec) || state.openYear))
            || (det.enrollments || [])[0] || {};
  if (!enr.grade_id) {
    return `<div class="card card-pad tbl-empty">${esc(L('ይህ ተማሪ በዓመቱ አልተመዘገበም።', 'This student is not enrolled this year.'))}</div>`;
  }
  const year = (det.year && +det.year.year_ec) || state.openYear;
  const defGi = year === ET_TODAY.year ? Math.min(3, Math.floor((ET_TODAY.month - 1) / 3)) : 0;
  const gi = state.grpTab[year] !== undefined ? state.grpTab[year] : defGi;

  await loadRegister(enr.grade_id, year, gi);
  const one = REG.students.find(x => String(x.id) === String(sid));
  if (!one) {
    return `<div class="card card-pad tbl-empty">${esc(L('ይህ ተማሪ በዓመቱ አልተመዘገበም።', 'This student is not enrolled this year.'))}</div>`;
  }
  REG.corrMode  = true;
  REG.todayOnly = false;
  REG.lockPast  = false;
  REG.single    = String(sid);
  REG.students  = [one];
  state.regDate = TODAY_ISO;

  const gradeName = LANG === 'am' ? (REG.grade && REG.grade.name_am) : (REG.grade && REG.grade.name_en);
  const tabs = ATT_MONTH_GROUPS.map((grp, i) =>
    `<button class="grp-tab ${i === gi ? 'active' : ''}" data-gi="${i}" data-yr="${year}" type="button" role="tab" aria-selected="${i === gi}">${gTitle(grp)}</button>`).join('');
  return `
    <div class="page-head">
      <span class="geez-badge">${esc(initials(one.full_name))}</span>
      <div class="ph-txt">
        <h1>${esc(one.full_name)}</h1>
        <p>${esc(one.student_code || '—')}${gradeName ? ' · ' + esc(gradeName) : ''} · ${yrText(year)}</p>
      </div>
      <button class="btn btn-soft" id="corBack" type="button">${L('ሌላ ተማሪ', 'Change student')}</button>
    </div>
    <div class="yr-pane" data-year="${year}" role="tabpanel">
      <div class="yr-meta">
        <span class="yr-meta-yr">${yrText(year)}${year === ET_TODAY.year ? ` <span class="cur-chip">${t('curYear')}</span>` : ''}</span>
      </div>
      <div class="grp-tabs" role="tablist">${tabs}</div>
      ${regToolbar()}
      <div class="att-pane" data-gi="${gi}" role="tabpanel">${attTable(year, gi)}</div>
    </div>`;
}
window.srRenderStudentCorrection = renderStudentCorrection;

/* Persist one cell */
async function saveMark(stuId, iso, status, reason){
  const stu = REG.students.find(s => String(s.id) === String(stuId));
  if (!stu) return;
  const prev = stu.marks[iso] ? Object.assign({}, stu.marks[iso]) : null;
  if (status === 'none') delete stu.marks[iso];
  else stu.marks[iso] = { status: status, reason: reason || null, day: isoDayKind(iso) };
  try {
    const res = await API.attendance.save({
      grade_id: +REG.gradeId, year_ec: REG.yearEc,
      marks: [{ student_id: +stuId, grade_id: +REG.gradeId, date: iso, status: status, reason: reason || '' }]
    });
    if (res.queued) setSync('pending', res.pending);
    else { setSync('online'); indicateSaved(); }
    if (res.errors && res.errors.length) toast(res.errors[0].message, 'error');
  } catch (err) {
    if (prev) stu.marks[iso] = prev; else delete stu.marks[iso];
    fail(err);
    refresh(true);
  }
}
function paintCell(td, status, reason){
  td.dataset.st = status;
  td.classList.toggle('absent', status === 'absent');
  td.classList.toggle('excused', status === 'excused');
  const cb = td.querySelector('.att-check');
  if (cb) cb.checked = status === 'present';
  td.classList.remove('just-saved'); void td.offsetWidth; td.classList.add('just-saved');
  if (reason) td.title = td.title.split(' — ')[0] + ' — ' + statusLabel(status) + ' · ' + reason;
  recountColumn(td);
}
function recountColumn(td){
  const tr = td.closest('tr'), tbl = td.closest('table');
  const colIdx = tr ? [...tr.cells].indexOf(td) : -1;
  if (!tbl || colIdx < 1 || !tbl.tFoot) return;
  let n = 0;
  [...tbl.tBodies[0].rows].forEach(rw => {
    const c = rw.cells[colIdx];
    if (c && c.dataset.st === 'present') n++;
  });
  const fc = tbl.tFoot.rows[0].cells[colIdx];
  if (fc) fc.textContent = langNum(n);
}

/* ====================================================================== */
/*  Student profile modal                                                  */
/* ====================================================================== */
async function openProfile(studentId, gradeId, year){
  try {
    state.modal = { type:'loading' };
    mountModal();
    const det = await API.students.get(studentId, { year_ec: year || state.openYear });
    state.modal = { type:'profile', detail: det, student: det.student, year: (det.year && +det.year.year_ec) || year || state.openYear };
    mountModal();
  } catch (e) { state.modal = null; mountModal(); fail(e); }
}
function profileModalHtml(m){
  const s = m.student;
  const d = m.detail || {};
  const att = d.attendance || {};
  const totals = att.summary || { present:0, absent:0, excused:0, total:0, rate:0 };
  const rate = Math.round(totals.rate || 0);
  /* current enrolment = the row for the year we asked about */
  const enr = (d.enrollments || []).find(e => +e.year_ec === +m.year) || (d.enrollments || [])[0] || {};
  const gradeName = LANG === 'am' ? (enr.grade_am || '') : (enr.grade_en || '');
  s.grade_id = enr.grade_id || null;
  s.roll_no  = enr.roll_no  || null;
  const bm = att.by_month || {};
  const monthKeys = Object.keys(bm).map(k => +k).sort((a, b) => a - b);
  const streak = d.absence_streak || 0;
  const thr = d.threshold || 3;
  const marked = totals.total || ((totals.present || 0) + (totals.absent || 0) + (totals.excused || 0));

  /* ---- month bars: a table of three numbers reads worse than a bar ---- */
  const months = monthKeys.map(mo => {
    const x = bm[mo] || {};
    const p = x.present || 0, a = x.absent || 0, e = x.excused || 0;
    const tot = p + a + e;
    if (!tot) return '';
    const r = Math.round(p * 100 / tot);
    const pc = v => (v * 100 / tot).toFixed(2) + '%';
    return `<li class="pm-mo">
      <span class="pm-mo-n">${LANG === 'am' ? ET_MONTHS[mo-1] : ET_MONTHS_EN[mo-1]}${mo === 13 ? ' <i class="pm-pag" title="Pagume">✦</i>' : ''}</span>
      <span class="pm-bar" role="img"
        aria-label="${langNum(p)}/${langNum(tot)} · ${langNum(r)}%">
        <i class="pm-bar-p" style="width:${pc(p)}"></i>
        <i class="pm-bar-e" style="width:${pc(e)}"></i>
        <i class="pm-bar-a" style="width:${pc(a)}"></i>
      </span>
      <span class="pm-mo-v">${langNum(p)}<em>/${langNum(tot)}</em></span>
      <span class="pm-mo-r ${r >= 85 ? 'ok' : r >= 60 ? 'mid' : 'low'}">${langNum(r)}%</span>
    </li>`;
  }).join('');

  /* ---- detail rows: only show what actually exists ---- */
  const det = [
    [t('pmId'), esc(s.student_code), 'mono'],
    [t('asBaptism'), esc(s.baptism_name || '')],
    [t('pmRoll'), s.roll_no ? rollTxt(s.roll_no) : ''],
    [t('asGender'), s.gender ? t(s.gender === 'female' ? 'gFemale' : 'gMale') : ''],
    [t('pmBirth'), s.birth_date ? esc(dLbl(String(s.birth_date).slice(0, 10))) : ''],
    [t('pmStatus'), s.status ? t('st' + s.status.charAt(0).toUpperCase() + s.status.slice(1)) : '']
  ].filter(r => r[1]).map(r =>
    `<div class="pm-dr"><dt>${r[0]}</dt><dd class="${r[2] || ''}">${r[1]}</dd></div>`).join('');

  const contact = [
    [ICON.phone, t('pmPhone'), s.phone ? digitsNum(s.phone) : '', s.phone ? 'tel:' + s.phone : ''],
    [ICON.users, t('pmGuardian'), esc(s.guardian_name || ''), ''],
    [ICON.phone, t('pmGuardPh'), s.guardian_phone ? digitsNum(s.guardian_phone) : '', s.guardian_phone ? 'tel:' + s.guardian_phone : ''],
    [ICON.home, t('pmAddress'), esc(s.address || ''), '']
  ].filter(r => r[2]).map(r =>
    `<div class="pm-cr">${r[0] || ''}<span class="pm-cr-k">${r[1]}</span>
       ${r[3] ? `<a class="pm-cr-v" href="${esc(r[3])}">${r[2]}</a>` : `<span class="pm-cr-v">${r[2]}</span>`}
     </div>`).join('');

  return `<div class="modal-wrap" id="appModal">
    <div class="modal-overlay" data-mclose></div>
    <div class="modal-card pm-card" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
      <div class="pm-head pm-head-v2">
        <span class="pm-av">${esc(initials(s.full_name))}</span>
        <div class="pm-head-txt">
          <h3 id="pmTitle">${esc(s.full_name)}</h3>
          <p class="pm-sub">
            <span class="pm-tag">${esc(gradeName || '—')}</span>
            <span class="pm-code">${esc(s.student_code)}</span>
          </p>
        </div>
        <button class="pm-close" data-mclose type="button" aria-label="${t('pmClose')}">✕</button>
      </div>

      <div class="pm-body pm-body-v2">
        ${streak >= thr ? `<div class="pm-alert">${ICON.warn}<span>${t('pmStreak')(streak)}</span></div>` : ''}

        <section class="pm-blk">
          <h4 class="pm-blk-t">${t('pmOverview')}<span class="pm-blk-x">${yrText(m.year)}</span></h4>
          <div class="pm-hero">
            <div class="pm-ring ${rate >= 85 ? 'ok' : rate >= 60 ? 'mid' : 'low'}"
                 style="--pct:${marked ? rate : 0}">
              <span class="pm-ring-in">
                <b>${marked ? langNum(rate) + '%' : '—'}</b>
                <i>${t('pmRate')}</i>
              </span>
            </div>
            <div class="pm-stats">
              <div class="pm-stat p"><b>${langNum(totals.present || 0)}</b><span>${t('pmPresentN')}</span></div>
              <div class="pm-stat a"><b>${langNum(totals.absent || 0)}</b><span>${t('pmAbsentN')}</span></div>
              <div class="pm-stat e"><b>${langNum(totals.excused || 0)}</b><span>${t('pmExcusedN')}</span></div>
              <div class="pm-stat n"><b>${langNum(marked)}</b><span>${t('attTot')}</span></div>
            </div>
          </div>
        </section>

        <section class="pm-blk">
          <h4 class="pm-blk-t">${t('pmDetails')}</h4>
          <dl class="pm-dl">${det}</dl>
        </section>

        <section class="pm-blk">
          <h4 class="pm-blk-t">${t('pmContact')}</h4>
          ${contact ? `<div class="pm-contact">${contact}</div>`
                    : `<p class="pm-none">${t('pmNoContact')}</p>`}
        </section>

        <section class="pm-blk pm-blk-last">
          <h4 class="pm-blk-t">${t('pmMonths')}</h4>
          ${months ? `<ul class="pm-mos">${months}</ul>
            <div class="pm-key">
              <span><i class="k-p"></i>${t('pmPresentN')}</span>
              <span><i class="k-e"></i>${t('pmExcusedN')}</span>
              <span><i class="k-a"></i>${t('pmAbsentN')}</span>
            </div>`
          : `<p class="pm-none">${t('pmNoMarks')}</p>`}
        </section>
      </div>

      <footer class="pm-foot">
        ${s.grade_id ? `<a class="btn btn-primary" href="#/grades/${s.grade_id}/${s.id}" id="pmViewCls">${t('pmViewClass')}</a>` : ''}
        ${FEAT('studentIdCard') ? `<button class="btn btn-ghost" id="pmCard" type="button">${ICON.card} ${t('pmCard')}</button>` : ''}
        ${can('students.edit') ? `<button class="btn btn-ghost" id="pmEdit" type="button" data-id="${s.id}">${ICON.pencil} ${t('pmEdit')}</button>` : ''}
        ${can('students.delete') ? `<button class="btn btn-ghost pm-del pm-foot-end" id="pmRemove" type="button" data-id="${s.id}" data-name="${esc(s.full_name)}">${ICON.trash} ${t('pmRemove')}</button>` : ''}
      </footer>
    </div>
  </div>`;
}
function renderModal(){
  const m = state.modal;
  if (!m) return '';
  if (m.type === 'loading') return `<div class="modal-wrap" id="appModal"><div class="modal-overlay" data-mclose></div>
      <div class="modal-card modal-loading">${loadingHtml()}</div></div>`;
  if (m.type === 'profile') return profileModalHtml(m);
  if (m.type === 'html') return `<div class="modal-wrap" id="appModal"><div class="modal-overlay" data-mclose></div>
      <div class="modal-card">${m.html}</div></div>`;
  return '';
}
function mountModal(){
  const old = $('#appModal'); if (old) old.remove();
  const html = renderModal();
  if (!html) return;
  const wrap = document.createElement('div');
  wrap.innerHTML = html;
  document.body.appendChild(wrap.firstElementChild);
  const close = () => { state.modal = null; mountModal(); };
  $$('#appModal [data-mclose]').forEach(el => el.addEventListener('click', close));
  const pv = $('#pmViewCls'); pv && pv.addEventListener('click', () => { state.modal = null; });
  const pe = $('#pmEdit');
  pe && pe.addEventListener('click', () => {
    const id = pe.dataset.id;
    state.modal = null; mountModal();
    location.hash = '#/students/' + id;
  });
  const pc = $('#pmCard');
  pc && pc.addEventListener('click', () => {
    const s = state.modal && state.modal.student;
    if (s) API.openPrint('idcards', { ids: [s.id], format: 'pdf', lang: LANG });
  });
  const rm = $('#pmRemove');
  rm && rm.addEventListener('click', async () => {
    const name = rm.dataset.name, id = rm.dataset.id;
    const msg = I18N[LANG].removeConfirm(name);
    if (!(await srConfirm({ title: LANG === 'am' ? 'ተማሪ ማስወገድ' : 'Remove student',
                            message: msg, danger: true,
                            okLabel: LANG === 'am' ? 'አስወግድ' : 'Remove' }))) return;
    try {
      await API.students.remove(id);
      toast(t('removedToast'));
      state.modal = null; mountModal();
      refresh(true);
    } catch (e) { fail(e); }
  });
  if (typeof window.srBindModal === 'function') window.srBindModal();
  const c = $('#appModal .pm-close'); c && c.focus();
}
window.srOpenProfile = openProfile;
window.srMountModal = mountModal;

/* ====================================================================== */
/*  Confirm dialog                                                         */
/*  One styled modal for every destructive action, replacing the browser's */
/*  native confirm() everywhere. Returns a Promise<boolean>; supports      */
/*  Escape / overlay / cancel = false, Enter = ok.                         */
/* ====================================================================== */
/* One styled dialog for every confirm AND every short prompt (the excuse
   reason is typed in it too). With opts.input set it resolves to the typed
   string (or null when cancelled); otherwise it resolves to a boolean. */
window.srConfirm = function (opts) {
  opts = opts || {};
  return new Promise(function (resolve) {
    const asks = Object.prototype.hasOwnProperty.call(opts, 'input');
    const okL  = opts.okLabel     || (LANG === 'am' ? 'ቀጥል' : 'Continue');
    const noL  = opts.cancelLabel || (LANG === 'am' ? 'ተወው' : 'Cancel');
    const wrap = document.createElement('div');
    wrap.id = 'srConfirm';
    wrap.className = 'modal-wrap cf-wrap';
    wrap.innerHTML = `
      <div class="modal-overlay" data-cfclose></div>
      <div class="modal-card cf-card" role="${asks ? 'dialog' : 'alertdialog'}" aria-modal="true"
           aria-labelledby="cfTitle" aria-describedby="cfMsg">
        ${asks ? '' : `<div class="cf-ico" aria-hidden="true">${opts.danger ? '!' : '?'}</div>`}
        <h2 class="cf-title" id="cfTitle"></h2>
        <p class="cf-msg" id="cfMsg"></p>

        ${asks ? `<input class="form-control cf-in" id="cfIn" type="text" maxlength="160"
                        autocomplete="off" placeholder="${esc(opts.placeholder || '')}">` : ''}
        <div class="cf-acts">
          <button type="button" class="btn btn-soft cf-no" data-cfno></button>
          <button type="button" class="btn ${opts.danger ? 'btn-danger cf-danger' : 'btn-primary'} cf-ok" data-cfok></button>
        </div>
      </div>`;
    wrap.querySelector('#cfTitle').textContent = opts.title || '';
    wrap.querySelector('#cfMsg').textContent = opts.message || '';
    wrap.querySelector('[data-cfno]').textContent = noL;
    wrap.querySelector('[data-cfok]').textContent = okL;
    document.body.appendChild(wrap);
    if (opts.danger) wrap.classList.add('with-ico');

    const done = (v) => { wrap.remove(); resolve(v); };
    wrap.querySelector('[data-cfclose]').addEventListener('click', () => done(asks ? null : false));
    wrap.querySelector('[data-cfno]').addEventListener('click', () => done(asks ? null : false));
    wrap.querySelector('[data-cfok]').addEventListener('click', () => {
      if (asks) {
        const v = wrap.querySelector('#cfIn').value.trim();
        if (!v) { wrap.querySelector('#cfIn').focus(); return; }
        done(v);
      } else done(true);
    });
    wrap.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); done(asks ? null : false); }
      else if (e.key === 'Enter') { e.preventDefault(); wrap.querySelector('[data-cfok]').click(); }
    });
    if (asks) {
      const inp = wrap.querySelector('#cfIn');
      inp.value = String(opts.input || '');
      inp.focus();
      try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (e) { /* noop */ }
    } else {
      const no = wrap.querySelector('[data-cfno]');
      if (no) no.focus();
    }
  });
};

/* ====================================================================== */
/*  Global search                                                          */
/* ====================================================================== */
let searchT = null;
function applySearch(qRaw){
  const q = qRaw.trim().toLowerCase();
  const box = $('#searchResults');
  const scb = $('#searchClear');
  if (scb) scb.hidden = !q;
  if (!box) return;
  if (!q){
    box.hidden = true; box.innerHTML = '';
    $$('#sec-alerts .alert-row').forEach(r => r.classList.remove('match-hide'));
    return;
  }
  $$('#sec-alerts .alert-row').forEach(r => r.classList.toggle('match-hide', !r.dataset.q.includes(q)));

  const clsHits = CLASSES.filter(c => {
    const g = c.g;
    const hay = [g.am, g.en.toLowerCase(), g.badge, 'grade', 'ክፍል', g.num ? String(g.num) : '',
                 (g.saintEn || '').toLowerCase(), g.saintAm || ''].join(' ');
    return hay.includes(q);
  }).slice(0, 8);
  const group = (title, items) => items ? `<div class="sr-group"><p class="sr-title">${title}</p>${items}</div>` : '';
  const clsL = clsHits.map(c => `<a class="sr-item" href="${classHref(c)}">
      <span class="sr-ic geez-ic ${c.g.tone}">${geezOf(c.g)}</span>
      <span class="sr-main"><strong>${classLabel(c)}</strong><span class="sr-sub">${classLabelLong(c)} · ${langNum(c.count)} ${t('stuSuffix')}</span></span>
      <span class="pill todo">${t('clT')}</span></a>`).join('');

  box.innerHTML = group(t('srClasses'), clsL) + `<div class="sr-group" id="srStuGroup"><p class="sr-title">${t('srStudents')}</p><p class="sr-empty">${t('loading')}</p></div>`;
  box.hidden = false;

  clearTimeout(searchT);
  searchT = setTimeout(async () => {
    try {
      const res = await API.students.list({ q: qRaw.trim(), per_page: 8 });
      const stuL = (res.students || []).map(s => `
        <a class="sr-item" href="#" data-open-profile="${s.id}|${s.grade_id || ''}">
          <span class="sr-ic">${esc(initials(s.full_name))}</span>
          <span class="sr-main"><strong>${esc(s.full_name)}</strong><span class="sr-sub">${esc(LANG === 'am' ? (s.grade_am || '') : (s.grade_en || ''))} · ${esc(s.student_code)}</span></span>
          <span class="pill todo">${esc(s.baptism_name || '')}</span></a>`).join('');
      const grp = $('#srStuGroup');
      if (grp) grp.innerHTML = `<p class="sr-title">${t('srStudents')}</p>` +
        (stuL || `<p class="sr-empty">“${esc(qRaw)}” — ${t('srEmpty')}.</p>`);
      $$('[data-open-profile]', box).forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        const [sid, gid] = a.dataset.openProfile.split('|');
        openProfile(sid, gid, state.openYear);
      }));
    } catch (e) { /* search is best-effort */ }
  }, 260);
}

/* ====================================================================== */
/*  Router                                                                 */
/* ====================================================================== */
const viewEl = document.getElementById('view');
let currentParts = ['dashboard'];
let renderSeq = 0;

/* Views contributed by js/admin.js register themselves here. */
window.SR_VIEWS = window.SR_VIEWS || {};
const CORE_VIEWS = {
  dashboard: { cap:'dashboard.view', render: renderDashboard },
  grades:    { cap:'students.view',  render: parts => parts[1] ? renderClassAttendance(parts[1], parts[2]) : renderClassesList() },
  alerts:    { cap:'attendance.view', feature:'attendanceAlerts', render: renderAlertsPage }
};

function viewFor(name){ return CORE_VIEWS[name] || window.SR_VIEWS[name] || null; }

function parseHash(){
  const h = location.hash.replace(/^#\/?/, '').split('?')[0];
  return h ? h.split('/').filter(Boolean) : ['dashboard'];
}
async function route(){
  currentParts = parseHash();
  const name = currentParts[0];
  if (state.modal && state.modal.type !== 'loading'){ state.modal = null; mountModal(); }
  const view = viewFor(name);
  updateNavActive();

  if (!view){ mount(renderNotFound()); return; }
  if (view.cap && !can(view.cap)){ mount(renderNoAccess()); return; }
  if (view.feature && !FEAT(view.feature)){ mount(renderNoAccess()); return; }

  const seq = ++renderSeq;
  let out;
  try {
    out = view.render(currentParts);
    if (out && typeof out.then === 'function'){
      mount(loadingHtml(), true);
      out = await out;
    }
  } catch (err) {
    if (seq !== renderSeq) return;
    mount(errorHtml(err));
    const rb = $('#retryBtn'); rb && rb.addEventListener('click', () => route());
    return;
  }
  if (seq !== renderSeq) return;          // a newer navigation won
  mount(out);
}
function mount(html, quiet){
  viewEl.classList.remove('view-anim');
  viewEl.setAttribute('aria-busy', quiet ? 'true' : 'false');
  viewEl.innerHTML = html;
  void viewEl.offsetWidth;
  viewEl.classList.add('view-anim');
  if (quiet) return;
  bindView();
  window.scrollTo(0, 0);
}
function refresh(keepScroll){
  const y = keepScroll ? window.scrollY : 0;
  route().then(() => { if (keepScroll) window.scrollTo(0, y); });
}
window.srRefresh = refresh;
window.srRoute = route;

/* ------------------------------ Bindings -------------------------------- */
function bindView(){
  const [v] = currentParts;

  const gs = $('#globalSearch');
  if (gs){
    gs.addEventListener('input', e => { state.search = e.target.value; applySearch(e.target.value); });
    gs.addEventListener('keydown', e => { if (e.key === 'Escape'){ gs.value = ''; state.search = ''; applySearch(''); } });
    const sc = $('#searchClear');
    sc && sc.addEventListener('click', () => { gs.value = ''; state.search = ''; applySearch(''); gs.focus(); });
    if (state.search) applySearch(state.search);
  }

  $$('.mark-read', viewEl).forEach(b => b.addEventListener('click', e => { e.stopPropagation(); markRead(b.dataset.alert); }));
  const mab = $('#markAllTableBtn');
  mab && mab.addEventListener('click', markAllRead);

  $$('.alert-row[data-profile]', viewEl).forEach(tr => {
    const open = e => {
      if (e && e.target.closest('.mark-read')) return;
      const [gid, sid] = tr.dataset.profile.split('|');
      openProfile(sid, gid, state.openYear);
    };
    tr.addEventListener('click', open);
    tr.addEventListener('keydown', e => { if (e.key === 'Enter'){ e.preventDefault(); open(); } });
  });
  $$('.al-filter', viewEl).forEach(ch => ch.addEventListener('click', () => {
    if (state.alertFilter === ch.dataset.f) return;
    state.alertFilter = ch.dataset.f;
    refresh(true);
  }));

  const asb = $('#addStudentBtn');
  asb && asb.addEventListener('click', () => {
    const pre = v === 'grades' ? (currentParts[1] || '') : '';
    location.hash = '#/add' + (pre ? '/' + pre : '');
  });

  if (v === 'grades' && currentParts[1]) bindRegister();

  if (typeof window.srBindAdminView === 'function') window.srBindAdminView(currentParts, viewEl);
  labelTableCells(viewEl);
  mountModal();
}

/* Copy each column's header onto its cells as data-th. On phones the
   stacked-card table mode (CSS) prints that label beside the value, so a
   7-column roster stays readable instead of becoming a scroll strip. */
function labelTableCells(scope){
  $$('.tbl-card table, .tbl-in table', scope || document).forEach(tbl => {
    if (tbl.closest('.att-scroll')) return;          /* the register grid scrolls on purpose */
    const head = tbl.tHead && tbl.tHead.rows[0];
    if (!head) return;
    const labels = [...head.cells].map(th => (th.textContent || '').trim());
    if (!labels.some(Boolean)) return;
    tbl.classList.add('tbl-stack');
    [...tbl.tBodies].forEach(tb => [...tb.rows].forEach(tr => {
      const cells = [...tr.cells];
      /* title = the name-ish cell (a link, <strong>, or .stu-cell), else the
         first cell with text — never the bare row checkbox */
      const named = cells.find(td => td.querySelector('a, strong, .stu-cell, .nm'));
      const title = named || cells.find(td => td.textContent.trim());
      cells.forEach((td, i) => {
        if (td.colSpan > 1) { td.classList.add('td-full'); return; }
        /* a lone checkbox rides along with the title instead of taking a row */
        const only = td.children.length === 1 && !td.textContent.trim() ? td.firstElementChild : null;
        if (only && only.matches('input[type=checkbox]')) { td.classList.add('td-chk'); return; }
        if (td === title) { td.classList.add('td-title'); if (labels[i]) td.setAttribute('data-th', labels[i]); return; }
        if (labels[i]) td.setAttribute('data-th', labels[i]);
      });
    }));
  });
}

function bindRegister(){
  $$('.yr-tab', viewEl).forEach(tab => tab.addEventListener('click', () => {
    if (+tab.dataset.year === state.openYear) return;
    state.openYear = +tab.dataset.year;
    state.regDate = null;
    refresh(true);
  }));
  const ayb = $('#addYearBtn');
  ayb && ayb.addEventListener('click', async () => {
    const next = Math.max.apply(null, DATA.years.map(y => +y.year_ec)) + 1;
    try {
      await API.settings.addYear({ year_ec: next });
      DATA.years = (await API.settings.years()).years;
      toast(I18N[LANG].addYearToast(next));
      state.openYear = next;
      refresh(true);
    } catch (e) { fail(e); }
  });
  $$('.ma-btn', viewEl).forEach(b => b.addEventListener('click', () => markAllOn(b.dataset.ma)));
  const cw = $('#copyWeekBtn');
  cw && cw.addEventListener('click', copyLastWeek);
  bindAttCells();
}
function centerTodayCol(scope){
  const sc = scope && scope.querySelector('.att-scroll');
  if (!sc || sc.scrollLeft > 0) return;
  const th = sc.querySelector('th.is-today');
  if (th) sc.scrollLeft = Math.max(0, th.offsetLeft - sc.clientWidth / 2 + 60);
}

/* Shared by the grade register AND the correction grid: per-cell
   marking. In the register a teacher records today only (past cells
   greyed); masters/admins may touch any day. In correction mode every
   change asks WHY, and the answer lands in the audit trail. */
function bindAttCells(){
  /* Month-group tabs — the same 3-month groups on the register and on the
     correction grid, so switching a group works on both. */
  const strip = viewEl.querySelector('.yr-pane .grp-tabs');
  const act = strip && strip.querySelector('.grp-tab.active');
  if (act) strip.scrollLeft = Math.max(0, act.offsetLeft - strip.clientWidth / 2 + act.clientWidth / 2);
  centerTodayCol(viewEl);
  $$('.grp-tab[data-gi]', viewEl).forEach(tab => tab.addEventListener('click', () => {
    state.grpTab[tab.dataset.yr] = +tab.dataset.gi;
    state.regDate = null;
    refresh(true);
  }));

  /* tick = present · untick = absent (unchanged muscle memory) */
  $$('.att-check', viewEl).forEach(cb => cb.addEventListener('change', async () => {
    const td = cb.closest('td');
    const iso = cb.dataset.diso, stu = cb.dataset.stu;
    const st = cb.checked ? 'present' : 'absent';
    if (REG.corrMode){
      /* hold the visual until the change is confirmed */
      cb.checked = td.dataset.st === 'present';
      if (st === td.dataset.st){ return; }
      const why = await srConfirm({
        title: L('የለውጡ ምክንያት', 'Reason for the change'),
        message: L('ይህ መዝገብ ለምን ይቀየራል? መልሱ በመዝገቡ ውስጥ ይቀመጣል።',
                   'Why is this record being changed? The answer stays in the audit trail.'),
        input: '', okLabel: L('አስተካክል', 'Apply')
      });
      if (why === null){ paintCell(td, td.dataset.st, null); return; }
      try {
        await API.attendance.correct({ student_id: +stu, date: iso, status: st, reason: '', note: why });
        paintCell(td, st, null);
        toast(L('ማስተካከያው ተመዝግቧል።', 'Correction saved.'));
      } catch (e) { paintCell(td, td.dataset.st, null); fail(e); }
      return;
    }
    paintCell(td, st, null);
    saveMark(stu, iso, st, null);
  }));

  /* right-click / long-press a cell → full status menu incl. Excused + reason.
     In the register a teacher's menu only exists on today's cell. */
  $$('.acell[data-stu]', viewEl).forEach(td => {
    if (!REG.canEdit || (REG.lockPast && !REG.corrMode && td.dataset.diso !== TODAY_ISO)) return;
    let pressT = null;
    const open = e => { e.preventDefault(); cellMenu(td, e); };
    td.addEventListener('contextmenu', open);
    td.addEventListener('touchstart', e => { pressT = setTimeout(() => open(e), 550); }, { passive:true });
    ['touchend','touchmove','touchcancel'].forEach(ev => td.addEventListener(ev, () => clearTimeout(pressT)));
  });

  $$('.att-name[data-stu]', viewEl).forEach(th => {
    const open = () => openProfile(th.dataset.stu, REG.gradeId, +th.dataset.byear);
    th.addEventListener('click', open);
    th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); open(); } });
  });
}
window.srBindAttCells   = bindAttCells;
window.srCenterTodayCol = centerTodayCol;

/* Per-cell status menu */
function cellMenu(td, ev){
  closeCellMenu();
  const iso = td.dataset.diso, stu = td.dataset.stu;
  const menu = document.createElement('div');
  menu.className = 'cell-menu';
  menu.id = 'cellMenu';
  menu.innerHTML = `
    <p class="cm-head">${esc(isoLabel(iso, true))}</p>
    <button type="button" data-st="present">${t('cellPresent')}</button>
    <button type="button" data-st="absent">${t('cellAbsent')}</button>
    <button type="button" data-st="excused">${t('cellExcused')}</button>
    <button type="button" data-st="none" class="cm-clear">${t('cellClear')}</button>`;
  document.body.appendChild(menu);
  const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) || 0;
  const y = (ev.touches ? ev.touches[0].clientY : ev.clientY) || 0;
  menu.style.left = Math.min(x, window.innerWidth - menu.offsetWidth - 8) + 'px';
  menu.style.top  = Math.min(y, window.innerHeight - menu.offsetHeight - 8) + 'px';
  $$('button', menu).forEach(b => b.addEventListener('click', async () => {
    const st = b.dataset.st;
    closeCellMenu();
    let reason = null;
    if (st === 'excused' && !REG.corrMode){
      /* one styled dialog instead of the browser prompt — the box always
         starts empty, the previous reason is never pre-filled */
      reason = await srConfirm({
        title: t('reasonAsk'),
        message: L('ለዚህ ቀን የፈቃድ ምክንያት ያስፈልጋል።', 'A reason is required for this excused day.'),
        input: '',
        okLabel: L('አስቀምጥ', 'Save')
      });
      if (reason === null) return;
      reason = reason.trim();
    }
    if (REG.corrMode){
      if (st === td.dataset.st){ toast(L('ለውጥ የለም።', 'Nothing to change.')); return; }
      const why = await srConfirm({
        title: L('የለውጡ ምክንያት', 'Reason for the change'),
        message: L('ይህ መዝገብ ለምን ይቀየራል? መልሱ በመዝገቡ ውስጥ ይቀመጣል።',
                   'Why is this record being changed? The answer stays in the audit trail.'),
        input: '', okLabel: L('አስተካክል', 'Apply')
      });
      if (why === null) return;
      try {
        await API.attendance.correct({ student_id: +stu, date: iso, status: st,
          reason: st === 'excused' ? why : '', note: why });
        paintCell(td, st, st === 'excused' ? why : null);
        toast(L('ማስተካከያው ተመዝግቧል።', 'Correction saved.'));
      } catch (e) { fail(e); }
      return;
    }
    paintCell(td, st, reason);
    saveMark(stu, iso, st, reason);
  }));
  setTimeout(() => document.addEventListener('click', closeCellMenu, { once:true }), 0);
}
function closeCellMenu(){ const m = $('#cellMenu'); if (m) m.remove(); }

/* Bulk: mark all on today (the register only ever marks today) */
async function markAllOn(status){
  const iso = TODAY_ISO;
  let reason = null;
  if (status === 'excused'){
    reason = await srConfirm({
      title: t('reasonAsk'),
      message: L('ሁሉም ተማሪዎች በፈቃድ ሲመዘገቡ ምክንያት ያስፈልጋል።', 'A reason is required when marking everyone excused.'),
      input: '',
      okLabel: L('አስቀምጥ', 'Save')
    });
    if (reason === null) return;
    reason = reason.trim();
  }
  const label = statusLabel(status);
  if (!(await srConfirm({
    title: t('markAll'),
    message: `${esc(isoLabel(iso, true))} · ${label}${reason ? ' — ' + esc(reason) : ''}\n${L('ሁሉም ተማሪዎች ይመዘገባሉ።', 'Every student is marked.')}`,
    okLabel: L('አስቀምጥ', 'Mark all')
  }))) return;
  try {
    const res = await API.attendance.markAll({
      grade_id: +REG.gradeId, date: iso, status: status, reason: reason || '',
      overwrite: true, year_ec: REG.yearEc
    });
    toast(`${langNum(res.marked || 0)} · ${label}`);
    refresh(true);
  } catch (e) { fail(e); }
}

/* Bulk: copy last week — preview first, then confirm */
async function copyLastWeek(){
  const iso = state.regDate;
  if (!iso) return;
  try {
    const prev = await API.attendance.copyLastWeek({ grade_id: +REG.gradeId, date: iso, year_ec: REG.yearEc });
    const list = prev.preview || [];
    if (!list.length){ toast(t('copyWeekNone'), 'error'); return; }
    const srcLbl = LANG === 'am' ? prev.source.label_am : prev.source.label_en;
    const tgtLbl = LANG === 'am' ? prev.target.label_am : prev.target.label_en;
    const brief = list.slice(0, 8).map(p => `· ${p.full_name} — ${statusLabel(p.status)}`).join('\n');
    let msg = `${t('copyWeekAsk')}\n\n${srcLbl}  →  ${tgtLbl}\n${langNum(list.length)}\n\n${brief}` +
              (list.length > 8 ? `\n…(+${langNum(list.length - 8)})` : '');
    if (prev.overwrite_count) msg += '\n\n' + I18N[LANG].overwriteAsk(prev.overwrite_count);
    if (!(await srConfirm({
      title: t('copyWeek'),
      message: msg,
      okLabel: L('አስቀምጥ', 'Copy')
    }))) return;
    const res = await API.attendance.copyLastWeek({
      grade_id: +REG.gradeId, date: iso, year_ec: REG.yearEc,
      source_date: prev.source.date, confirm: true
    });
    toast(I18N[LANG].copyWeekOk(res.copied || list.length));
    refresh(true);
  } catch (e) {
    if (e && e.type === 'no_source'){ toast(t('copyWeekNone'), 'error'); return; }
    fail(e);
  }
}

/* ====================================================================== */
/*  Navigation chrome                                                      */
/* ====================================================================== */
/* NAV_ITEMS — the single source of truth for the sidebar AND the mobile
   tab bar. Both are generated from this list, so adding a page here is
   all it takes to make it appear in every navigation surface.

   Fields per entry:
     nav      route key; must match the first hash segment (#/students → 'students')
              and the name the view is registered under in CORE_VIEWS / SR_VIEWS.
     href     the link target.
     icon     an ICON.* glyph (see the ICON table near the top of this file).
     labelKey an I18N key — never a literal string, or it will not translate.
     cap      capability required to SEE the entry. Server-side checks are
              enforced separately in api/*.php; hiding a link is cosmetic only.
     feature  optional js/features.js flag; false hides the entry.
     children optional sub-items, same shape. Rendered indented under the
              parent and shown when the parent or any child is the active
              route. Children are skipped in the mobile tab bar (no space) —
              they remain reachable from the parent page's own buttons.

   TO ADD A PAGE: add an entry here, register the renderer on
   window.SR_VIEWS (see js/admin.js, bottom of file), and add the two
   I18N label keys. Nothing else needs editing. */
const NAV_ITEMS = [
  { nav:'dashboard', href:'#/dashboard', icon:ICON.home,   labelKey:'navDashboard', cap:'dashboard.view' },
  { nav:'grades',    href:'#/grades',    icon:ICON.grid,   labelKey:'navClasses',   cap:'students.view' },
  /* Students is a group: the roster is the parent page, and the two ways
     of getting students INTO the roster hang off it as children. */
  { nav:'students',  href:'#/students',  icon:ICON.users,  labelKey:'navStudents',  cap:'students.view',
    children:[
      { nav:'add',    href:'#/add',    icon:ICON.uplus,  labelKey:'navAdd',    cap:'students.create' },
      { nav:'import', href:'#/import', icon:ICON.upload, labelKey:'navImport', cap:'students.import', feature:'studentImport' },
      /* History reads one student's whole record. It lives here, under
         Students, rather than inside the edit form where it used to hide. */
      { nav:'history', href:'#/history', icon:ICON.clock,  labelKey:'navHistory', cap:'students.view' }
    ] },
  { nav:'alerts',    href:'#/alerts',    icon:ICON.warn,   labelKey:'navAlerts',    cap:'attendance.view', feature:'attendanceAlerts' },
  /* Correcting a past record is its own job, separate from taking the
     register, and is limited to users holding attendance.correct.
     Canonical route is the plural #/corrections; the old singular
     #/correction still works and redirects (see js/admin.js). */
  { nav:'corrections', href:'#/corrections', icon:ICON.pencil, labelKey:'navCorrection', cap:'attendance.correct' },
  { nav:'promotion', href:'#/promotion', icon:ICON.up,     labelKey:'navPromotion', cap:'promotion.manage', feature:'studentPromotion' },
  { nav:'teachers',  href:'#/teachers',  icon:ICON.users,  labelKey:'navTeachers',  cap:'teachers.manage' },
  { nav:'users',     href:'#/users',     icon:ICON.shield, labelKey:'navUsers',     cap:'users.manage' },
  { nav:'permissions', href:'#/permissions', icon:ICON.target, labelKey:'navPerms', cap:'permissions.manage' },
  { nav:'audit',     href:'#/audit',     icon:ICON.list,   labelKey:'navAudit',     cap:'audit.view', feature:'auditTrail' },
  { nav:'backup',    href:'#/backup',    icon:ICON.db,     labelKey:'navBackup',    cap:'backup.manage', feature:'backupRestore' },
  { nav:'settings',  href:'#/settings',  icon:ICON.gear,   labelKey:'navSettings',  cap:'settings.view' }
];

/* Which entries get a slot in the 5-across mobile tab bar, in order.
   Anything missing (or hidden by caps) is backfilled from the sidebar. */
const TAB_ORDER = ['dashboard','grades','students','alerts','settings'];

/* An entry is shown when the user holds the capability AND the feature
   flag is on. Both are cosmetic gates — see the note on `cap` above. */
const navAllowed = i => (!i.cap || can(i.cap)) && (!i.feature || FEAT(i.feature));

/* Flatten parents + children into one list — used for "is this a known
   route?" tests and for backfilling the tab bar. */
function navFlat(){
  const out = [];
  NAV_ITEMS.forEach(i => { out.push(i); (i.children || []).forEach(c => out.push(c)); });
  return out;
}

function navItemHtml(i, cls){
  return cls === 'side-link'
    ? `<a class="${cls}" href="${i.href}" data-nav="${i.nav}"><span class="sl-ic">${i.icon}</span><span class="sl-tx">${t(i.labelKey)}</span></a>`
    : `<a class="${cls}" href="${i.href}" data-nav="${i.nav}">${i.icon}<span>${t(i.labelKey)}</span></a>`;
}

/* A child link: smaller, indented, with a tick-mark rail instead of an
   icon tile so the hierarchy reads at a glance. */
function navChildHtml(i){
  return `<a class="side-sub" href="${i.href}" data-nav="${i.nav}"><span class="ss-dot" aria-hidden="true"></span><span class="sl-tx">${t(i.labelKey)}</span></a>`;
}

function renderNav(){
  /* Sidebar: parents, each optionally followed by its visible children. */
  const side = NAV_ITEMS.filter(navAllowed);
  $('#sideNav').innerHTML = side.map(i => {
    const kids = (i.children || []).filter(navAllowed);
    if (!kids.length) return navItemHtml(i, 'side-link');
    /* The group is wrapped so the children can be shown/hidden as a unit
       (see .side-group.open in styles.css). */
    return `<div class="side-group" data-group="${i.nav}">
        ${navItemHtml(i, 'side-link')}
        <div class="side-subs"><div class="side-subs-in">${kids.map(navChildHtml).join('')}</div></div>
      </div>`;
  }).join('');

  /* ---- Mobile tab bar -------------------------------------------------
     Four fixed slots plus a "More" button. Earlier versions used all five
     slots for pages, which quietly stranded everything that did not fit —
     a master could not reach Users, Audit or Backup on a phone at all.
     Now anything without a slot lives in the More sheet, so every page a
     user is allowed to open is reachable on every screen size. */
  const tabs = TAB_ORDER.map(n => side.find(i => i.nav === n)).filter(Boolean).slice(0, 4);
  const rest = navFlat().filter(navAllowed).filter(i => !tabs.includes(i));
  const moreBtn = `<button class="tab-item tab-more" id="tabMore" type="button"
      aria-haspopup="dialog" aria-expanded="false">${ICON.menu || ICON.list}<span>${t('navMore')}</span></button>`;
  $('#tabBar').innerHTML = tabs.map(i => navItemHtml(i, 'tab-item')).join('')
                         + (rest.length ? moreBtn : '');
  renderMoreSheet(rest);
  initMoreSheet();

  initNavToggle();
  updateNavActive();
}

/* ---- Mobile "More" sheet -------------------------------------------
   Draws every destination that did not win a tab slot, grouped so a long
   list stays scannable: sub-items are shown under their parent's name.
   Called from renderNav(), so it is rebuilt whenever the language or the
   user's capabilities change. */
function renderMoreSheet(items){
  const body = $('#moreBody');
  if (!body) return;
  if (!items.length) { body.innerHTML = ''; return; }

  /* Child entries are labelled with their parent so "Add" and "Import"
     are not floating in the list without context. */
  const parentOf = {};
  NAV_ITEMS.forEach(p => (p.children || []).forEach(c => { parentOf[c.nav] = p; }));

  body.innerHTML = `<div class="sheet-grid">${items.map(i => {
    const p = parentOf[i.nav];
    return `<a class="sheet-item" href="${i.href}" data-nav="${i.nav}">
        <span class="sh-ic">${i.icon}</span>
        <span class="sh-tx">
          <span class="sh-nm">${t(i.labelKey)}</span>
          ${p ? `<span class="sh-pa">${t(p.labelKey)}</span>` : ''}
        </span>
      </a>`;
  }).join('')}</div>`;
}

function openMore(){
  const sh = $('#moreSheet'), sc = $('#moreScrim'), btn = $('#tabMore');
  if (!sh) return;
  sc.hidden = false; sh.hidden = false;
  /* Force a reflow so the CSS transition runs from the hidden state
     instead of being skipped because both changes land in one frame. */
  void sh.offsetHeight;
  sc.classList.add('show'); sh.classList.add('show');
  if (btn) btn.setAttribute('aria-expanded', 'true');
  document.body.style.overflow = 'hidden';      /* stop the page scrolling behind */
  const first = sh.querySelector('.sheet-item');
  if (first) first.focus({ preventScroll:true });
}

function closeMore(){
  const sh = $('#moreSheet'), sc = $('#moreScrim'), btn = $('#tabMore');
  if (!sh || sh.hidden) return;
  sh.classList.remove('show'); sc.classList.remove('show');
  if (btn) btn.setAttribute('aria-expanded', 'false');
  document.body.style.overflow = '';
  /* Wait for the slide-out before hiding, or it disappears instantly. */
  setTimeout(() => { sh.hidden = true; sc.hidden = true; }, 200);
}

/* One delegated listener for the whole sheet, installed once. */
function initMoreSheet(){
  if (initMoreSheet.done) return;
  initMoreSheet.done = true;
  document.addEventListener('click', ev => {
    if (ev.target.closest('#tabMore'))   { ev.preventDefault(); openMore();  return; }
    if (ev.target.closest('#moreClose')) { ev.preventDefault(); closeMore(); return; }
    if (ev.target.closest('#moreScrim')) { closeMore(); return; }
    /* Picking a destination closes the sheet; the hash change navigates. */
    if (ev.target.closest('.sheet-item')) closeMore();
  });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeMore(); });
  /* Growing the window past the mobile breakpoint must not leave the
     sheet stranded on screen. */
  window.addEventListener('resize', () => { if (window.innerWidth >= 1024) closeMore(); });
}

/* Groups the user has collapsed BY HAND, e.g. {students:false}. A manual
   choice outranks the automatic "open the group that owns this page"
   rule until the user navigates somewhere outside that group, at which
   point the override is dropped and automatic behaviour resumes. */
const navManual = {};

function updateNavActive(){
  const v = currentParts[0];
  const known = navFlat().some(i => i.nav === v);
  const key = known ? v : 'dashboard';

  /* Which top-level group owns the active route? A child route keeps its
     parent highlighted so you always know where you are in the tree. */
  const parent = NAV_ITEMS.find(i => i.nav === key
    || (i.children || []).some(c => c.nav === key));
  const parentKey = parent ? parent.nav : key;

  $$('.side-link, .tab-item, .side-sub, .sheet-item').forEach(n => {
    const active = n.dataset.nav === key;
    n.classList.toggle('active', active);
    if (active) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current');
  });

  /* The current page may live in the More sheet rather than on a tab —
     light up "More" so the bar never looks like nothing is selected. */
  const moreTab = $('#tabMore');
  if (moreTab) {
    const inSheet = !!document.querySelector(`.sheet-item[data-nav="${key}"]`);
    moreTab.classList.toggle('active', inSheet);
  }
  const mt = $('#moreTitle');
  if (mt) mt.textContent = t('moreTitle');

  $$('.side-group').forEach(g => {
    const name  = g.dataset.group;
    const owns  = name === parentKey;
    /* Left this group entirely? Forget any manual collapse, so coming
       back to Students later shows its children again. */
    if (!owns) delete navManual[name];
    const isOpen = owns && navManual[name] !== false;

    g.classList.toggle('open', isOpen);
    const head = g.querySelector('.side-link');
    if (head) {
      head.classList.toggle('active-parent', isOpen && head.dataset.nav !== key);
      head.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    /* Collapsed children must leave the tab order, or Tab still walks
       through links nobody can see. */
    g.querySelectorAll('.side-sub').forEach(a => {
      if (isOpen) a.removeAttribute('tabindex');
      else a.setAttribute('tabindex', '-1');
    });
  });
}

/* Clicking the parent of an OPEN group collapses it instead of
   re-navigating to a page you are already on. Any other click behaves
   like a normal link. Delegated once, at document level, because
   renderNav() replaces the sidebar's markup on every language change. */
function initNavToggle(){
  if (initNavToggle.done) return;      /* guard: renderNav runs repeatedly */
  initNavToggle.done = true;
  document.addEventListener('click', ev => {
    const head = ev.target.closest('.side-group > .side-link');
    if (!head) return;
    const group = head.parentElement;
    const name  = group.dataset.group;
    /* Only intercept when this group's own page is already showing —
       otherwise the click should navigate, which opens it anyway. */
    if (currentParts[0] !== name) return;
    ev.preventDefault();
    navManual[name] = !group.classList.contains('open');
    updateNavActive();
  });
}
function setLang(l){
  if (l === LANG) return;
  LANG = l;
  store.set(LS.LANG, LANG);
  document.cookie = 'sr_lang=' + LANG + ';path=/;max-age=31536000;samesite=lax';
  applyStaticText();
  renderNav();
  refresh(true);
  toast(LANG === 'am' ? 'ቋንቋ ተቀይሯል · አማርኛ' : 'Language changed · English');
}
window.srLang = () => LANG;

function applyStaticText(){
  document.documentElement.lang = LANG;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

  /* ---- Brand name in the top bar -------------------------------------
     The school's name is a PROPER NOUN, so it stays in Amharic even when
     the interface language is English — the building is called
     ቅዱስ ሩፋኤል, not "St. Rufael". Only the surrounding chrome translates.

     Settings → School identity → "Always use the Amharic name" (default
     ON) locks the Amharic name in the header, on the sign-in page and on
     printed output whatever the interface language; OFF lets the name
     follow the interface language. PHP reached the same verdict in
     index.php via sr_brand_name(); keep the two in step or the name will
     flip when the SPA takes over.

     Both names are editable in Settings → School identity, so you can
     change the wording without touching this file. */
  const alwaysAmharic = !!(+(DATA.settings.brand_always_amharic ?? BOOT.brand_always_amharic ?? 1));

  const schoolAm = (DATA.settings.school_name_am || BOOT.school_am || t('brand'));
  const schoolEn = (DATA.settings.school_name_en || BOOT.school_en || 'St. Rufael Church School');
  const brandName = alwaysAmharic ? (schoolAm || schoolEn)
                  : LANG === 'en'  ? (schoolEn || schoolAm)
                  : (schoolAm || schoolEn);
  set('brandName', brandName);
  set('brandSub', t('brandSub'));
  set('notifTitle', t('notifTitle'));
  set('markAllBellBtn', t('markAllRead'));
  set('viewAllAlertsBtn', t('viewAllAlerts'));
  set('langCode', LANG === 'am' ? 'አማ' : 'EN');
  set('langHead', t('langTitle'));
  set('savePillTxt', t('saved'));
  const skip = document.querySelector('[data-i18n-skip]'); if (skip) skip.textContent = t('skip');
  const sl = document.querySelector('[data-i18n-side]'); if (sl) sl.textContent = t('sideMenu');
  const yr = (DATA.year && +DATA.year.year_ec) || ET_TODAY.year;
  const footTxt = LANG === 'am'
    ? '© ' + dateNum(yr) + ' ዓ.ም · ' + brandName
    : '© ' + yr + ' · ' + brandName;
  const fn = $('#footNote'); if (fn) fn.textContent = footTxt;
  const sf = $('#sideFoot'); if (sf) sf.textContent = footTxt;
  document.title = brandName + (LANG === 'am' ? ' · የመገኘት መድረክ' : ' · Attendance Platform');
  /* account panel labels */
  $$('[data-am][data-en]').forEach(el => { el.textContent = LANG === 'am' ? el.dataset.am : el.dataset.en; });
}
function updateBell(){
  const n = DATA.alertsUnread || 0;
  const badge = $('#bellBadge');
  if (!badge) return;
  badge.textContent = n > 99 ? langNum(99) + '+' : langNum(n);
  badge.classList.toggle('zero', n === 0);
}
async function refreshAlerts(){
  if (!FEAT('attendanceAlerts') || !can('attendance.view')) return;
  try {
    const al = await API.attendance.alerts({ lang: LANG });
    DATA.alerts = al.alerts || [];
    DATA.alertsUnread = al.unread || 0;
    updateBell();
  } catch (e) { /* silent */ }
}
function renderNotifList(){
  const list = $('#notifList');
  if (!list) return;
  const alerts = DATA.alerts || [];
  const unread = alerts.filter(a => !a.read);
  const read = alerts.filter(a => a.read);
  const show = unread.concat(read.slice(0, 3)).slice(0, 9);
  if (!show.length){
    list.innerHTML = `<li class="notif-empty"><span class="ok-emoji">✓</span>${t('allCaught')}</li>`;
    return;
  }
  list.innerHTML = show.map(a => `
    <li class="notif-item ${a.read ? 'read' : ''}">
      <span class="dot" aria-hidden="true"></span>
      <div class="notif-body">
        <p class="notif-text">${esc(a.message)}</p>
        <span class="notif-time">${dLbl(a.last_date)} · ${esc(a.grade)}</span>
      </div>
      ${a.read
        ? '<span class="badge-check small-ck" aria-hidden="true">✓</span>'
        : `<button class="mini-btn mark-read" data-alert="${esc(a.key)}" type="button">${t('markRead')}</button>`}
    </li>`).join('');
  $$('.mark-read', list).forEach(b => b.addEventListener('click', e => { e.stopPropagation(); markRead(b.dataset.alert); }));
}
function closeDropdowns(){
  ['notifPanel','langPanel','userPanel'].forEach(id => { const el = document.getElementById(id); if (el) el.hidden = true; });
  ['bellBtn','langBtn','userBtn'].forEach(id => { const el = document.getElementById(id); if (el) el.setAttribute('aria-expanded', 'false'); });
}
function bindChrome(){
  const bindDD = (btnId, panelId, onOpen) => {
    const btn = $('#' + btnId), panel = $('#' + panelId);
    if (!btn || !panel) return;
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const open = panel.hidden;
      closeDropdowns();
      if (open){
        if (onOpen) onOpen();
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  };
  bindDD('bellBtn', 'notifPanel', renderNotifList);
  bindDD('langBtn', 'langPanel', () => {
    $$('.lang-opt').forEach(o => {
      const act = o.dataset.lang === LANG;
      o.classList.toggle('active-lang', act);
      const c = o.querySelector('.lang-check'); if (c) c.hidden = !act;
    });
  });
  bindDD('userBtn', 'userPanel');
  $$('.lang-opt').forEach(o => o.addEventListener('click', e => {
    e.stopPropagation();
    setLang(o.dataset.lang);
    closeDropdowns();
  }));
  document.addEventListener('click', e => { if (!e.target.closest('.dd-wrap')) closeDropdowns(); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape'){
      if ($('#cellMenu')){ closeCellMenu(); return; }
      if ($('#appModal')){ state.modal = null; mountModal(); return; }
      closeDropdowns();
    }
  });
  const mb = $('#markAllBellBtn'); mb && mb.addEventListener('click', markAllRead);
  const va = $('#viewAllAlertsBtn'); va && va.addEventListener('click', () => { closeDropdowns(); location.hash = '#/alerts'; });

  const pb = $('#profileBtn'); pb && pb.addEventListener('click', () => { closeDropdowns(); openProfileEditor(); });
  const pw = $('#pwBtn');      pw && pw.addEventListener('click', () => { closeDropdowns(); openPasswordEditor(); });
  const lo = $('#logoutBtn');
  lo && lo.addEventListener('click', async () => {
    try { await API.auth.logout(); } catch (e) {}
    location.href = 'auth/login.php';
  });
}

/* --------------------------- Account dialogs ---------------------------- */
function openProfileEditor(){
  const u = DATA.user;
  state.modal = { type:'html', html: `
    <div class="pm-head"><span class="pm-av">${esc(initials(u.full_name))}</span>
      <div class="pm-head-txt"><h3>${t('accProfile')}</h3><p class="pm-sub">${esc(u.username)}</p></div>
      <button class="pm-close" data-mclose type="button" aria-label="${t('close')}">✕</button></div>
    <div class="pm-body">
      <div class="mb-3"><label class="form-label" for="pfName">${t('fullName')}</label>
        <input class="form-control" id="pfName" value="${esc(u.full_name || '')}"></div>
      <div class="row g-2 mb-3">
        <div class="col-12 col-sm-6"><label class="form-label" for="pfEmail">${t('email')}</label>
          <input class="form-control" id="pfEmail" type="email" value="${esc(u.email || '')}"></div>
        <div class="col-12 col-sm-6"><label class="form-label" for="pfPhone">${t('phone')}</label>
          <input class="form-control" id="pfPhone" value="${esc(u.phone || '')}"></div>
      </div>
      <div class="mb-3">
        <label class="form-label">${t('numT')}</label>
        <div class="num-opts">
          ${[['mix', t('numMix'), t('numMixEg')],
             ['geez', t('numGeez'), t('numGeezEg')],
             ['arabic', t('numArabic'), t('numArabicEg')]].map(o => `
            <label class="num-opt ${NUMERALS === o[0] ? 'on' : ''}">
              <input type="radio" name="pfNum" value="${o[0]}" ${NUMERALS === o[0] ? 'checked' : ''}>
              <span class="num-o-t">${esc(o[1])}</span>
              <span class="num-o-e">${esc(o[2])}</span>
            </label>`).join('')}
        </div>
        <div class="form-text">${t('numHint')}</div>
      </div>
      <div class="d-flex gap-2"><button class="btn btn-primary" id="pfSave" type="button">${t('save')}</button>
        <button class="btn btn-ghost" data-mclose type="button">${t('cancel')}</button></div>
    </div>` };
  mountModal();
  $$('input[name="pfNum"]').forEach(r => r.addEventListener('change', () => {
    $$('.num-opt').forEach(l => l.classList.toggle('on', l.contains(r) ? r.checked : l.querySelector('input').checked));
  }));
  const b = $('#pfSave');
  b && b.addEventListener('click', async () => {
    try {
      const picked = $('input[name="pfNum"]:checked');
      const nums = picked ? picked.value : NUMERALS;
      const res = await API.auth.updateProfile({
        full_name: $('#pfName').value, email: $('#pfEmail').value,
        phone: $('#pfPhone').value, numerals: nums
      });
      DATA.user = res.user || DATA.user;
      if (nums !== NUMERALS) {
        NUMERALS = nums; store.set(LS.NUM, nums);
        toast(t('profSaved'));
        state.modal = null; mountModal();
        refresh(true);          /* redraw every number with the new rule */
        return;
      }
      const ini = $('#userInitial'); if (ini) ini.textContent = initials(DATA.user.full_name);
      const nm = $('#userName'); if (nm) nm.textContent = DATA.user.full_name;
      toast(t('profSaved'));
      state.modal = null; mountModal();
    } catch (e) { fail(e); }
  });
}
function openPasswordEditor(){
  state.modal = { type:'html', html: `
    <div class="pm-head"><span class="pm-av">${ICON.shield}</span>
      <div class="pm-head-txt"><h3>${t('accPassword')}</h3></div>
      <button class="pm-close" data-mclose type="button" aria-label="${t('close')}">✕</button></div>
    <div class="pm-body">
      <div class="mb-3"><label class="form-label" for="pwOld">${t('pwOld')}</label>
        <input class="form-control" id="pwOld" type="password" autocomplete="current-password"></div>
      <div class="mb-3"><label class="form-label" for="pwNew">${t('pwNew')}</label>
        <input class="form-control" id="pwNew" type="password" autocomplete="new-password"></div>
      <div class="mb-3"><label class="form-label" for="pwNew2">${t('pwNew2')}</label>
        <input class="form-control" id="pwNew2" type="password" autocomplete="new-password"></div>
      <div class="d-flex gap-2"><button class="btn btn-primary" id="pwSave" type="button">${t('save')}</button>
        <button class="btn btn-ghost" data-mclose type="button">${t('cancel')}</button></div>
    </div>` };
  mountModal();
  const b = $('#pwSave');
  b && b.addEventListener('click', async () => {
    const a = $('#pwNew').value, c = $('#pwNew2').value;
    if (a !== c){ toast(t('pwMismatch'), 'error'); return; }
    try {
      await API.auth.changePassword({ current_password: $('#pwOld').value, new_password: a, confirm_password: c });
      toast(t('pwSaved'));
      state.modal = null; mountModal();
    } catch (e) { fail(e); }
  });
}

/* ------------------------------ Sync chip -------------------------------- */
function setSync(kind, n){
  const chip = $('#syncChip');
  if (!chip) return;
  const label = $('#syncTxt') || chip;
  chip.classList.remove('off','pend','sync','ok','err');
  let txt = '';
  if (kind === 'offline'){ chip.classList.add('off'); txt = t('syncOffline'); }
  else if (kind === 'pending'){ chip.classList.add('pend'); txt = I18N[LANG].syncPending(n || 0); }
  else if (kind === 'syncing'){ chip.classList.add('sync'); txt = t('syncSyncing'); }
  else if (kind === 'synced'){ chip.classList.add('ok'); txt = t('syncDone'); }
  else if (kind === 'failed'){ chip.classList.add('err'); txt = t('syncFail'); }
  else { txt = t('syncOnline'); }
  label.textContent = txt;
  chip.hidden = (kind === 'online' && !n);
  if (kind === 'synced') setTimeout(() => { if (!API.attendance.pending()) chip.hidden = true; }, 2600);
}
function bindSync(){
  API.on('offline', () => setSync('offline'));
  API.on('online',  () => { const p = API.attendance.pending(); setSync(p ? 'pending' : 'online', p); });
  API.on('queued',  d => setSync('pending', d.pending));
  API.on('queue',   d => { if (d.pending) setSync('pending', d.pending); });
  API.on('syncing', () => setSync('syncing'));
  API.on('synced',  d => { if (d.failed) setSync('pending', d.failed); else { setSync('synced'); refresh(true); } });
  API.on('syncfail', () => setSync('failed'));
  if (!navigator.onLine) setSync('offline');
  else {
    const p = API.attendance.pending();
    if (p){ setSync('pending', p); API.attendance.flush().catch(() => {}); }
  }
}

/* ------------------------------- Init ------------------------------------ */
let inited = false;
async function init(){
  if (inited) return;
  inited = true;

  document.cookie = 'sr_lang=' + LANG + ';path=/;max-age=31536000;samesite=lax';
  applyStaticText();
  renderNav();
  bindChrome();
  bindSync();
  window.addEventListener('hashchange', route);
  if (!location.hash || location.hash === '#') history.replaceState(null, '', '#/dashboard');

  /* one boot round-trip: grades + years + settings */
  try {
    const [g, y] = await Promise.all([
      API.grades.list({ with_counts: 1 }).catch(() => ({ grades: [] })),
      API.settings.years().catch(() => ({ years: [], current: null }))
    ]);
    setGrades(g.grades || []);
    DATA.years = y.years || [];
    DATA.year  = y.current || DATA.year;
    if (DATA.year) state.openYear = +DATA.year.year_ec;
    DATA.loaded = true;
    applyStaticText();
  } catch (e) { /* the view layer will show its own error */ }

  await route();
  refreshAlerts();
  setInterval(refreshAlerts, 180000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
