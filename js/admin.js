/* ============================================================
   St. Rufael Church School — Administrative views
   js/admin.js  ·  loaded AFTER js/app.js

   app.js owns the shell (chrome, router, register, alerts).
   This file contributes the management pages by registering
   them on window.SR_VIEWS, and binds them through the two
   hooks app.js calls:

       window.srBindAdminView(parts, viewEl)   after every mount
       window.srBindModal()                    after every modal mount

   Everything here is presentation only. Each action goes back
   to api/*.php, which re-checks the session, the capability
   and (for teachers) the assigned grades on the server.
   ============================================================ */
(function () {
  'use strict';

  /* ------------------------------------------------------------------ */
  /*  Local state — one bag per page, kept across re-renders             */
  /* ------------------------------------------------------------------ */
  const A = {
    stu : { q:'', grade_id:'', status:'', page:1, per_page:50, sel:{}, res:null },
    frm : { busy:false },
    set : { data:null, busy:false },
    imp : { report:null, token:'', filename:'', skip:{}, busy:false, batches:[] },
    pro : { plan:null, summary:null, toEc:null, grade_id:'', def:'passed', tab:'plan', history:null, res:{} },
    usr : { q:'', role:'', deleted:false, list:null },
    tch : { list:null },
    aud : { page:1, per_page:50, f:{ action_type:'', entity_type:'', role:'', q:'', from:'', to:'' }, filters:null, res:null },
    bak : { list:[], preview:null, pending:null, busy:false },
    prm : { tab:'roles', role:'teacher', matrix:null, draft:null, userId:'', userData:null, userDraft:null, users:null },
    cor : { q:'', gradeId:'', studentId:'', date:TODAY_ISO, to:'', reason:'', note:'', last:null },
    hist: { q:'', gradeId:'', studentId:'', yearEc:'' },
    modal: null,
    focus: null
  };

  /* ------------------------------------------------------------------ */
  /*  Tiny helpers (esc, t, ICON, can, FEAT, toast… come from app.js)    */
  /* ------------------------------------------------------------------ */
  const lang  = () => (window.srLang ? window.srLang() : 'am');
  const am    = () => lang() === 'am';
  const L     = (a, e) => (am() ? a : e);
  /* counts honour the user's numeral preference (app.js owns the rule) */
  const num   = n => langNum(Number(n) || 0);
  const dnum  = n => (window.srDateNum ? window.srDateNum(Number(n) || 0) : String(Number(n) || 0));
  const val   = id => { const el = $('#' + id); return el ? el.value.trim() : ''; };
  const chk   = id => { const el = $('#' + id); return !!(el && el.checked); };
  const busy  = (btn, on) => { if (btn) { btn.disabled = !!on; btn.classList.toggle('is-busy', !!on); } };

  /* "2026-08-17 15:31:04" → Ethiopian label + clock */
  function stamp(sqlDt) {
    if (!sqlDt) return '—';
    const iso = String(sqlDt).slice(0, 10);
    const hm  = String(sqlDt).slice(11, 16);
    return esc(isoLabel(iso, false)) + (hm ? ' · ' + hm : '');
  }

  function pageTop(title, sub, right) {
    return `<div class="page-top">
      <div class="sec-head first mt-0 mb-0"><h2>${title}</h2>${sub ? `<p>${sub}</p>` : ''}</div>
      ${right || ''}
    </div>`;
  }
  /* A plain content card. `.set-card` itself carries no padding (the
     settings page paints its own header band), so the body must supply
     it — otherwise every admin card renders flush against its border. */
  function card(title, sub, body, extraCls) {
    const head = (title || sub)
      ? `<header class="set-head plain"><div class="set-hx">
           ${title ? `<h3>${title}</h3>` : ''}
           ${sub ? `<p>${sub}</p>` : ''}
         </div></header>`
      : '';
    return `<section class="card set-card ${extraCls || ''}">
      ${head}<div class="set-body">${body}</div>
    </section>`;
  }
  function emptyCard(msg) {
    return `<div class="card card-pad text-center nf-card"><p class="text-muted nf-sub mb-0">${msg}</p></div>`;
  }
  /* Empty state for a table that already lives inside a card — the
     boxed `emptyCard` would nest a card in a card and leave a gap. */
  function emptyInline(msg) {
    return `<p class="tbl-empty">${msg}</p>`;
  }
  /* A table rendered inside an existing card body. */
  function tblIn(head, body, minEmpty) {
    if (!body) return emptyInline(minEmpty || L('ምንም መረጃ የለም።', 'Nothing to show yet.'));
    return `<div class="tbl-in table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr>${head}</tr></thead><tbody>${body}</tbody>
      </table></div>`;
  }
  function tblCard(head, body, minEmpty, id) {
    if (!body) return emptyCard(minEmpty || L('ምንም መረጃ የለም።', 'Nothing to show yet.'));
    return `<div class="card tbl-card"><div class="table-responsive">
      <table class="table table-hover align-middle mb-0"${id ? ` id="${id}"` : ''}>
        <thead><tr>${head}</tr></thead><tbody>${body}</tbody>
      </table></div></div>`;
  }

  const GRADE_NAME = g => (am() ? g.name_am : g.name_en);
  function gradeOptions(selected, blankLabel) {
    const list = DATA.grades || [];
    return `<option value="">${esc(blankLabel || L('ሁሉም ክፍሎች', 'All grades'))}</option>` +
      list.map(g => `<option value="${g.id}" ${String(selected) === String(g.id) ? 'selected' : ''}>${esc(GRADE_NAME(g))}</option>`).join('');
  }
  const STATUS_KEYS_S = ['active', 'inactive', 'graduated', 'transferred'];
  const statusLbl = s => t(s === 'active' ? 'stActive' : s === 'inactive' ? 'stInactive'
    : s === 'graduated' ? 'stGraduated' : s === 'transferred' ? 'stTransferred' : 'stActive');
  const statusPill = s => `<span class="pill ${s === 'active' ? 'g' : s === 'graduated' ? 'b' : 'n'}">${esc(statusLbl(s))}</span>`;
  const genderLbl = g => t(g === 'female' ? 'gFemale' : 'gMale');
  const roleLbl = r => {
    if (r === 'master') return t('roleMaster');
    if (r === 'admin')  return t('roleAdmin');
    if (r === 'teacher') return t('roleTeacher');
    if (r === 'editor') return t('roleTeacher');   /* legacy accounts read as teachers */
    r = String(r || '');
    return r ? r.charAt(0).toUpperCase() + r.slice(1) : t('roleTeacher');
  };
  /* Every role that exists (filters/labels) vs. the roles this signed-in
     user may hand out. 'master' appears in dropdowns only for master. */
  const SRB = window.SR_BOOT || {};
  const ROLE = (SRB.user && SRB.user.role) || '';
  const isMaster = () => ROLE === 'master';
  const ALL_ROLES = (SRB.roles && SRB.roles.length ? SRB.roles.slice().reverse() : ['master', 'admin', 'teacher'])
      .filter(r => r !== 'master' || isMaster());
  const ASSIGNABLE_ROLES = (SRB.assignable_roles && SRB.assignable_roles.length ? SRB.assignable_roles : ['teacher']);

  /* The preview plan speaks in the infinitive (promote | repeat | graduate |
     skip); the saved promotions row keeps the past tense (promoted |
     repeated | graduated). Normalise both to one label. */
  const ACT_CANON = {
    promote: 'promote', promoted: 'promote',
    repeat: 'repeat',   repeated: 'repeat',
    graduate: 'graduate', graduated: 'graduate',
    skip: 'skip',       skipped: 'skip',
    withhold: 'withhold', withheld: 'withhold', not_promoted: 'withhold'
  };
  const ACT_AM = { promote: 'ተሳድጓል', repeat: 'ይደግማል', graduate: 'ተመርቋል', withhold: 'አልተሳደገም', skip: 'ተዘሏል' };
  const ACT_EN = { promote: 'Promote', repeat: 'Repeat', graduate: 'Graduate', withhold: 'Not promoted', skip: 'Skip' };
  const actKey = a => ACT_CANON[String(a || '').toLowerCase()] || '';
  const actLbl = a => (am() ? ACT_AM[actKey(a)] : ACT_EN[actKey(a)]) || a || '—';
  const actCls = a => {
    const k = actKey(a);
    return k === 'promote' ? 'g' : k === 'repeat' ? 'late' : k === 'graduate' ? 'b' : k === 'withhold' ? 'r' : 'n';
  };

  /* keep the caret where the user left it when a keystroke triggers a refresh */
  function rememberFocus(el) { A.focus = el && el.id ? el.id : null; }
  function restoreFocus() {
    if (!A.focus) return;
    const el = $('#' + A.focus);
    if (!el) { A.focus = null; return; }
    el.focus();
    try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) { /* search inputs */ }
  }
  let debT = null;
  const debounce = fn => { clearTimeout(debT); debT = setTimeout(fn, 320); };

  /* ------------------------------------------------------------------
     Live student search — the dashboard's feel, for the History and
     Correction pickers. Matches appear in a dropdown under the box once
     the user stops typing; nothing underneath re-renders, so a fuzzy
     multi-word query like "a s" or "አ ሽ" can be typed in full. Picking
     a row hands the student to onPick, which navigates.
     ------------------------------------------------------------------ */
  function lsqLive(cfg) {
    const q = cfg.input, pop = cfg.pop;
    if (!q || !pop) return;
    let tmr = null, items = [], sel = -1;

    const close = () => { pop.hidden = true; sel = -1;
      q.setAttribute('aria-expanded', 'false'); };
    const show = () => { pop.hidden = false;
      q.setAttribute('aria-expanded', 'true'); };
    const paint = () => {
      if (!items.length) {
        pop.innerHTML = `<div class="lsq-empty">${esc(L('ማንም አልተገኘም።', 'No student matched.'))}</div>`;
        show(); return;
      }
      pop.innerHTML = items.map((st, i) => `
        <button type="button" class="lsq-item ${i === sel ? 'is-on' : ''}" data-id="${st.id}"
                data-i="${i}" role="option" aria-selected="${i === sel}">
          <span class="lsq-av" aria-hidden="true">${esc((st.full_name || '?').trim().charAt(0))}</span>
          <span class="lsq-main">
            <strong>${esc(st.full_name)}</strong>
            <span class="lsq-sub">${esc(st.student_code || '—')}${st.grade_am ? ' · ' + esc(am() ? st.grade_am : st.grade_en) : ''}</span>
          </span>
        </button>`).join('');
      show();
    };
    const run = async () => {
      const qv = q.value.trim();
      if (!qv && !(cfg.grade && cfg.grade.value)) { close(); return; }
      try {
        const res = await API.students.list({
          q: qv, grade_id: cfg.grade ? (cfg.grade.value || '') : '', per_page: 8 });
        items = res.students || []; sel = -1;
        paint();
      } catch (e) {
        items = [];
        pop.innerHTML = `<div class="lsq-empty">${esc((e && e.message) || String(e))}</div>`;
        show();
      }
    };

    q.addEventListener('input', () => { clearTimeout(tmr); tmr = setTimeout(run, 300); });
    q.addEventListener('focus', () => { if (q.value.trim() || (cfg.grade && cfg.grade.value)) run(); });
    /* Click (or tap) a result — hands that student to onPick. */
    pop.addEventListener('click', e => {
      const it = e.target.closest('.lsq-item');
      if (!it) return;
      const pick = items[+it.dataset.i] || items.find(x => String(x.id) === String(it.dataset.id));
      if (pick) { close(); cfg.onPick(pick); }
    });
    q.addEventListener('keydown', e => {
      if (pop.hidden) { if (e.key === 'Enter') e.preventDefault(); return; }
      const n = items.length;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!n) return;
        sel = e.key === 'ArrowDown' ? (sel + 1) % n : (sel - 1 + n) % n;
        const lis = pop.querySelectorAll('.lsq-item');
        lis.forEach((el, i) => { el.classList.toggle('is-on', i === sel); el.setAttribute('aria-selected', i === sel ? 'true' : 'false'); });
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const pick = items[sel] || items[0];
        if (pick) { close(); cfg.onPick(pick); }
      } else if (e.key === 'Escape') { close(); }
    });
    if (cfg.grade) cfg.grade.addEventListener('change', () => { clearTimeout(tmr); run(); });
    document.addEventListener('click', e => {
      if (!pop.contains(e.target) && !q.contains(e.target)) close();
    });
  }

  /* The import validator returns errors as {field, message} objects and
     warnings as plain strings — flatten either shape to one line. */
  function msgList(list) {
    return (list || []).map(x => {
      if (typeof x === 'string') return x;
      if (x && x.message) return x.field ? x.field + ': ' + x.message : x.message;
      return String(x);
    }).join(' · ');
  }

  /* ------------------------------------------------------------------ */
  /*  Generic form modal — rendered through app.js' modal machinery      */
  /* ------------------------------------------------------------------ */
  function openModal(opts) {
    /* opts = { title, sub, body, submit, cancel, onSubmit(form), bind(), danger } */
    A.modal = opts;
    state.modal = {
      type: 'html',
      html: `
        <div class="pm-head">
          <div class="pm-head-txt"><h3>${opts.title}</h3>${opts.sub ? `<p class="pm-sub">${opts.sub}</p>` : ''}</div>
          <button class="pm-close" type="button" data-mclose aria-label="${esc(t('close'))}">✕</button>
        </div>
        <div class="pm-body">
          <form id="amForm" novalidate>
            ${opts.body}
            <div class="d-flex gap-2 flex-wrap mt-3">
              <button class="btn ${opts.danger ? 'btn-ghost pm-del' : 'btn-primary'}" type="submit" id="amSubmit">${opts.submit || t('save')}</button>
              <button class="btn btn-ghost" type="button" data-mclose>${opts.cancel || t('cancel')}</button>
            </div>
          </form>
        </div>`
    };
    window.srMountModal();
  }
  function closeModal() { A.modal = null; state.modal = null; window.srMountModal(); }

  /* ------------------------------------------------------------------ */
  /*  Credential hand-off — the one place a generated password is shown  */
  /* ------------------------------------------------------------------ */
  function credentialModal(o) {
    /* o = { title, sub, username, password, note, onClose } */
    A.modal = { kind: 'cred', onClose: o.onClose || null };
    const both = o.username + ' / ' + o.password;
    state.modal = {
      type: 'html',
      html: `
        <div class="pm-head">
          <span class="pm-av cred-av" aria-hidden="true">${ICON.key || '🔑'}</span>
          <div class="pm-head-txt"><h3>${esc(o.title || t('cdTitle'))}</h3>
            <p class="pm-sub">${esc(o.sub || t('cdSub'))}</p></div>
          <button class="pm-close" type="button" data-mclose aria-label="${esc(t('close'))}">✕</button>
        </div>
        <div class="pm-body">
          <div class="cred-grid">
            <div class="cred-row">
              <span class="cred-k">${esc(t('cdUser'))}</span>
              <code class="cred-v" id="credUser">${esc(o.username)}</code>
              <button class="btn btn-ghost cred-copy" type="button" data-copy="${esc(o.username)}">${ICON.copy || ''} ${esc(t('cdCopy'))}</button>
            </div>
            <div class="cred-row cred-row-pw">
              <span class="cred-k">${esc(t('cdPw'))}</span>
              <code class="cred-v cred-pw" id="credPw">${esc(o.password)}</code>
              <button class="btn btn-primary cred-copy" type="button" data-copy="${esc(o.password)}">${ICON.copy || ''} ${esc(t('cdCopy'))}</button>
            </div>
          </div>
          <p class="cred-note">${esc(o.note || t('cdNote'))}</p>
          <div class="cred-foot">
            <button class="btn btn-soft cred-copy" type="button" data-copy="${esc(both)}">${esc(t('cdCopyBoth'))}</button>
            <button class="btn btn-primary ms-auto" type="button" data-mclose>${esc(t('cdDone'))}</button>
          </div>
        </div>`
    };
    window.srMountModal();
  }

  /* Clipboard with a document.execCommand fallback — the app is often
     served over plain http on a LAN, where navigator.clipboard is absent. */
  function copyText(txt) {
    const done = () => toast(t('cdCopied'));
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(txt).then(done, () => fallback());
      return;
    }
    fallback();
    function fallback() {
      const ta = document.createElement('textarea');
      ta.value = txt;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); }
      catch (e) { toast(txt, 'error'); }
      ta.remove();
    }
  }

  window.srBindModal = function () {
    /* copy buttons live in the credential modal, which has no form */
    $$('.cred-copy').forEach(b => b.addEventListener('click', () => {
      copyText(b.dataset.copy || '');
      const old = b.innerHTML;
      b.innerHTML = '✓ ' + esc(t('cdCopied'));
      setTimeout(() => { b.innerHTML = old; }, 1400);
    }));
    const pw = $('#credPw');
    if (pw) { try { pw.focus(); } catch (e) { /* no-op */ } }

    const f = $('#amForm');
    if (!f || !A.modal) return;
    const o = A.modal;
    f.addEventListener('submit', async ev => {
      ev.preventDefault();
      const b = $('#amSubmit');
      busy(b, true);
      try { await o.onSubmit(f); } catch (e) { fail(e); } finally { busy(b, false); }
    });
    if (typeof o.bind === 'function') o.bind(f);
  };

  /* ================================================================== */
  /*  1 · STUDENTS — list, filter, export, ID cards                      */
  /* ================================================================== */
  async function renderStudentsList() {
    const s = A.stu;
    /* Dashboard-style list: fetch the roster once, then filter in place —
       every keystroke just toggles rows, no reload and no scroll jump. */
    if (!s.all) {
      const res = await API.students.list({ per_page: 500 });
      s.all = res.students || [];
      s.tot = res.total || 0;
    }
    const rows = s.all;
    const selected = Object.keys(s.sel).filter(k => s.sel[k]);
    const canExport = FEAT('studentExport') && can('export.students');
    /* Row selection drives two things: bulk delete and "print selected
       ID cards". Anyone allowed to do either needs the checkboxes. */
    const canCards  = FEAT('studentIdCard') && can('idcard.view');
    const canSelect = can('students.delete') || canCards;

    const filters = `
      <section class="card card-pad mb-3">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-lg-5">
            <label class="form-label" for="stuQ">${L('ፍለጋ', 'Search')}</label>
            <input class="form-control" id="stuQ" type="search" placeholder="${esc(t('stuSearchPh'))}" value="${esc(s.q)}" autocomplete="off">
          </div>
          <div class="col-6 col-lg-3">
            <label class="form-label" for="stuGrade">${t('stuFilterGrade')}</label>
            <select class="form-select" id="stuGrade">${gradeOptions(s.grade_id)}</select>
          </div>
          <div class="col-6 col-lg-2">
            <label class="form-label" for="stuStatus">${t('stuFilterStatus')}</label>
            <select class="form-select" id="stuStatus">
              <option value="">${esc(t('all'))}</option>
              ${STATUS_KEYS_S.map(k => `<option value="${k}" ${s.status === k ? 'selected' : ''}>${esc(statusLbl(k))}</option>`).join('')}
            </select>
          </div>
          <div class="col-6 col-lg-2">
            <button class="btn btn-ghost btn-field w-100" id="stuReset" type="button">${L('አጽዳ', 'Reset')}</button>
          </div>
        </div>
        ${(canExport || canCards || can('students.delete')) ? `
        <hr class="my-3">
        <div class="d-flex gap-2 flex-wrap align-items-center">
          ${canExport ? `
          <span class="set-sub mb-0 me-1">${t('exportT')}${selected.length ? ` · ${num(selected.length)} ${L('የተመረጡ', 'selected')}` : ''}:</span>
          <button class="btn btn-ghost" data-ex="csv" type="button">${ICON.down} ${t('exportCsv')}</button>
          ${FEAT('excelExport') ? `<button class="btn btn-ghost" data-ex="xlsx" type="button">${ICON.down} ${t('exportExcel')}</button>` : ''}
          <button class="btn btn-ghost" data-ex="json" type="button">${ICON.down} ${t('exportJson')}</button>
          ${FEAT('pdfExport') ? `<button class="btn btn-ghost" data-ex="pdf" type="button">${t('exportPdf')}</button>` : ''}` : ''}
          ${canCards ? `<button class="btn btn-soft" id="stuCards" type="button">${ICON.card} ${t('printCards')}</button>` : ''}
          ${canCards && selected.length ? `<button class="btn btn-primary" id="stuCardsSel" type="button">${ICON.card} ${L(`የተመረጡትን አትም · ${num(selected.length)}`, `Print selected · ${num(selected.length)}`)}</button>` : ''}
          ${can('students.delete') && selected.length ? `<button class="btn btn-ghost pm-del ms-auto" id="stuBulkDel" type="button">${t('del')} · ${num(selected.length)}</button>` : ''}
        </div>` : ''}
      </section>`;

    const head = `
      ${canSelect ? '<th style="width:34px"><input class="form-check-input" type="checkbox" id="stuAll" aria-label="all"></th>' : ''}
      <th>${esc(t('pmId'))}</th>
      <th>${esc(t('thStudent'))}</th>
      <th>${esc(t('thClass'))}</th>
      <th>${esc(t('asGender'))}</th>
      <th>${esc(t('thStatus'))}</th>
      <th class="text-end">${esc(t('thAction'))}</th>`;

    const body = rows.map(st => `
      <tr data-sid="${st.id}" data-grade="${st.grade_id || ''}" data-status="${st.status || 'active'}"
          data-q="${esc(([st.full_name, st.baptism_name, st.student_code, st.guardian_name, st.phone,
                          am() ? st.grade_am : st.grade_en, st.grade_am, st.grade_en])
                          .filter(Boolean).join(' ').toLowerCase())}">
        ${canSelect ? `<td><input class="form-check-input stu-ck" type="checkbox" data-id="${st.id}" ${s.sel[st.id] ? 'checked' : ''} aria-label="${esc(st.full_name)}"></td>` : ''}
        <td><span class="pm-b-sub">${esc(st.student_code)}</span></td>
        <td>
          <a href="#" class="stu-open" data-id="${st.id}" data-gid="${st.grade_id || ''}"><strong>${esc(st.full_name)}</strong></a>
          ${st.baptism_name ? `<div class="pm-b-sub">${esc(st.baptism_name)}</div>` : ''}
          ${st.alert ? `<span class="pill absent mt-1">${num(st.absence_streak)} ${L('ተከታታይ ቀናት', 'days in a row')}</span>` : ''}
        </td>
        <td>${esc(am() ? (st.grade_am || '') : (st.grade_en || ''))}${st.roll_no ? `<div class="pm-b-sub">${esc(t('pmRoll'))} ${num(st.roll_no)}</div>` : ''}</td>
        <td>${esc(genderLbl(st.gender))}</td>
        <td>${statusPill(st.status)}</td>
        <td class="text-end">
          <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
            <button class="mini-btn stu-open" data-id="${st.id}" data-gid="${st.grade_id || ''}" type="button">${L('ክፈት', 'Open')}</button>
            ${can('students.edit') ? `<button class="mini-btn stu-edit" data-id="${st.id}" type="button">✎ ${esc(t('edit'))}</button>` : ''}
            ${FEAT('studentIdCard') && can('idcard.view') ? `<button class="mini-btn stu-card" data-id="${st.id}" type="button">${esc(t('idCard'))}</button>` : ''}
            ${can('students.delete') ? `<button class="mini-btn stu-del" data-id="${st.id}" data-name="${esc(st.full_name)}" type="button">${esc(t('del'))}</button>` : ''}
          </div>
        </td>
      </tr>`).join('');

    const noneKey = t('stuEmpty');
    const noneRow = `<tr id="stuNone" hidden><td colspan="${canSelect ? 7 : 6}" class="text-center pm-b-sub py-4">${esc(noneKey)}</td></tr>`;

    return pageTop(t('stuT'), t('stuSub'),
        can('students.create') ? `<button class="btn btn-primary" id="addStudentBtn" type="button">${ICON.plus} ${t('stuNew')}</button>` : '')
      + filters
      + tblCard(head, body + noneRow, '', 'stuTbl')
      + `<p class="set-sub mt-3 mb-0" id="stuCount">${esc(stuCountText())}</p>`;
  }

  function stuCountText() {
    const s = A.stu;
    const q = s.q.trim().toLowerCase();
    const gid = String(s.grade_id || '');
    const all = s.all || [];
    let vis = 0;
    all.forEach(st => {
      const hay = ([st.full_name, st.baptism_name, st.student_code, st.guardian_name, st.phone,
                    st.grade_am, st.grade_en]).filter(Boolean).join(' ').toLowerCase();
      const ok = (!q || hay.indexOf(q) !== -1)
              && (!gid || String(st.grade_id || '') === gid)
              && (!s.status || (st.status || 'active') === s.status);
      if (ok) vis++;
    });
    return vis === all.length ? I18N[lang()].stuCount(vis)
      : L(`የተገኙት · ${num(vis)} / ${num(all.length)}`,
          `Showing ${num(vis)} of ${num(all.length)}`);
  }

  /* Toggle rows against the current filter — the dashboard pattern, so the
     page never reloads and the input keeps the focus. */
  function applyStuFilter() {
    const s = A.stu;
    const q = s.q.trim().toLowerCase();
    const gid = String(s.grade_id || '');
    const table = $('#stuTbl');
    if (!table) return;
    let vis = 0;
    $$('tbody tr[data-sid]', table).forEach(tr => {
      const ok = (!q || (tr.dataset.q || '').indexOf(q) !== -1)
              && (!gid || tr.dataset.grade === gid)
              && (!s.status || tr.dataset.status === s.status);
      tr.classList.toggle('match-hide', !ok);
      if (ok) vis++;
    });
    const none = $('#stuNone');
    if (none) none.hidden = vis > 0;
    const c = $('#stuCount');
    if (c) c.textContent = vis === (s.all || []).length
      ? I18N[lang()].stuCount(vis)
      : L(`የተገኙት · ${num(vis)} / ${num(s.all ? s.all.length : 0)}`,
          `Showing ${num(vis)} of ${num(s.all ? s.all.length : 0)}`);
  }

  function bindStudentsList() {
    const q = $('#stuQ');
    q && q.addEventListener('input', e => { A.stu.q = e.target.value; applyStuFilter(); });
    const g = $('#stuGrade');
    g && g.addEventListener('change', e => { A.stu.grade_id = e.target.value; applyStuFilter(); });
    const stt = $('#stuStatus');
    stt && stt.addEventListener('change', e => { A.stu.status = e.target.value; applyStuFilter(); });
    const rst = $('#stuReset');
    rst && rst.addEventListener('click', () => {
      A.stu.q = ''; A.stu.grade_id = ''; A.stu.status = ''; A.stu.sel = {}; A.focus = null;
      const qi = $('#stuQ'); if (qi) qi.value = '';
      const gi = $('#stuGrade'); if (gi) gi.value = '';
      const si = $('#stuStatus'); if (si) si.value = '';
      applyStuFilter();
    });

    $$('.stu-open').forEach(b => b.addEventListener('click', e => {
      e.preventDefault();
      window.srOpenProfile(b.dataset.id, b.dataset.gid, state.openYear);
    }));
    $$('.stu-edit').forEach(b => b.addEventListener('click', () => { location.hash = '#/students/' + b.dataset.id; }));
    $$('.stu-card').forEach(b => b.addEventListener('click', () => {
      API.openPrint('idcards', { ids: [b.dataset.id], lang: lang() });
    }));
    $$('.stu-del').forEach(b => b.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ተማሪ ማስወገድ', 'Remove student'),
                               message: I18N[lang()].removeConfirm(b.dataset.name),
                               danger: true, okLabel: L('አስወግድ', 'Remove') }))) return;
      try { await API.students.remove(b.dataset.id); A.stu.all = null; toast(t('removedToast')); refresh(true); }
      catch (e) { fail(e); }
    }));

    $$('.stu-ck').forEach(c => c.addEventListener('change', () => {
      A.stu.sel[c.dataset.id] = c.checked;
      refresh(true);
    }));
    const all = $('#stuAll');
    all && all.addEventListener('change', () => {
      ($$('.stu-ck') || []).forEach(c => {
        if (c.closest('tr') && c.closest('tr').classList.contains('match-hide')) return;
        A.stu.sel[c.dataset.id] = all.checked;
      });
      refresh(true);
    });

    const selIds = () => Object.keys(A.stu.sel).filter(k => A.stu.sel[k]);
    const exParams = () => {
      const ids = selIds();
      const p = { lang: lang() };
      if (ids.length) p.ids = ids;
      else { if (A.stu.grade_id) p.grade_id = A.stu.grade_id; if (A.stu.status) p.status = A.stu.status; if (A.stu.q) p.q = A.stu.q; }
      return p;
    };
    $$('[data-ex]').forEach(b => b.addEventListener('click', () => {
      const fmt = b.dataset.ex;
      const p = Object.assign({ format: fmt }, exParams());
      if (fmt === 'pdf') API.openPrint('students', p);
      else API.download('students', p);
      toast(L('ማውረድ ተጀምሯል…', 'Download started…'));
    }));
    /* Top button = every student the current filter matches, never the
       ticked rows; the separate button below prints only the selection. */
    const cards = $('#stuCards');
    cards && cards.addEventListener('click', () => {
      const p = { lang: lang() };
      if (A.stu.grade_id) p.grade_id = A.stu.grade_id;
      if (A.stu.status)   p.status   = A.stu.status;
      if (A.stu.q)        p.q        = A.stu.q;
      API.openPrint('idcards', p);
    });
    const cardsSel = $('#stuCardsSel');
    cardsSel && cardsSel.addEventListener('click', () => {
      const ids = selIds();
      if (!ids.length) return;
      API.openPrint('idcards', { lang: lang(), ids });
    });

    /* re-apply the current filter whenever the page re-renders */
    applyStuFilter();

    const bd = $('#stuBulkDel');
    bd && bd.addEventListener('click', async () => {
      const ids = selIds();
      if (!ids.length) return;
      if (!(await srConfirm({ title: L('የተመረጡ ተማሪዎች ማስወገድ', 'Remove selected students'),
                               message: L(`${num(ids.length)} ተማሪዎችን ማሰረዝ ይፈልጋሉ? የመገኘት መዝገባቸው ተጠብቆ ይቆያል።`,
                                 `Remove ${ids.length} students? Their attendance history is kept.`),
                               danger: true, okLabel: L('አስወግድ', 'Remove') }))) return;
      try {
        const r = await API.students.bulkDelete(ids);
        toast(r._message || t('removedToast'));
        A.stu.sel = {}; A.stu.all = null;
        refresh(true);
      } catch (e) { fail(e); }
    });
  }

  /* ================================================================== */
  /*  2 · STUDENT FORM — create (#/add) and edit (#/students/:id)         */
  /* ================================================================== */
  async function renderStudentForm(id, preGrade) {
    let s = { status: 'active', gender: 'male' };
    let detail = null;
    if (id) {
      detail = await API.students.get(id, { year_ec: state.openYear });
      s = detail.student || {};
      const enr = (detail.enrollments || []).find(e => +e.year_ec === +state.openYear) || (detail.enrollments || [])[0] || {};
      s.grade_id = enr.grade_id || '';
      s.roll_no  = enr.roll_no  || '';
    } else if (preGrade) {
      s.grade_id = preGrade;
    }
    const editing = !!id;
    const v = k => esc(s[k] === null || s[k] === undefined ? '' : s[k]);

    /* Student ID + roll number are server-generated unless the school has
       switched manual entry back on in config/config.php. When locked,
       the boxes are still rendered — hiding them would leave the
       registrar wondering where the ID went — just read-only and greyed,
       showing "auto" until the server fills them in. */
    const MANUAL_IDS = !!BOOT.manual_ids;
    const autoPh = L('ራስ-ሰር', 'auto');

    /* Existing photo, if any. A cache-buster is unnecessary because
       sr_save_photo() gives every new upload a fresh random filename. */
    const photoUrl = s.photo_path ? String(s.photo_path) : '';

    const gradeSel = `<select class="form-select" id="sfGrade" required>
        <option value="">${esc(L('ክፍል ይምረጡ…', 'Choose a grade…'))}</option>
        ${(DATA.grades || []).map(g => `<option value="${g.id}" ${String(s.grade_id) === String(g.id) ? 'selected' : ''}>${esc(GRADE_NAME(g))}</option>`).join('')}
      </select>`;

    /* Photo picker. The <input type="file"> is visually hidden and driven
       by the two buttons, so the control matches the rest of the theme
       instead of showing the browser's default file widget.
       `capture` is not set: on a phone the OS offers camera OR gallery,
       which is what a registrar photographing a queue of children wants. */
    const photoField = `
      <div class="col-12 col-md-4">
        <label class="form-label" for="sfPhotoBtn">${esc(L('ፎቶ', 'Photo'))}</label>
        <div class="sf-photo">
          <div class="sf-ph-frame" id="sfPhotoFrame">
            <img id="sfPhotoImg" src="${esc(photoUrl)}" alt="" ${photoUrl ? '' : 'hidden'}>
            <span class="sf-ph-empty" id="sfPhotoEmpty" ${photoUrl ? 'hidden' : ''}>${ICON.camera}</span>
          </div>
          <div class="sf-ph-acts">
            <button class="btn btn-soft btn-sm" type="button" id="sfPhotoBtn">${esc(L('ፎቶ ይምረጡ', 'Choose photo'))}</button>
            <button class="btn btn-ghost btn-sm" type="button" id="sfPhotoClear" ${photoUrl ? '' : 'hidden'}>${esc(L('አስወግድ', 'Remove'))}</button>
            <p class="sf-ph-hint">${esc(L('በመታወቂያ ካርዱ ላይ ይታያል።', 'Appears on the ID card.'))}</p>
          </div>
          <input type="file" id="sfPhotoFile" accept="image/jpeg,image/png,image/webp" hidden>
        </div>
      </div>`;

    const body = `
      <form id="sfForm" novalidate>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfName">${t('asName')} *</label>
            <input class="form-control" id="sfName" value="${v('full_name')}" required maxlength="160">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfBaptism">${t('asBaptism')}</label>
            <input class="form-control" id="sfBaptism" value="${v('baptism_name')}" maxlength="160">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfGrade">${t('asClass')} *</label>
            ${gradeSel}
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="sfCode">${editing ? t('pmId') : t('asAutoId')}</label>
            <input class="form-control${MANUAL_IDS ? '' : ' is-auto'}" id="sfCode" value="${v('student_code')}"
                   placeholder="${esc(autoPh)}" maxlength="40" ${MANUAL_IDS ? '' : 'readonly tabindex="-1" aria-readonly="true"'}>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="sfRoll">${t('pmRoll')}</label>
            <input class="form-control${MANUAL_IDS ? '' : ' is-auto'}" id="sfRoll" type="${MANUAL_IDS ? 'number' : 'text'}" min="1"
                   value="${v('roll_no')}" placeholder="${esc(autoPh)}" ${MANUAL_IDS ? '' : 'readonly tabindex="-1" aria-readonly="true"'}>
          </div>
          ${MANUAL_IDS ? '' : `<div class="col-12"><p class="sf-auto-note">${ICON.info || ''}${esc(L(
              'መለያ ቁጥርና የተማሪ ተራ ቁጥር በራስ-ሰር ይሰጣሉ።',
              'The Student ID and roll number are assigned automatically.'))}</p></div>`}
          <div class="col-6 col-md-4">
            <label class="form-label" for="sfGender">${t('asGender')}</label>
            <select class="form-select" id="sfGender">
              ${['male', 'female'].map(g => `<option value="${g}" ${s.gender === g ? 'selected' : ''}>${esc(genderLbl(g))}</option>`).join('')}
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label" for="sfBirth">${t('asBirth')}</label>
            <input class="form-control" id="sfBirth" type="date" value="${v('birth_date')}">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label" for="sfStatus">${t('thStatus')}</label>
            <select class="form-select" id="sfStatus">
              ${STATUS_KEYS_S.map(k => `<option value="${k}" ${s.status === k ? 'selected' : ''}>${esc(statusLbl(k))}</option>`).join('')}
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfPhone">${t('asPhone')}</label>
            <input class="form-control" id="sfPhone" value="${v('phone')}" maxlength="40">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfGuardian">${t('asGuardian')}</label>
            <input class="form-control" id="sfGuardian" value="${v('guardian_name')}" maxlength="160">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfGuardianPhone">${t('asGuardianPhone')}</label>
            <input class="form-control" id="sfGuardianPhone" value="${v('guardian_phone')}" maxlength="40">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="sfAddress">${t('asAddress')}</label>
            <input class="form-control" id="sfAddress" value="${v('address')}" maxlength="255">
          </div>
          ${photoField}
          <div class="col-12 col-md-8">
            <label class="form-label" for="sfNotes">${t('asNotes')}</label>
            <textarea class="form-control" id="sfNotes" rows="4" maxlength="500">${v('notes')}</textarea>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-4">
          <button class="btn btn-primary" type="submit" id="sfSave">${editing ? t('save') : t('asSave')}</button>
          <a class="btn btn-ghost" href="#/students" id="sfCancel">${t('asCancel')}</a>
          ${editing && FEAT('studentIdCard') && can('idcard.view') ? `<button class="btn btn-ghost" type="button" id="sfCard">${ICON.card} ${t('idCard')}</button>` : ''}
          ${editing && can('students.delete') ? `<button class="btn btn-ghost pm-del ms-auto" type="button" id="sfDelete" data-name="${v('full_name')}">${t('pmRemove')}</button>` : ''}
        </div>
      </form>`;

    /* The full record lives on its own page now (#/history) — the edit
       form is for editing. A link keeps it one click away. */
    let sideInfo = '';
    if (editing && detail) {
      sideInfo = card(L('የተማሪው ታሪክ', 'Student history'),
        L('ምዝገባ፣ ዕድገትና የመገኘት መዝገብ በራሱ ገጽ ላይ።', 'Enrolment, promotion and attendance on their own page.'),
        `<a class="btn btn-soft" href="#/history/${encodeURIComponent(s.id)}">
           ${ICON.clock} ${L('ታሪኩን ክፈት', 'Open student history')}</a>`);
    }

    return pageTop(editing ? `${t('pmEdit')} · ${esc(s.full_name || '')}` : t('asT'),
        editing ? esc(s.student_code || '') : t('asSub'),
        `<a class="btn btn-ghost" href="#/students">${t('backClasses').replace('‹ ', '‹ ')}</a>`)
      + `<section class="card card-pad">${body}</section>`
      + sideInfo;
  }

  /* Downscale a chosen image in the browser and hand back a JPEG data URL.
     This exists because the server has no `gd` extension and therefore
     cannot resize anything: without this a 4 MB phone photo would be
     stored at full size and every ID card print would crawl.

     600px on the long edge at quality 0.82 lands around 60 KB, which is
     far more than an ID card photo needs.

     TO CHANGE THE STORED SIZE OR QUALITY: adjust MAX and Q below. Keep
     MAX at or above the CSS pixel size of the ID-card photo box (see
     .photo in api/export.php) or printed cards will look soft. */
  function photoResize(file) {
    const MAX = 600, Q = 0.82;
    return new Promise((resolve, reject) => {
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
        reject(new Error(L('JPG፣ PNG ወይም WebP ብቻ።', 'Please choose a JPG, PNG or WebP image.')));
        return;
      }
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        /* Scale the long edge down to MAX; never scale a small photo UP. */
        const scale = Math.min(1, MAX / Math.max(img.width, img.height));
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));
        const cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        const cx = cv.getContext('2d');
        /* White backdrop: a transparent PNG would otherwise turn black
           once it is flattened into JPEG. */
        cx.fillStyle = '#fff';
        cx.fillRect(0, 0, w, h);
        cx.drawImage(img, 0, 0, w, h);
        try { resolve(cv.toDataURL('image/jpeg', Q)); }
        catch (e) { reject(new Error(L('ፎቶውን ማዘጋጀት አልተቻለም።', 'That image could not be processed.'))); }
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error(L('ፎቶውን ማንበብ አልተቻለም።', 'That image could not be read.')));
      };
      img.src = url;
    });
  }

  function bindStudentForm(parts) {
    const editing = parts[0] === 'students' && parts[1];
    const id = editing ? parts[1] : null;
    const f = $('#sfForm');
    if (!f) return;

    /* --- Photo picker -------------------------------------------------
       pendingPhoto holds a data URL waiting to be saved with the form;
       removePhoto records that the user cleared an existing picture.
       Nothing is uploaded until the form itself is submitted, so
       abandoning the page leaves no orphaned files on the server. */
    let pendingPhoto = '';
    let removePhoto  = false;

    const phFile  = $('#sfPhotoFile');
    const phImg   = $('#sfPhotoImg');
    const phEmpty = $('#sfPhotoEmpty');
    const phClear = $('#sfPhotoClear');

    const phShow = (src) => {
      if (phImg)   { phImg.src = src || ''; phImg.hidden = !src; }
      if (phEmpty) phEmpty.hidden = !!src;
      if (phClear) phClear.hidden = !src;
    };

    const phBtn = $('#sfPhotoBtn');
    phBtn && phBtn.addEventListener('click', () => phFile && phFile.click());

    phFile && phFile.addEventListener('change', async () => {
      const file = phFile.files && phFile.files[0];
      if (!file) return;
      try {
        pendingPhoto = await photoResize(file);
        removePhoto = false;
        phShow(pendingPhoto);
      } catch (e) {
        toast(e.message, 'error');
      } finally {
        /* Reset so picking the SAME file twice still fires `change`. */
        phFile.value = '';
      }
    });

    phClear && phClear.addEventListener('click', () => {
      pendingPhoto = '';
      removePhoto = true;
      phShow('');
    });

    f.addEventListener('submit', async ev => {
      ev.preventDefault();
      const name = val('sfName');
      if (!name) { toast(t('errName'), 'error'); const n = $('#sfName'); n && n.focus(); return; }
      const gid = val('sfGrade');
      if (!gid) { toast(L('እባክዎ ክፍል ይምረጡ።', 'Please choose a grade.'), 'error'); return; }
      const payload = {
        full_name: name,
        baptism_name: val('sfBaptism'),
        grade_id: gid,
        gender: val('sfGender'),
        birth_date: val('sfBirth'),
        status: val('sfStatus'),
        phone: val('sfPhone'),
        guardian_name: val('sfGuardian'),
        guardian_phone: val('sfGuardianPhone'),
        address: val('sfAddress'),
        notes: val('sfNotes')
      };

      /* Only send the identifiers when the school has re-enabled manual
         entry. Otherwise the server generates them and would ignore
         these anyway — leaving them out keeps the request honest. */
      if (BOOT.manual_ids) {
        payload.student_code = val('sfCode');
        payload.roll_no = val('sfRoll');
      }

      /* Photo: send the new picture, or the removal flag, or neither.
         Sending nothing means "leave the existing photo alone". */
      if (pendingPhoto) payload.photo = pendingPhoto;
      else if (removePhoto) payload.photo_remove = '1';

      const btn = $('#sfSave');
      busy(btn, true);
      try {
        A.stu.all = null;                      /* roster changed — refetch next visit */
        if (editing) {
          payload.id = id;
          const r = await API.students.update(payload);
          toast(r._message || t('setSavedToast'));
        } else {
          const r = await API.students.create(payload);
          toast(I18N[lang()].addedToast(r.student_code || name));
          location.hash = '#/students';
          return;
        }
        refresh(true);
      } catch (e) { fail(e); } finally { busy(btn, false); }
    });

    const card = $('#sfCard');
    card && card.addEventListener('click', () => API.openPrint('idcards', { ids: [id], lang: lang() }));

    const del = $('#sfDelete');
    del && del.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ተማሪ ማስወገድ', 'Remove student'),
                               message: I18N[lang()].removeConfirm(del.dataset.name || ''),
                               danger: true, okLabel: L('አስወግድ', 'Remove') }))) return;
      try { await API.students.remove(id); toast(t('removedToast')); location.hash = '#/students'; }
      catch (e) { fail(e); }
    });
  }

  /* ================================================================== */
  /*  3 · SETTINGS                                                       */
  /* ================================================================== */
  /* A settings card with an icon-led header. Keeps each block scannable
     instead of the wall of text the page used to be. */
  function setCard(icon, title, sub, body, cls) {
    return `<section class="card set-card ${cls || ''}">
      <header class="set-head">
        <span class="set-ic">${icon}</span>
        <div class="set-hx">
          <h3>${title}</h3>
          ${sub ? `<p>${sub}</p>` : ''}
        </div>
      </header>
      <div class="set-body">${body}</div>
    </section>`;
  }

  /* One labelled control on the settings grid. */
  function setField(id, label, control, hint, w) {
    return `<div class="set-f ${w || 'w-half'}">
      <label class="set-lb" for="${id}">${label}</label>
      ${control}
      ${hint ? `<span class="set-hint">${hint}</span>` : ''}
    </div>`;
  }

  async function renderSettings() {
    const res = await API.settings.get();
    A.set.data = res;
    const s = res.settings || {};
    const editable = !!res.editable;
    const dis = editable ? '' : 'disabled';
    const g = (k, d) => (s[k] === undefined || s[k] === null ? (d === undefined ? '' : d) : s[k]);

    /* ---- school identity ---- */
    const schoolCard = setCard(ICON.home,
      L('የትምህርት ቤቱ ማንነት', 'School identity'),
      L('በአርዕስት፣ በሪፖርቶች እና በመታወቂያ ካርድ ላይ ይታያል።', 'Appears in the header, on reports and on ID cards.'),
      `<div class="set-grid">
        ${setField('stSchoolAm', L('ስም (አማርኛ)', 'Name (Amharic)'),
          `<input class="form-control" id="stSchoolAm" value="${esc(g('school_name_am'))}" ${dis}>`)}
        ${setField('stSchoolEn', L('ስም (እንግሊዝኛ)', 'Name (English)'),
          `<input class="form-control" id="stSchoolEn" value="${esc(g('school_name_en'))}" ${dis}>`)}
        ${setField('stSubAm', L('ንዑስ አርዕስት (አማርኛ)', 'Subtitle (Amharic)'),
          `<input class="form-control" id="stSubAm" value="${esc(g('school_sub_am'))}" ${dis}>`)}
        ${setField('stSubEn', L('ንዑስ አርዕስት (እንግሊዝኛ)', 'Subtitle (English)'),
          `<input class="form-control" id="stSubEn" value="${esc(g('school_sub_en'))}" ${dis}>`)}
      </div>
      <div class="set-row-sw">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="stBrandAm"
                 ${g('brand_always_amharic', 1) ? 'checked' : ''} ${dis}>
          <label class="form-check-label" for="stBrandAm">
            ${L('አማርኛውን ስም ሁልጊዜ ተጠቀም', 'Always use the Amharic name')}
          </label>
        </div>
        <span class="set-hint">${L('ሲበራ የአማርኛው ስም በአርዕስት፣ በመግቢያ ገጽ እና በኅትመቶች ላይ ይቆያል — የማያ ቋንቋው ምንም ይሁን። ሲጠፋ ከማያ ቋንቋው ጋር ይከተላል።',
          'When on, the Amharic name stays in the header, on the sign-in page and on printed output — whatever the interface language. Off, it follows the interface language.')}</span>
      </div>
      <div class="set-grid mt-3">
        ${setField('stQuoteAm', L('የመግቢያ ገጽ ጥቅስ (አማርኛ ብቻ)', 'Sign-in quotation (Amharic only)'),
          `<textarea class="form-control" id="stQuoteAm" rows="3" maxlength="500"
                     placeholder="${L('ነጻ ሲቀር አይታይም።', 'Leave empty to hide it.')}" ${dis}>${esc(g('login_quote_am', 'እግዚአብሔር ዓለሙን እንዲሁ ወደደ፤ አንድዬ ልጁን ሰጠ፤ በእርሱ የሚያምን ሁሉ እንዳይጠፋ ይልቁንም የዘላለም ሕይወት እንዲኖረው።'))}</textarea>`,
          L('በመግቢያው ግራ ፓነል ላይ በአማርኛ ብቻ ይታያል።', 'Shown on the left branding panel, Amharic only.'), 'w-full')}
        ${setField('stQuoteRef', L('የጥቅሱ ምንጭ', 'Reference'),
          `<input class="form-control" id="stQuoteRef" value="${esc(g('login_quote_ref', 'ዮሐንስ 3፡16'))}" maxlength="80" ${dis}>`,
          '', 'w-quarter')}
      </div>`);

    /* ---- absence alerts ---- */
    const ph = (res.placeholders || ['{days}', '{student}', '{grade}'])
      .map(p => `<code class="set-tok">${esc(p)}</code>`).join('');
    const alertCard = setCard(ICON.bell,
      L('የመቅረት ማንቂያ', 'Absence alerts'),
      L('ተማሪ ተከታታይ ቀናት ሲቀር ማንቂያ ይላካል።', 'Raised when a student misses several sessions in a row.'),
      `<div class="set-row-sw">
         <div class="form-check form-switch mb-0">
           <input class="form-check-input" type="checkbox" id="stAlertOn" ${g('absence_alert_enabled') ? 'checked' : ''} ${dis}>
           <label class="form-check-label" for="stAlertOn">${L('ማንቂያውን አንቃ', 'Enable alerts')}</label>
         </div>
       </div>
       <div class="set-grid">
        ${setField('stThreshold', L('ተከታታይ የመቅረት ደረጃ', 'Threshold'),
          `<input class="form-control" id="stThreshold" type="number" min="1" max="60" value="${esc(g('absence_alert_threshold', 3))}" ${dis}>`,
          L('ቀናት', 'Sessions in a row'), 'w-quarter')}
        ${setField('stIdle', L('የክፍለ ጊዜ ማብቂያ', 'Session timeout'),
          `<input class="form-control" id="stIdle" type="number" min="5" max="1440" value="${esc(g('session_idle_minutes', 120))}" ${dis}>`,
          L('ደቂቃ', 'Minutes idle'), 'w-quarter')}
       </div>
       <div class="set-tokens">
         <span class="set-tok-lb">${L('በመልእክቱ ውስጥ ይጠቀሙ', 'Use in the message')}</span>${ph}
       </div>
       <div class="set-grid">
        ${setField('stMsgAm', L('መልእክት (አማርኛ)', 'Message (Amharic)'),
          `<textarea class="form-control" id="stMsgAm" rows="2" ${dis}>${esc(g('absence_alert_message_am'))}</textarea>`)}
        ${setField('stMsgEn', L('መልእክት (እንግሊዝኛ)', 'Message (English)'),
          `<textarea class="form-control" id="stMsgEn" rows="2" ${dis}>${esc(g('absence_alert_message_en'))}</textarea>`)}
       </div>`);

    /* ---- platform ---- */
    const platformCard = setCard(ICON.gear,
      L('መድረክ', 'Platform'),
      L('ነባሪ ቋንቋ፣ የመገኘት ቀናት እና የመታወቂያ ካርድ ግርጌ።', 'Default language, attendance days and the ID card footer.'),
      `<div class="set-grid">
        ${setField('stLang', L('ነባሪ ቋንቋ', 'Default language'),
          `<select class="form-select" id="stLang" ${dis}>
             <option value="am" ${g('default_lang') === 'am' ? 'selected' : ''}>አማርኛ</option>
             <option value="en" ${g('default_lang') === 'en' ? 'selected' : ''}>English</option>
           </select>`, '', 'w-quarter')}
        ${setField('stDays', L('የመገኘት ቀናት', 'Attendance days'),
          `<input class="form-control" id="stDays" value="${esc(g('attendance_days', 'sat,sun'))}" ${dis}>`,
          L('ቅዳሜና እሁድ ተለያይተው ይመዘገባሉ።', 'Saturday and Sunday stay separate.'), 'w-quarter')}
        ${setField('stCardAm', L('የካርድ ግርጌ (አማ)', 'Card footer (AM)'),
          `<input class="form-control" id="stCardAm" value="${esc(g('id_card_footer_am'))}" ${dis}>`, '', 'w-quarter')}
        ${setField('stCardEn', L('የካርድ ግርጌ (EN)', 'Card footer (EN)'),
          `<input class="form-control" id="stCardEn" value="${esc(g('id_card_footer_en'))}" ${dis}>`, '', 'w-quarter')}
      </div>`);

    /* ---- academic years ---- */
    const years = res.years || [];
    const cur = res.current_year || {};
    const yearRows = years.map(y => `
      <tr>
        <td><strong>${num(y.year_ec)}</strong></td>
        <td>${esc(am() ? (y.label_am || '') : (y.label_en || ''))}</td>
        <td>${+y.is_current ? `<span class="pill g">${esc(t('curYear'))}</span>` : ''}</td>
        <td class="text-end">${can('years.manage') && !+y.is_current
          ? `<button class="mini-btn yr-cur" data-id="${y.id}" type="button">${L('አሁን አድርግ', 'Make current')}</button>` : ''}</td>
      </tr>`).join('');
    const yearCard = setCard(ICON.cal,
      L('የትምህርት ዓመታት', 'Academic years'),
      L('የሚካሄደው ዓመት ለመዝገብ፣ ለዳሽቦርድና ለሪፖርቶች ነባሪ ነው።', 'The current year is the default for the register, dashboard and reports.'),
      `<div class="set-tbl">
         <table class="pm-table mb-0"><thead><tr><th>${esc(t('asYear'))}</th><th>${L('መለያ', 'Label')}</th><th></th><th></th></tr></thead>
           <tbody>${yearRows}</tbody></table>
       </div>
       ${can('years.manage') ? `
       <div class="set-addyear">
         <div class="set-f w-quarter">
           <label class="set-lb" for="stNewYear">${t('addYear')}</label>
           <input class="form-control" id="stNewYear" type="number" min="1900" max="2200" placeholder="${esc(String((+((cur && cur.year_ec) || 2018)) + 1))}">
         </div>
         <div class="form-check set-ay-chk">
           <input class="form-check-input" type="checkbox" id="stNewYearCur">
           <label class="form-check-label" for="stNewYearCur">${L('ወዲያው አሁን አድርገው', 'Make it current')}</label>
         </div>
         <button class="btn btn-soft btn-field set-ay-btn" id="stAddYear" type="button">${ICON.plus} ${t('addYear')}</button>
       </div>` : ''}
       <div class="set-row-sw">
         <div class="form-check form-switch mb-0">
           <input class="form-check-input" type="checkbox" id="stYearAuto"
                  ${g('year_auto_switch') ? 'checked' : ''} ${dis}>
           <label class="form-check-label" for="stYearAuto">
             ${L('ዓመቱ በራሱ ይጀምር', 'Start the new year automatically')}
           </label>
         </div>
         <span class="set-hint">${L('ክፍል ማሳደግ ዓመቱን አይቀይረውም። ዓመቱ የሚቀየረው ከታች በተቀመጠው ቀን ወይም "አሁን አድርግ" ሲጫኑ ብቻ ነው።',
           'Promotion never changes the year. It moves on the date below, or when you press “Make current”.')}</span>
       </div>
       <div class="set-grid">
        ${setField('stYearStart', L('የዓመቱ መጀመሪያ ቀን', 'Year starts on'),
          `<input class="form-control" id="stYearStart" value="${esc(g('year_start_date', '01-01'))}"
                  placeholder="01-01" ${dis}>`,
          L('በኢትዮጵያ አቆጣጠር ወር-ቀን (ለምሳሌ 01-01 = መስከረም 1)', 'Ethiopian month-day, e.g. 01-01 = Meskerem 1'), 'w-quarter')}
       </div>`);

    /* ---- feature switches: master only (features.manage) ---- */
    let featCard = '';
    if (can('features.manage')) {
      const keys = Object.keys(window.FEATURES || {});
      const flags = keys.map(k => `<span class="feat-chip ${window.FEATURES[k] ? 'on' : 'off'}">
          <i></i>${esc(k)}</span>`).join('');
      featCard = setCard(ICON.shield,
        L('የባህሪ መቆጣጠሪያዎች', 'Feature switches'),
        L('በ js/features.js ውስጥ ይቀየራሉ። እይታን ብቻ ይቆጣጠራሉ — ፈቃድ ሁልጊዜ በአገልጋዩ ይረጋገጣል።',
          'Edited in js/features.js. They control visibility only — permissions are always re-checked by PHP.'),
        `<div class="feat-wrap">${flags || `<span class="set-dim">${L('ምንም የለም።', 'None defined.')}</span>`}</div>`);
    }

    /* ---- master recovery keys: master only (item 15) ----
       Not gated on a capability: recovery must never become something a
       permission edit can hand to an admin. The list is fetched here so
       the card can show what the Master currently holds. */
    let recCard = '';
    if ((DATA.user || {}).role === 'master') {
      let keys = [], unused = 0;
      try {
        const rk = await API.users.recoveryKeys();
        keys = rk.keys || []; unused = rk.unused || 0;
      } catch (e) { /* card still renders; the buttons will report the error */ }

      const rows = keys.map(k => `
        <tr>
          <td>${esc(k.label || L('ስም የለም', 'Untitled'))}</td>
          <td>${esc(stamp(k.created_at))}</td>
          <td>${k.used_at
                ? `<span class="pill n">${esc(L('ተጠቅሟል', 'Used'))} · ${esc(stamp(k.used_at))}</span>`
                : `<span class="pill g">${esc(L('ዝግጁ', 'Ready'))}</span>`}</td>
          <td class="text-end">${k.used_at ? ''
            : `<button class="mini-btn rk-del" data-id="${k.id}" type="button">${esc(L('ሰርዝ', 'Revoke'))}</button>`}</td>
        </tr>`).join('');

      recCard = setCard(ICON.key,
        L('የመልሶ ማግኛ ቁልፎች', 'Recovery keys'),
        L('የይለፍ ቃልዎን ቢረሱ ወደ ማስተር መለያዎ የሚመልስዎ ቁልፍ። ታትሞ በአስተማማኝ ቦታ ይቀመጥ። አንድ ቁልፍ አንድ ጊዜ ብቻ ይሠራል።',
          'The way back into your Master account if you forget the password. Print one and keep it somewhere safe — each key works exactly once.'),
        `<div class="rk-top">
           <span class="set-dim">${unused
             ? L(`${num(unused)} ዝግጁ ቁልፍ`, `${num(unused)} unused key${unused === 1 ? '' : 's'}`)
             : L('ምንም ዝግጁ ቁልፍ የለም።', 'No unused keys — you cannot recover this account yet.')}</span>
           <button class="btn btn-soft btn-sm" id="rkNew" type="button">${ICON.plus} ${L('አዲስ ቁልፍ', 'New key')}</button>
         </div>
         <div id="rkOut"></div>
         ${keys.length ? tblIn(
            `<th>${esc(L('መለያ', 'Label'))}</th><th>${esc(L('የተፈጠረበት', 'Created'))}</th>`
            + `<th>${esc(L('ሁኔታ', 'Status'))}</th><th></th>`, rows, '') : ''}`);
    }

    const saveBar = editable
      ? `<div class="set-savebar">
           <span class="set-dim">${L('ለውጦች እስኪያስቀምጡ አይተገበሩም።', 'Changes apply once you save.')}</span>
           <button class="btn btn-primary" id="stSave2" type="button">${ICON.check} ${t('setSave')}</button>
         </div>`
      : '';

    return pageTop(t('setT'), t('setSub'),
        editable ? `<button class="btn btn-primary" id="stSave" type="button">${t('setSave')}</button>` : '')
      + `<div class="set-wrap">${schoolCard}${alertCard}${platformCard}${yearCard}${recCard}${featCard}</div>`
      + saveBar;
  }

  function bindSettings() {
    const save = async btn => {
      busy(btn, true);
      const payload = {
        school_name_am: val('stSchoolAm'),
        school_name_en: val('stSchoolEn'),
        school_sub_am: val('stSubAm'),
        school_sub_en: val('stSubEn'),
        brand_always_amharic: chk('stBrandAm') ? 1 : 0,
        login_quote_am: val('stQuoteAm'),
        login_quote_ref: val('stQuoteRef'),
        absence_alert_enabled: chk('stAlertOn') ? 1 : 0,
        absence_alert_threshold: val('stThreshold'),
        absence_alert_message_am: val('stMsgAm'),
        absence_alert_message_en: val('stMsgEn'),
        session_idle_minutes: val('stIdle'),
        default_lang: val('stLang'),
        attendance_days: val('stDays'),
        id_card_footer_am: val('stCardAm'),
        id_card_footer_en: val('stCardEn'),
        year_auto_switch: chk('stYearAuto') ? 1 : 0,
        year_start_date: val('stYearStart')
      };
      try {
        const r = await API.settings.save({ settings: payload });
        Object.assign(DATA.settings, r.saved || {});
        toast(r._message || t('setSavedToast'));
        refresh(true);
      } catch (e) { fail(e); } finally { busy(btn, false); }
    };
    /* ---- recovery keys (master only) ---- */
    const rkNew = $('#rkNew');
    rkNew && rkNew.addEventListener('click', async () => {
      const label = prompt(L('ይህን ቁልፍ የት እንደሚያስቀምጡት ይጻፉ (አማራጭ):',
                             'Where will you keep this key? (optional label)')) || '';
      busy(rkNew, true);
      try {
        const r = await API.users.recoveryKeyCreate({ label: label.slice(0, 120) });
        /* Shown once and never again, so it is displayed in the page —
           not a toast that disappears — with an explicit copy button. */
        const out = $('#rkOut');
        if (out) out.innerHTML = `
          <div class="rk-show">
            <div class="rk-show-t">${esc(L('ይህን አሁን ይቅዱ — ዳግም አይታይም።',
              'Copy this now — it will never be shown again.'))}</div>
            <div class="rk-code"><code id="rkCode">${esc(r.key)}</code>
              <button class="mini-btn" id="rkCopy" type="button">${ICON.copy} ${esc(L('ቅዳ', 'Copy'))}</button>
              <button class="mini-btn" id="rkPrint" type="button">${esc(L('አትም', 'Print'))}</button>
            </div>
          </div>`;
        const cp = $('#rkCopy');
        cp && cp.addEventListener('click', () => {
          navigator.clipboard && navigator.clipboard.writeText(r.key);
          toast(L('ተቀድቷል።', 'Copied.'));
        });
        const pr = $('#rkPrint');
        pr && pr.addEventListener('click', () => {
          const w = window.open('', '_blank');
          if (!w) { toast(L('ኅትመቱ ታግዷል።', 'Print window was blocked.'), 'error'); return; }
          w.document.write(`<pre style="font:16px ui-monospace,monospace;padding:32px;line-height:1.8">`
            + `${esc(L('የማስተር መልሶ ማግኛ ቁልፍ', 'Master recovery key'))}\n\n${esc(r.key)}\n\n`
            + `${esc(L('በአስተማማኝ ቦታ ያስቀምጡ። አንድ ጊዜ ብቻ ይሠራል።',
                       'Keep this somewhere safe. It works exactly once.'))}</pre>`);
          w.document.close(); w.print();
        });
        toast(r._message || L('ቁልፍ ተፈጥሯል።', 'Recovery key created.'));
      } catch (e) { fail(e); } finally { busy(rkNew, false); }
    });

    $$('.rk-del').forEach(b => b.addEventListener('click', async () => {
      if (!confirm(L('ይህ ቁልፍ ከእንግዲህ አይሠራም። ይቀጥሉ?',
                     'This key will stop working. Continue?'))) return;
      busy(b, true);
      try {
        const r = await API.users.recoveryKeyRevoke({ id: b.dataset.id });
        toast(r._message || L('ተሰርዟል።', 'Revoked.'));
        refresh(true);
      } catch (e) { fail(e); busy(b, false); }
    }));

    const b1 = $('#stSave'); b1 && b1.addEventListener('click', () => save(b1));
    const b2 = $('#stSave2'); b2 && b2.addEventListener('click', () => save(b2));

    $$('.yr-cur').forEach(b => b.addEventListener('click', async () => {
      busy(b, true);
      try {
        const r = await API.settings.setCurrentYear({ id: b.dataset.id });
        toast(r._message || t('setSavedToast'));
        if (r.year) { DATA.year = r.year; state.openYear = +r.year.year_ec; }
        refresh(true);
      } catch (e) { fail(e); busy(b, false); }
    }));

    const add = $('#stAddYear');
    add && add.addEventListener('click', async () => {
      const ec = parseInt(val('stNewYear'), 10);
      if (!ec) { toast(L('ዓመቱን ያስገቡ።', 'Enter the year.'), 'error'); return; }
      busy(add, true);
      try {
        const r = await API.settings.addYear({ year_ec: ec, make_current: chk('stNewYearCur') });
        toast(I18N[lang()].addYearToast(ec));
        if (chk('stNewYearCur') && r.year) { DATA.year = r.year; state.openYear = ec; }
        const ys = await API.settings.years().catch(() => null);
        if (ys) DATA.years = ys.years || DATA.years;
        refresh(true);
      } catch (e) { fail(e); busy(add, false); }
    });
  }

  /* ================================================================== */
  /*  4 · IMPORT — upload → validate → preview → confirm (+ migration)   */
  /* ================================================================== */
  async function renderImport() {
    const b = await API.importer.batches().catch(() => ({ batches: [] }));
    A.imp.batches = b.batches || [];
    const r = A.imp.report;

    const step1 = card('', '', `
      <div class="up-row">
        <div class="up-field">
          <label class="form-label" for="impFile">${t('impPick')}</label>
          <input class="form-control" id="impFile" type="file" accept=".csv,.tsv,.txt,.xlsx">
        </div>
        <div class="up-go">
          <button class="btn btn-primary" id="impUpload" type="button">${ICON.upload} ${t('impUpload')}</button>
        </div>
        <!-- The hint is a direct child of .up-row (order:3, full width) so it
             sits under BOTH controls. Nested inside .up-field it made that
             column taller than the button and knocked the two out of line. -->
        <span class="up-hint">${L('.csv · .tsv · .xlsx — እስከ ፭ ሜባ', '.csv · .tsv · .xlsx — up to 5 MB')}</span>
      </div>
      <div class="up-tmpl">
        <span class="up-tmpl-lb">${t('tmplDownload')}</span>
        <div class="up-tmpl-btns">
          <button class="btn btn-ghost" id="impTmplCsv" type="button">${ICON.down} CSV</button>
          ${FEAT('excelExport') ? `<button class="btn btn-ghost" id="impTmplXlsx" type="button">${ICON.down} Excel</button>` : ''}
        </div>
      </div>`);

    let preview = '';
    if (r) {
      const rows = (r.rows || []).map(row => {
        const d = row.data || {};
        const bad = !row.valid;
        const skipped = !!A.imp.skip[row.row];
        return `<tr class="${bad ? 'imp-bad' : ''}">
          <td><input class="form-check-input imp-ck" type="checkbox" data-row="${row.row}" ${bad || skipped ? '' : 'checked'} ${bad ? 'disabled' : ''}></td>
          <td>${num(row.row)}</td>
          <td>${esc(d.full_name || '')}${d.baptism_name ? `<div class="pm-b-sub">${esc(d.baptism_name)}</div>` : ''}</td>
          <td>${esc(d.student_code || L('ራስ-ሰር', 'auto'))}</td>
          <td>${esc(d.grade_label || '')}</td>
          <td>${esc(genderLbl(d.gender))}</td>
          <td>${bad
            ? `<span class="pill r">${esc(t('impInvalid'))}</span><div class="pm-b-sub">${esc(msgList(row.errors))}</div>`
            : `<span class="pill g">${esc(t('impValid'))}</span>${(row.warnings || []).length ? `<div class="pm-b-sub">${esc(msgList(row.warnings))}</div>` : ''}`}</td>
        </tr>`;
      }).join('');

      preview = card(t('impPreview'),
        `${esc(A.imp.filename)} · ${L('ምንም ነገር ገና አልተቀመጠም።', 'Nothing has been saved yet.')}`,
        `<div class="pm-chip-row">
           <span class="pill b">${L('ጠቅላላ', 'Total')} · ${num(r.total)}</span>
           <span class="pill g">${esc(t('impValid'))} · ${num(r.valid_count)}</span>
           <span class="pill r">${esc(t('impInvalid'))} · ${num(r.invalid_count)}</span>
           ${r.duplicate_count ? `<span class="pill late">${L('መለያ ይታደሳል', 'IDs auto-assigned')} · ${num(r.duplicate_count)}</span>` : ''}
           ${(r.unknown_columns || []).length ? `<span class="pill n">${L('ያልታወቁ አምዶች', 'Unknown columns')}: ${esc(r.unknown_columns.join(', '))}</span>` : ''}
         </div>
         ${r.truncated ? `<div class="set-sub">${L('የመጀመሪያዎቹ ፭፻ ረድፎች ብቻ ይታያሉ — ሁሉም ግን ይገባሉ።', 'Only the first 500 rows are shown — all valid rows are imported.')}</div>` : ''}
         <div class="table-responsive" style="max-height:460px;overflow:auto">
           <table class="table table-sm align-middle mb-0">
             <thead><tr><th style="width:34px"></th><th>${esc(t('impRow'))}</th><th>${esc(t('thStudent'))}</th>
               <th>${esc(t('pmId'))}</th><th>${esc(t('thClass'))}</th><th>${esc(t('asGender'))}</th><th>${esc(t('thStatus'))}</th></tr></thead>
             <tbody>${rows}</tbody>
           </table>
         </div>
         <div class="d-flex gap-2 flex-wrap mt-3">
           <button class="btn btn-primary" id="impConfirm" type="button" ${r.valid_count ? '' : 'disabled'}>${t('impConfirm')} · ${num(r.valid_count)}</button>
           <button class="btn btn-ghost" id="impCancel" type="button">${t('cancel')}</button>
         </div>`);
    }

    const batchRows = A.imp.batches.map(x => `
      <tr><td>${esc(x.filename)}</td>
        <td>${num(x.total_rows)}</td>
        <td><span class="pill g">${num(x.valid_rows)}</span> / <span class="pill r">${num(x.invalid_rows)}</span></td>
        <td>${x.status === 'committed' ? `<span class="pill g">${num(x.imported_rows || 0)} ${L('ገብተዋል', 'imported')}</span>` : `<span class="pill n">${esc(x.status)}</span>`}</td>
        <td>${esc(x.created_by_name || '—')}</td>
        <td>${stamp(x.created_at)}</td></tr>`).join('');

    return pageTop(t('impT'), t('impSub'), '')
      + step1 + preview
      + card(L('የቀደሙ ማስገቢያዎች', 'Recent imports'),
          L('የተሰቀሉ ፋይሎችና ውጤታቸው።', 'Uploaded files and how each one landed.'),
          tblIn(`<th>${L('ፋይል', 'File')}</th><th>${L('ረድፎች', 'Rows')}</th><th>${esc(t('impValid'))}/${esc(t('impInvalid'))}</th><th>${esc(t('thStatus'))}</th><th>${esc(t('auUser'))}</th><th>${esc(t('auWhen'))}</th>`,
            batchRows, L('ገና ምንም ማስገባት አልተደረገም።', 'No imports yet.')));
  }

  function bindImport() {
    const up = $('#impUpload');
    up && up.addEventListener('click', async () => {
      const inp = $('#impFile');
      const file = inp && inp.files && inp.files[0];
      if (!file) { toast(L('እባክዎ ፋይል ይምረጡ።', 'Please choose a file.'), 'error'); return; }
      busy(up, true);
      try {
        const r = await API.importer.upload(file);
        A.imp.report = r; A.imp.token = r.token; A.imp.filename = r.filename; A.imp.skip = {};
        toast(r._message || t('impPreview'));
        refresh(true);
      } catch (e) { fail(e); busy(up, false); }
    });

    const tc = $('#impTmplCsv');
    tc && tc.addEventListener('click', () => API.download('template', { format: 'csv' }));
    const tx = $('#impTmplXlsx');
    tx && tx.addEventListener('click', () => API.download('template', { format: 'xlsx' }));

    $$('.imp-ck').forEach(c => c.addEventListener('change', () => {
      A.imp.skip[c.dataset.row] = !c.checked;
    }));

    const cf = $('#impConfirm');
    cf && cf.addEventListener('click', async () => {
      const skip = Object.keys(A.imp.skip).filter(k => A.imp.skip[k]).map(Number);
      if (!(await srConfirm({ title: L('ከፋይል አስገባ', 'Import'),
                               message: L('ትክክለኛዎቹን ረድፎች ማስገባት ይፈልጋሉ?', 'Import the valid rows now?'),
                               okLabel: L('አስገባ', 'Import') }))) return;
      busy(cf, true);
      try {
        const r = await API.importer.confirm({ token: A.imp.token, skip_rows: skip });
        toast(I18N[lang()].impDone(r.inserted || 0));
        if ((r.failed || []).length) toast(L('አንዳንድ ረድፎች አልገቡም።', 'Some rows were rejected.'), 'error');
        A.imp.report = null; A.imp.token = ''; A.imp.skip = {};
        const g = await API.grades.list({ with_counts: 1 }).catch(() => null);
        if (g) setGrades(g.grades || []);
        refresh(true);
      } catch (e) { fail(e); busy(cf, false); }
    });
    const cc = $('#impCancel');
    cc && cc.addEventListener('click', () => { A.imp.report = null; A.imp.token = ''; A.imp.skip = {}; refresh(true); });

  }

  /* ================================================================== */
  /*  5 · PROMOTION                                                      */
  /* ================================================================== */
  async function renderPromotion() {
    const p = A.pro;
    const curEc = (DATA.year && +DATA.year.year_ec) || state.openYear;
    if (!p.toEc) p.toEc = curEc + 1;

    if (p.tab === 'history') {
      const h = await API.promotion.history({});
      p.history = h.history || [];
      const rows = p.history.map(x => `
        <tr>
          <td><strong>${esc(x.full_name)}</strong><div class="pm-b-sub">${esc(x.student_code)}</div></td>
          <td>${esc(am() ? (x.from_am || '') : (x.from_en || ''))} → ${esc(am() ? (x.to_am || '—') : (x.to_en || '—'))}</td>
          <td>${num(x.from_year)} → ${num(x.to_year)}</td>
          <td><span class="pill ${actCls(x.action)}">${esc(actLbl(x.action))}</span></td>
          <td>${esc(x.performed_by_name || '—')}</td>
          <td>${stamp(x.created_at)}</td>
        </tr>`).join('');
      return promoHead(curEc)
        + tblCard(`<th>${esc(t('thStudent'))}</th><th>${esc(t('pmClass'))}</th><th>${esc(t('asYear'))}</th><th>${esc(t('thAction'))}</th><th>${esc(t('auUser'))}</th><th>${esc(t('auWhen'))}</th>`,
            rows, L('ገና ምንም ማሳደግ አልተደረገም።', 'No promotions recorded yet.'))
        + card(t('prUndo'), L('በዒላማው ዓመት መገኘት እስካልተመዘገበ ድረስ ማሳደጉን መመለስ ይቻላል።',
            'A promotion can be rolled back while the target year has no attendance yet.'),
            `<div class="row g-2 align-items-end">
               <div class="col-6 col-md-3"><label class="form-label" for="prUndoYear">${t('prTo')}</label>
                 <input class="form-control" id="prUndoYear" type="number" value="${esc(String(p.toEc))}"></div>
               <div class="col-6 col-md-3"><button class="btn btn-ghost pm-del w-100" id="prUndoBtn" type="button">${t('prUndo')}</button></div>
             </div>`, 'set-danger');
    }

    const controls = card(t('prT'), t('prSub'), `
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label">${t('prFrom')}</label>
          <input class="form-control" value="${esc(num(curEc))}" disabled></div>
        <div class="col-6 col-md-3"><label class="form-label" for="prToYear">${t('prTo')}</label>
          <input class="form-control" id="prToYear" type="number" min="${curEc + 1}" value="${esc(String(p.toEc))}"></div>
        <div class="col-6 col-md-3"><label class="form-label" for="prGrade">${t('stuFilterGrade')}</label>
          <select class="form-select" id="prGrade">${gradeOptions(p.grade_id)}</select></div>
        <div class="col-6 col-md-3"><button class="btn btn-primary btn-field w-100" id="prPreviewBtn" type="button">${t('prPreview')}</button></div>
      </div>`);

    let plan = '';
    if (p.plan) {
      /* The user can change an outcome without asking the server again, so
         recompute the action, the chips and the warning banner locally. */
      const effAct = x => {
        const r = p.res[x.student_id] || x.result;
        if (r === x.result) return x.action;
        if (r === 'skip')     return 'skip';
        if (r === 'withheld') return 'withhold';
        if (r === 'failed')   return 'repeat';
        return x.to_grade_id || x.from_grade_id ? 'promote' : 'graduate';
      };
      const s = { promote: 0, repeat: 0, graduate: 0, withhold: 0, skip: 0 };
      (p.plan || []).forEach(x => { s[effAct(x)] = (s[effAct(x)] || 0) + 1; });
      const rows = p.plan.map(x => {
        const chosen = p.res[x.student_id] || x.result;
        return `<tr>
          <td><strong>${esc(x.full_name)}</strong><div class="pm-b-sub">${esc(x.student_code)}
            ${x.was_withheld ? `<span class="pr-wasw">${L('ባለፈው አልተሳደገም', 'was not promoted')}</span>` : ''}</div></td>
          <td>${esc(am() ? x.from_grade : x.from_grade_en)}</td>
          <td>${esc(am() ? (x.to_grade || '—') : (x.to_grade_en || '—'))}</td>
          <td>
            <select class="form-select form-select-sm pr-res" data-id="${x.student_id}">
              <option value="passed" ${chosen === 'passed' ? 'selected' : ''}>${esc(t('prPassed'))}</option>
              <option value="failed" ${chosen === 'failed' ? 'selected' : ''}>${esc(t('prFailed'))}</option>
              <option value="withheld" ${chosen === 'withheld' ? 'selected' : ''}>${esc(t('prWithheld'))}</option>
              <option value="skip"   ${chosen === 'skip' ? 'selected' : ''}>${esc(t('prSkip'))}</option>
            </select>
          </td>
          <td><span class="pill ${actCls(effAct(x))}">${esc(actLbl(effAct(x)))}</span>
            ${effAct(x) === x.action && x.note ? `<div class="pm-b-sub">${esc(x.note)}</div>` : ''}</td>
        </tr>`;
      }).join('');
      /* Anyone who will have no place in the new year, named up front. */
      const behind = (p.plan || []).filter(x => ['withhold', 'skip'].indexOf(effAct(x)) !== -1);
      const left = behind.length ? `<div class="pr-behind">
          ${ICON.warn}
          <div>
            <b>${L(`${num(behind.length)} ተማሪ ወደ ${num(p.toEc)} ዓ.ም አይሸጋገርም።`,
                    `${behind.length} student${behind.length === 1 ? '' : 's'} will not move into ${p.toEc}`)}</b>
            <div class="pr-behind-l">${behind.slice(0, 12).map(x =>
              `<span class="pr-bn">${esc(x.full_name)} <i>${esc(actLbl(effAct(x)))}</i></span>`).join('')}
              ${behind.length > 12 ? `<span class="pr-bn more">+${num(behind.length - 12)}</span>` : ''}</div>
            <div class="set-hint">${L('«አልተሳደገም» የተባሉ ተማሪዎች በአሮጌው ዓመት ተዘግተው ይመዘገባሉ፤ በአዲሱ ዓመት ምዝገባ አይኖራቸውም። «ተዘሏል» የተባሉት ግን ምንም ሳይነኩ ይቀራሉ።',
              'Students marked “Not promoted” are closed out in the old year with that decision on record and get no enrolment in the new one. Students marked “Skip” are left completely untouched.')}</div>
          </div>
        </div>` : '';

      plan = card(t('prPreview'),
        L('ምንም አልተቀመጠም — ከታች ሲያረጋግጡ ብቻ ይተገበራል።', 'Nothing is saved yet — it applies only when you confirm below.'),
        `<div class="pm-chip-row">
           <span class="pill g">${esc(t('prRun'))} · ${num(s.promote || 0)}</span>
           <span class="pill late">${esc(t('prFailed'))} · ${num(s.repeat || 0)}</span>
           <span class="pill b">${esc(t('prGraduate'))} · ${num(s.graduate || 0)}</span>
           <span class="pill r">${esc(t('prWithheld'))} · ${num(s.withhold || 0)}</span>
           <span class="pill n">${esc(t('prSkip'))} · ${num(s.skip || 0)}</span>
         </div>
         ${left}
         <div class="table-responsive" style="max-height:480px;overflow:auto">
           <table class="table table-sm align-middle mb-0">
             <thead><tr><th>${esc(t('thStudent'))}</th><th>${t('prFrom')}</th><th>${t('prTo')}</th>
               <th style="width:150px">${esc(t('thStatus'))}</th><th>${esc(t('thAction'))}</th></tr></thead>
             <tbody>${rows}</tbody></table>
         </div>
         <div class="d-flex gap-2 flex-wrap mt-3">
           <button class="btn btn-primary" id="prRunBtn" type="button">${t('prRun')} → ${num(p.toEc)}</button>
           <button class="btn btn-ghost" id="prClear" type="button">${t('cancel')}</button>
         </div>`);
    }

    return promoHead(curEc) + controls + plan;
  }

  function promoHead(curEc) {
    const p = A.pro;
    return pageTop(t('prT'), `${t('prSub')} · ${num(curEc)}`,
      `<div class="grp-tabs mb-0" role="tablist">
         <button class="grp-tab pr-tab ${p.tab === 'plan' ? 'active' : ''}" data-tab="plan" type="button">${t('prPreview')}</button>
         <button class="grp-tab pr-tab ${p.tab === 'history' ? 'active' : ''}" data-tab="history" type="button">${t('prHistory')}</button>
       </div>`);
  }

  function bindPromotion() {
    $$('.pr-tab').forEach(b => b.addEventListener('click', () => {
      if (A.pro.tab === b.dataset.tab) return;
      A.pro.tab = b.dataset.tab; refresh(true);
    }));

    const ty = $('#prToYear');
    ty && ty.addEventListener('change', e => { A.pro.toEc = parseInt(e.target.value, 10) || A.pro.toEc; });
    const gr = $('#prGrade');
    gr && gr.addEventListener('change', e => { A.pro.grade_id = e.target.value; });

    const pv = $('#prPreviewBtn');
    pv && pv.addEventListener('click', async () => {
      busy(pv, true);
      try {
        const r = await API.promotion.preview({ to_year_ec: A.pro.toEc, grade_id: A.pro.grade_id || undefined });
        A.pro.plan = r.plan || []; A.pro.summary = r.summary || {}; A.pro.res = {};
        toast(r._message || t('prPreview'));
        refresh(true);
      } catch (e) { fail(e); busy(pv, false); }
    });

    $$('.pr-res').forEach(sel => sel.addEventListener('change', () => {
      A.pro.res[sel.dataset.id] = sel.value;
      refresh(true);   /* chips + the “left behind” banner follow the choice */
    }));

    const run = $('#prRunBtn');
    run && run.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ክፍል ማሳደግ', 'Promotion'),
                               message: L(`ተማሪዎቹ ወደ ${num(A.pro.toEc)} ዓ.ም ይሳደጉ? ማንነታቸው አይባዛም።`,
                                 `Promote the selected students into ${A.pro.toEc}? No student identity is duplicated.`),
                               okLabel: L('አሳድግ', 'Promote') }))) return;
      const students = (A.pro.plan || []).map(x => {
        const res = A.pro.res[x.student_id] || x.result;
        /* a changed outcome invalidates the planned target grade — let the
           server recompute it rather than pinning last render's value */
        return { student_id: x.student_id, result: res,
                 to_grade_id: res === x.result ? (x.to_grade_id || null) : null };
      });
      busy(run, true);
      try {
        const r = await API.promotion.run({
          to_year_ec: A.pro.toEc, grade_id: A.pro.grade_id || undefined,
          students: students, confirm: true
        });
        toast(r._message || I18N[lang()].prDone((r.summary || {}).promote || 0));
        A.pro.plan = null; A.pro.res = {};
        const ys = await API.settings.years().catch(() => null);
        if (ys) { DATA.years = ys.years || DATA.years; DATA.year = ys.current || DATA.year; }
        refresh(true);
      } catch (e) { fail(e); busy(run, false); }
    });

    const cl = $('#prClear');
    cl && cl.addEventListener('click', () => { A.pro.plan = null; A.pro.res = {}; refresh(true); });

    const ub = $('#prUndoBtn');
    ub && ub.addEventListener('click', async () => {
      const ec = parseInt(val('prUndoYear'), 10);
      if (!ec) return;
      if (!(await srConfirm({ title: L('ማሳደግ መመለስ', 'Roll back'),
                               message: L(`የ${num(ec)} ዓ.ም ማሳደግ ይመለስ?`, `Roll back the promotion into ${ec}?`),
                               danger: true, okLabel: L('መልስ', 'Roll back') }))) return;
      busy(ub, true);
      try {
        const r = await API.promotion.undo({ to_year_ec: ec });
        toast(r._message || t('prUndo'));
        refresh(true);
      } catch (e) { fail(e); busy(ub, false); }
    });
  }

  /* ================================================================== */
  /*  6 · USERS (Master)                                                 */
  /* ================================================================== */
  async function renderUsers() {
    const u = A.usr;
    /* Dashboard-style list: everyone is fetched once and filtered in place. */
    if (!u.all) {
      const res = await API.users.list({ per_page: 500, include_deleted: 1 });
      u.all = res.users || [];
    }
    u.list = u.all;

    const rows = u.list.map(x => `
      <tr class="${x.deleted ? 'is-deleted' : ''}" data-role="${esc(x.role || '')}" data-del="${x.deleted ? '1' : '0'}"
          data-q="${esc(([x.full_name, x.username, x.email, x.phone]).filter(Boolean).join(' ').toLowerCase())}">
        <td><strong>${esc(x.full_name)}</strong><div class="pm-b-sub">@${esc(x.username)}</div></td>
        <td><span class="pill ${x.role === 'master' ? 'b' : x.role === 'admin' ? 'g' : 'n'}">${esc(roleLbl(x.role))}</span></td>
        <td>${esc(x.email || '—')}<div class="pm-b-sub">${esc(x.phone || '')}</div></td>
        <td>${x.assigned_classes ? num(x.assigned_classes) : '—'}</td>
        <td>${x.deleted ? `<span class="pill r">${L('ተሰርዟል', 'Removed')}</span>`
              : x.is_active ? `<span class="pill g">${esc(t('usActive'))}</span>`
              : `<span class="pill n">${esc(t('stInactive'))}</span>`}</td>
        <td>${x.last_login_at ? stamp(x.last_login_at) : '—'}</td>
        <td class="text-end">
          <div class="row-acts">
            ${x.deleted
              ? `<button class="mini-btn us-restore" data-id="${x.id}" type="button">${L('መልስ', 'Restore')}</button>`
              : `<button class="mini-btn us-edit" data-id="${x.id}" type="button" title="${esc(t('edit'))}">${ICON.pencil}<span class="ra-t">${esc(t('edit'))}</span></button>
                 ${can('users.reset_password') ? `<button class="mini-btn us-pw" data-id="${x.id}" data-name="${esc(x.username)}" type="button" title="${esc(t('usReset'))}">${ICON.key}<span class="ra-t">${L('የይለፍ ቃል', 'Password')}</span></button>` : ''}
                 <button class="mini-btn us-del" data-id="${x.id}" data-name="${esc(x.username)}" type="button" title="${esc(t('del'))}">${ICON.trash}<span class="ra-t">${esc(t('del'))}</span></button>`}
          </div>
        </td>
      </tr>`).join('');

    return pageTop(t('usT'), t('usSub'),
        can('users.manage') ? `<button class="btn btn-primary" id="usNew" type="button">${ICON.uplus} ${t('usNew')}</button>` : '')
      + `<section class="card card-pad mb-3">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-5"><label class="form-label" for="usQ">${L('ፍለጋ', 'Search')}</label>
              <input class="form-control" id="usQ" type="search" value="${esc(u.q)}" placeholder="${esc(L('ስም ወይም የተጠቃሚ ስም…', 'Name or username…'))}"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="usRole">${t('usRole')}</label>
              <select class="form-select" id="usRole">
                <option value="">${esc(t('all'))}</option>
                ${ALL_ROLES.map(r => `<option value="${r}" ${u.role === r ? 'selected' : ''}>${esc(roleLbl(r))}</option>`).join('')}
              </select></div>
            <div class="col-6 col-md-4">
              <div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="usDel" ${u.deleted ? 'checked' : ''}>
                <label class="form-check-label" for="usDel">${L('የተሰረዙትን አሳይ', 'Show removed')}</label></div>
            </div>
          </div>
        </section>`
      + tblCard(`<th>${esc(t('auUser'))}</th><th>${esc(t('usRole'))}</th><th>${esc(t('email'))}</th><th>${esc(t('tcClasses'))}</th><th>${esc(t('thStatus'))}</th><th>${L('የመጨረሻ መግቢያ', 'Last sign-in')}</th><th class="text-end">${esc(t('thAction'))}</th>`,
          rows + `<tr id="usNone" hidden><td colspan="7" class="text-center pm-b-sub py-4">${esc(L('ምንም ተጠቃሚ አልተገኘም።', 'No users found.'))}</td></tr>`,
          '', 'usTbl')
      + `<p class="set-sub mt-3 mb-0" id="usCount">${esc(userCountText())}</p>`;
  }

  function userFormBody(x) {
    const v = k => esc(x && x[k] !== null && x[k] !== undefined ? x[k] : '');
    const isNew = !x;
    const role0 = x ? x.role : (ASSIGNABLE_ROLES.indexOf('teacher') >= 0 ? 'teacher' : ASSIGNABLE_ROLES[0]);
    return `
      <div class="uf-sec">
        <div class="uf-sec-t">${L('ማንነት', 'Identity')}</div>
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label" for="ufName">${t('fullName')} <span class="req">*</span></label>
            <input class="form-control" id="ufName" value="${v('full_name')}" required maxlength="160"></div>
          <div class="col-12 col-md-6"><label class="form-label" for="ufUser">${t('usUsername')} <span class="req">*</span></label>
            <input class="form-control" id="ufUser" value="${v('username')}" ${isNew ? '' : 'disabled'} required
              pattern="[a-z0-9._-]{3,64}" placeholder="a-z 0-9 . _ -" autocapitalize="off" autocomplete="off"></div>
          <div class="col-12 col-md-6"><label class="form-label" for="ufEmail">${t('email')}</label>
            <input class="form-control" id="ufEmail" type="email" value="${v('email')}" autocomplete="off"></div>
          <div class="col-12 col-md-6"><label class="form-label" for="ufPhone">${t('phone')}</label>
            <input class="form-control" id="ufPhone" type="tel" value="${v('phone')}" autocomplete="off"></div>
        </div>
      </div>

      <div class="uf-sec">
        <div class="uf-sec-t">${L('ሚና', 'Role')}</div>
        <div class="row g-3">
          <div class="col-12 col-md-6"><label class="form-label" for="ufRole">${t('usRole')}</label>
            <select class="form-select" id="ufRole">
              ${(x && x.role === 'master' ? ['master'] : ASSIGNABLE_ROLES).map(r => `<option value="${r}" ${role0 === r ? 'selected' : ''}>${esc(roleLbl(r))}</option>`).join('')}
            </select></div>
        </div>
        <div class="pm-b-sub mt-2">${L('የ«ማስተር» መለያ በዚህ በኩል አይሰጥም።', 'Master accounts cannot be granted here.')}</div>
        <!-- Language and numerals are deliberately not set here: each user
             chooses their own in Settings, so an administrator cannot
             override someone else's display preference. -->
        <div class="pm-b-sub">${L('ቋንቋና የቁጥር ምርጫ በተጠቃሚው ራሱ በ«ቅንብሮች» ውስጥ ይመረጣል።', 'Language and numeral preferences are chosen by the user in their own Settings.')}</div>
      </div>

      <div class="uf-sec" id="ufGradesWrap" ${role0 === 'teacher' ? '' : 'hidden'}>
        <div class="uf-sec-t">
          <span>${t('tcClasses')}</span>
          <span class="uf-count" id="ufCount"></span>
        </div>
        <p class="uf-sec-s">${t('ufGradesSub')}</p>
        <div class="uf-gr-tools">
          <button class="mini-btn" type="button" id="ufGrAll">${t('ufAll')}</button>
          <button class="mini-btn" type="button" id="ufGrNone">${t('ufNone')}</button>
        </div>
        <div class="uf-gr-grid">
          ${(DATA.grades || []).map(g => `
            <label class="uf-gr-lbl">
              <input class="uf-gr" type="checkbox" value="${g.id}">
              <span class="uf-gr-box" aria-hidden="true"></span>
              <span class="uf-gr-txt">${esc(GRADE_NAME(g))}</span>
            </label>`).join('')}
        </div>
      </div>

      <div class="uf-sec uf-sec-last">
        <div class="uf-sec-t">${t('ufOpts')}</div>
        <label class="uf-tog" for="ufActive">
          <input class="uf-tog-in" type="checkbox" id="ufActive" ${!x || x.is_active ? 'checked' : ''}>
          <span class="uf-sw" aria-hidden="true"></span>
          <span class="uf-tog-txt"><b>${t('usActive')}</b><i>${t('ufActiveSub')}</i></span>
        </label>
        <label class="uf-tog" for="ufMust">
          <input class="uf-tog-in" type="checkbox" id="ufMust" ${isNew ? 'checked' : ''}>
          <span class="uf-sw" aria-hidden="true"></span>
          <span class="uf-tog-txt"><b>${L('የይለፍ ቃል ይቀይር', 'Change password at first sign-in')}</b><i>${t('ufMustSub')}</i></span>
        </label>
        ${isNew ? `<div class="uf-auto">${ICON.key}
          <div><b>${t('cdAuto')}</b><i>${t('cdAutoSub')}</i></div></div>` : ''}
      </div>`;
  }

  /* Shared by the new / edit user modals: grade picker only matters for
     teachers, and the counter has to track the checkboxes. */
  function bindUserForm() {
    const wrap  = $('#ufGradesWrap');
    const role  = $('#ufRole');
    const count = $('#ufCount');
    const boxes = () => $$('.uf-gr');
    const sync = () => {
      const n = boxes().filter(c => c.checked).length;
      if (count) count.textContent = n ? t('ufPicked')(n) : '';
      boxes().forEach(c => c.closest('.uf-gr-lbl').classList.toggle('on', c.checked));
    };
    const showHide = () => { if (wrap) wrap.hidden = (role ? role.value : '') !== 'teacher'; };
    role && role.addEventListener('change', showHide);
    boxes().forEach(c => c.addEventListener('change', sync));
    const all = $('#ufGrAll');
    all && all.addEventListener('click', () => { boxes().forEach(c => { c.checked = true; }); sync(); });
    const none = $('#ufGrNone');
    none && none.addEventListener('click', () => { boxes().forEach(c => { c.checked = false; }); sync(); });
    showHide(); sync();
    return sync;
  }

  function userCountText() {
    const u = A.usr;
    const q = (u.q || '').trim().toLowerCase();
    const all = u.all || [];
    let vis = 0;
    all.forEach(x => {
      if (!userMatch(x, q)) return;
      vis++;
    });
    return vis === all.length
      ? `${num(vis)} ${L('ተጠቃሚ', 'user')}${vis === 1 ? '' : 's'}`
      : `${L('የተገኙት', 'Showing')} ${num(vis)} ${L('ከ', 'of')} ${num(all.length)}`;
  }

  function userMatch(x, q) {
    if (q && String([x.full_name, x.username, x.email, x.phone].filter(Boolean).join(' '))
              .toLowerCase().indexOf(q) === -1) return false;
    if (A.usr.role && x.role !== A.usr.role) return false;
    if (A.usr.deleted ? !x.deleted : x.deleted) return false;
    return true;
  }

  /* Toggle rows against the current filters — no reload. */
  function applyUsersFilter() {
    const table = $('#usTbl');
    if (!table) return;
    const q = (A.usr.q || '').trim().toLowerCase();
    let vis = 0;
    $$('tbody tr[data-q]', table).forEach(tr => {
      const ok = (!q || (tr.dataset.q || '').indexOf(q) !== -1)
              && (!A.usr.role || tr.dataset.role === A.usr.role)
              && (A.usr.deleted ? tr.dataset.del === '1' : tr.dataset.del === '0');
      tr.classList.toggle('match-hide', !ok);
      if (ok) vis++;
    });
    const none = $('#usNone');
    if (none) none.hidden = vis > 0;
    const c = $('#usCount');
    if (c) c.textContent = vis === (A.usr.all || []).length
      ? `${num(vis)} ${L('ተጠቃሚ', 'user')}${vis === 1 ? '' : 's'}`
      : `${L('የተገኙት', 'Showing')} ${num(vis)} ${L('ከ', 'of')} ${num(A.usr.all ? A.usr.all.length : 0)}`;
  }

  function bindUsers() {
    const q = $('#usQ');
    q && q.addEventListener('input', e => { A.usr.q = e.target.value; applyUsersFilter(); });
    const r = $('#usRole');
    r && r.addEventListener('change', e => { A.usr.role = e.target.value; applyUsersFilter(); });
    const d = $('#usDel');
    d && d.addEventListener('change', e => { A.usr.deleted = e.target.checked; applyUsersFilter(); });

    const nw = $('#usNew');
    nw && nw.addEventListener('click', () => {
      openModal({
        title: esc(t('usNew')),
        sub: esc(t('cdAutoSub')),
        body: userFormBody(null),
        submit: t('usNew'),
        bind: bindUserForm,
        onSubmit: async () => {
          const isTeacher = val('ufRole') === 'teacher';
          const grades = isTeacher ? $$('.uf-gr').filter(c => c.checked).map(c => c.value) : [];
          const uname = val('ufUser');
          /* password is deliberately omitted — the server mints a
             temporary one and returns it exactly once. */
          const r2 = await API.users.create({
            full_name: val('ufName'), username: uname, role: val('ufRole'),
            email: val('ufEmail'), phone: val('ufPhone'),
            is_active: chk('ufActive'), must_change_pw: chk('ufMust'), grade_ids: grades
          });
          A.usr.all = null; A.prm.users = null;
          refresh(true);
          if (r2 && r2.temp_password) {
            credentialModal({
              title: t('cdReady'),
              sub: t('cdSub'),
              username: uname,
              password: r2.temp_password,
              note: r2.must_change_pw ? t('cdNote') : ''
            });
          } else {
            toast(r2._message || t('saved'));
            closeModal();
          }
        }
      });
    });

    $$('.us-edit').forEach(b => b.addEventListener('click', async () => {
      busy(b, true);
      try {
        const det = await API.users.get(b.dataset.id);
        const x = det.user;
        const mine = (det.classes || []).map(c => String(c.grade_id));
        openModal({
          title: esc(x.full_name),
          sub: '@' + esc(x.username),
          body: userFormBody(x),
          submit: t('save'),
          bind: () => {
            $$('.uf-gr').forEach(c => { c.checked = mine.indexOf(c.value) >= 0; });
            bindUserForm();
          },
          onSubmit: async () => {
            const r2 = await API.users.update({
              id: x.id, full_name: val('ufName'), role: val('ufRole'),
              email: val('ufEmail'), phone: val('ufPhone'),
              is_active: chk('ufActive')
            });
            const grades = $$('.uf-gr').filter(c => c.checked).map(c => c.value);
            if (val('ufRole') === 'teacher' && can('teachers.assign')) {
              await API.teachers.assign({ user_id: x.id, grade_ids: grades, replace: true });
            }
            toast(r2._message || t('saved'));
            closeModal(); A.usr.all = null; A.prm.users = null;
            refresh(true);
          }
        });
      } catch (e) { fail(e); } finally { busy(b, false); }
    }));

    /* Reset never asks the admin to invent a password — the server mints a
       temporary one, forces must_change_pw, and we hand it over once. */
    $$('.us-pw').forEach(b => b.addEventListener('click', () => {
      openModal({
        title: esc(t('usReset')),
        sub: '@' + esc(b.dataset.name),
        body: `<div class="uf-auto uf-auto-lg">${ICON.key}
                 <div><b>${esc(t('cdGen'))}</b><i>${esc(t('cdResetSub'))}</i></div>
               </div>
               <p class="cred-note mb-0">${esc(t('cdNote'))}</p>`,
        submit: t('cdGen'),
        onSubmit: async () => {
          const r2 = await API.users.resetPassword({ id: b.dataset.id }); A.usr.all = null;
          credentialModal({
            title: t('usReset'),
            sub: t('cdSub'),
            username: r2.username || b.dataset.name,
            password: r2.temp_password,
            note: t('cdNote')
          });
        }
      });
    }));

    $$('.us-del').forEach(b => b.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ተጠቃሚ ማስወገድ', 'Remove user'),
                               message: L(`«${b.dataset.name}» ይሰረዝ?`, `Remove “${b.dataset.name}”?`),
                               danger: true, okLabel: L('አስወግድ', 'Remove') }))) return;
      try { const r2 = await API.users.remove(b.dataset.id); toast(r2._message || t('removedToast')); A.usr.all = null; A.prm.users = null; refresh(true); }
      catch (e) { fail(e); }
    }));
    $$('.us-restore').forEach(b => b.addEventListener('click', async () => {
      try { await API.users.restore(b.dataset.id); toast(t('saved')); A.usr.all = null; A.prm.users = null; refresh(true); } catch (e) { fail(e); }
    }));

    /* re-apply the current filters whenever the page re-renders */
    applyUsersFilter();
  }

  /* ================================================================== */
  /*  7 · TEACHERS — grade assignment matrix                             */
  /* ================================================================== */
  async function renderTeachers() {
    const res = await API.teachers.list({});
    A.tch.list = res.teachers || [];
    const yearEc = (res.year && res.year.year_ec) || state.openYear;

    /* A teacher reads better as a roster card than as a five-column
       table that leaves most of the row empty. */
    const cards = A.tch.list.map(x => {
      const initials = (x.full_name || '?').trim().charAt(0).toUpperCase();
      const chips = (x.grades || []).length
        ? x.grades.map(g => `<span class="tc-chip">${esc(am() ? g.name_am : g.name_en)}
             ${can('teachers.assign') ? `<button class="tc-un" data-uid="${x.id}" data-gid="${g.id}" type="button"
                 aria-label="${L('አስወግድ', 'Remove')} ${esc(am() ? g.name_am : g.name_en)}">✕</button>` : ''}
           </span>`).join('')
        : `<span class="tc-none">${L('ምንም ክፍል አልተመደበም።', 'No grades assigned yet.')}</span>`;
      return `<article class="tc-card">
        <div class="tc-top">
          <span class="tc-av">${esc(initials)}</span>
          <div class="tc-id">
            <strong>${esc(x.full_name)}</strong>
            <span>@${esc(x.username)}</span>
          </div>
          <span class="pill ${x.is_active ? 'g' : 'n'} tc-st">${x.is_active ? esc(t('usActive')) : esc(t('stInactive'))}</span>
        </div>
        <div class="tc-cls">
          <span class="tc-cls-lb">${esc(t('tcClasses'))} <b>${num((x.grades || []).length)}</b></span>
          <div class="tc-chips">${chips}</div>
        </div>
        <div class="tc-foot">
          <span class="tc-seen">${L('የመጨረሻ መግቢያ', 'Last sign-in')} · ${x.last_login_at ? stamp(x.last_login_at) : L('በጭራሽ', 'never')}</span>
          ${can('teachers.assign')
            ? `<button class="btn btn-soft tc-assign" data-id="${x.id}" data-name="${esc(x.full_name)}" type="button">${esc(t('tcAssign'))}</button>` : ''}
        </div>
      </article>`;
    }).join('');

    return pageTop(t('tcT'), `${t('tcSub')} · ${num(yearEc)}`, '')
      + `<div class="tc-note">${ICON.shield || ''}<span>${L('መምህር የተመደበለትን ክፍል ብቻ ያያል — ይህ በአገልጋዩ በኩል ይጠበቃል።',
          'A teacher only ever sees their assigned grades — enforced server-side, not by hiding menus.')}</span></div>`
      + (cards
          ? `<div class="tc-grid">${cards}</div>`
          : emptyCard(L('ምንም መምህር የለም።', 'No teacher accounts yet.')));
  }

  function bindTeachers() {
    $$('.tc-assign').forEach(b => b.addEventListener('click', () => {
      const teacher = (A.tch.list || []).find(x => String(x.id) === String(b.dataset.id)) || {};
      const mine = (teacher.grades || []).map(g => String(g.id));
      openModal({
        title: esc(t('tcAssign')),
        sub: esc(b.dataset.name),
        body: `<div class="pm-chip-row">
                 ${(DATA.grades || []).map(g => `
                   <label class="pill ${mine.indexOf(String(g.id)) >= 0 ? 'b' : 'n'}">
                     <input class="form-check-input me-1 tc-gr" type="checkbox" value="${g.id}" ${mine.indexOf(String(g.id)) >= 0 ? 'checked' : ''}>
                     ${esc(GRADE_NAME(g))}</label>`).join('')}
               </div>
               <div class="pm-b-sub">${L('የተመረጡት ክፍሎች የቀደመውን ምደባ ይተካሉ።', 'The selected grades replace the current assignment.')}</div>`,
        submit: t('tcAssign'),
        onSubmit: async () => {
          const ids = $$('.tc-gr').filter(c => c.checked).map(c => c.value);
          const r = await API.teachers.assign({ user_id: b.dataset.id, grade_ids: ids, replace: true });
          toast(r._message || t('saved'));
          closeModal(); refresh(true);
        }
      });
    }));

    $$('.tc-un').forEach(b => b.addEventListener('click', async ev => {
      ev.stopPropagation();
      try {
        const r = await API.teachers.unassign({ user_id: b.dataset.uid, grade_id: b.dataset.gid });
        toast(r._message || t('saved'));
        refresh(true);
      } catch (e) { fail(e); }
    }));
  }

  /* ================================================================== */
  /*  8 · AUDIT TRAIL                                                    */
  /* ================================================================== */
  async function renderAudit() {
    const a = A.aud;
    if (!a.filters) a.filters = await API.audit.filters().catch(() => ({ actions: [], entity_types: [], users: [] }));
    /* Dashboard-style list: the latest window of entries is fetched once and
       every filter keystroke just toggles rows — no reload, no round-trip. */
    if (!a.all) {
      const res = await API.audit.list({ page: 1, per_page: 200, lang: lang() });
      a.all = res.logs || [];
      a.tot = res.total || 0;
    }

    const rows = a.all.map(x => `
      <tr data-action="${esc(x.action || '')}" data-ent="${esc(x.entity_type || '')}"
          data-role="${esc(x.role || '')}" data-date="${esc(String(x.created_at || '').slice(0, 10))}"
          data-q="${esc(String([x.summary, x.username, x.ip_address, x.action, x.entity_type]
                                .filter(Boolean).join(' ')).toLowerCase())}">
        <td><span class="pm-b-sub">${num(x.id)}</span></td>
        <td>${esc(x.username || '—')}<div class="pm-b-sub">${esc(roleLbl(x.role || 'teacher'))}</div></td>
        <td><span class="pill ${/deleted|purge|failed/.test(x.action) ? 'r' : /created|added|imported|promoted/.test(x.action) ? 'g' : 'b'}">${esc(x.action)}</span></td>
        <td>${esc(x.entity_type || '—')}${x.entity_id ? ` <span class="pm-b-sub">#${num(x.entity_id)}</span>` : ''}</td>
        <td>${esc(x.summary || '')}</td>
        <td>${esc(x.ip_address || '—')}</td>
        <td>${stamp(x.created_at)}</td>
      </tr>`).join('');

    const opts = (arr, cur) => arr.map(v => `<option value="${esc(v)}" ${cur === v ? 'selected' : ''}>${esc(v)}</option>`).join('');
    const filters = `
      <section class="card card-pad mb-3">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-3"><label class="form-label" for="auQ">${L('ፍለጋ', 'Search')}</label>
            <input class="form-control" id="auQ" type="search" value="${esc(a.f.q)}" placeholder="${esc(L('ማጠቃለያ ወይም ተጠቃሚ…', 'Summary or user…'))}"></div>
          <div class="col-6 col-md-2"><label class="form-label" for="auAction">${t('auAction')}</label>
            <select class="form-select" id="auAction"><option value="">${esc(t('all'))}</option>${opts(a.filters.actions || [], a.f.action_type)}</select></div>
          <div class="col-6 col-md-2"><label class="form-label" for="auEnt">${t('auTarget')}</label>
            <select class="form-select" id="auEnt"><option value="">${esc(t('all'))}</option>${opts(a.filters.entity_types || [], a.f.entity_type)}</select></div>
          <div class="col-4 col-md-1"><label class="form-label" for="auRole">${t('usRole')}</label>
            <select class="form-select" id="auRole"><option value="">${esc(t('all'))}</option>
              ${ALL_ROLES.map(r => `<option value="${r}" ${a.f.role === r ? 'selected' : ''}>${esc(roleLbl(r))}</option>`).join('')}</select></div>
          <div class="col-4 col-md-2"><label class="form-label" for="auFrom">${L('ከ', 'From')}</label>
            <input class="form-control" id="auFrom" type="date" value="${esc(a.f.from)}"></div>
          <div class="col-4 col-md-2"><label class="form-label" for="auTo">${L('እስከ', 'To')}</label>
            <input class="form-control" id="auTo" type="date" value="${esc(a.f.to)}"></div>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-3">
          <button class="btn btn-ghost" id="auReset" type="button">${L('አጽዳ', 'Reset')}</button>
          <button class="btn btn-soft" id="auExport" type="button">${ICON.down} ${t('auExport')}</button>
          ${can('audit.purge') ? `<button class="btn btn-ghost pm-del ms-auto" id="auPurge" type="button">${t('auPurge')}</button>` : ''}
        </div>
      </section>`;

    const noneRow = `<tr id="auNone" hidden><td colspan="7" class="text-center pm-b-sub py-4">${esc(L('ምንም መዝገብ የለም።', 'No audit entries match.'))}</td></tr>`;

    return pageTop(t('auT'), t('auSub'), '')
      + filters
      + tblCard(`<th>#</th><th>${esc(t('auUser'))}</th><th>${esc(t('auAction'))}</th><th>${esc(t('auTarget'))}</th><th>${esc(t('thMessage'))}</th><th>IP</th><th>${esc(t('auWhen'))}</th>`,
          rows + noneRow, '', 'auTbl')
      + `<p class="set-sub mt-3 mb-0" id="auCount">${esc(auditCountText())}</p>`;
  }

  function auditCountText() {
    const a = A.aud;
    const logs = a.all || [];
    let vis = 0;
    logs.forEach(x => {
      if (auditMatch(x)) vis++;
    });
    return vis === logs.length
      ? `${num(vis)} ${L('መዝገቦች', 'entries')}`
      : `${L('የተገኙት', 'Showing')} ${num(vis)} ${L('ከ', 'of')} ${num(logs.length)}`;
  }

  function auditMatch(x) {
    const f = A.aud.f || {};
    const q = (f.q || '').trim().toLowerCase();
    if (q && String([x.summary, x.username, x.ip_address, x.action, x.entity_type]
                    .filter(Boolean).join(' ')).toLowerCase().indexOf(q) === -1) return false;
    if (f.action_type && x.action !== f.action_type) return false;
    if (f.entity_type && x.entity_type !== f.entity_type) return false;
    if (f.role && x.role !== f.role) return false;
    const d = String(x.created_at || '').slice(0, 10);
    if (f.from && d < f.from) return false;
    if (f.to && d > f.to) return false;
    return true;
  }

  /* Toggle rows against the current filters — no reload, focus kept. */
  function applyAuditFilter() {
    const table = $('#auTbl');
    if (!table) return;
    let vis = 0;
    $$('tbody tr[data-q]', table).forEach(tr => {
      const f = A.aud.f || {};
      const q = (f.q || '').trim().toLowerCase();
      const ok = (!q || (tr.dataset.q || '').indexOf(q) !== -1)
              && (!f.action_type || tr.dataset.action === f.action_type)
              && (!f.entity_type || tr.dataset.ent === f.entity_type)
              && (!f.role || tr.dataset.role === f.role)
              && (!f.from || (tr.dataset.date || '') >= f.from)
              && (!f.to || (tr.dataset.date || '') <= f.to);
      tr.classList.toggle('match-hide', !ok);
      if (ok) vis++;
    });
    const none = $('#auNone');
    if (none) none.hidden = vis > 0;
    const c = $('#auCount');
    if (c) c.textContent = vis === (A.aud.all || []).length
      ? `${num(vis)} ${L('መዝገቦች', 'entries')}`
      : `${L('የተገኙት', 'Showing')} ${num(vis)} ${L('ከ', 'of')} ${num(A.aud.all ? A.aud.all.length : 0)}`;
  }

  function bindAudit() {
    const q = $('#auQ');
    q && q.addEventListener('input', e => { A.aud.f.q = e.target.value; applyAuditFilter(); });
    const ac = $('#auAction'); ac && ac.addEventListener('change', e => { A.aud.f.action_type = e.target.value; applyAuditFilter(); });
    const en = $('#auEnt');    en && en.addEventListener('change', e => { A.aud.f.entity_type = e.target.value; applyAuditFilter(); });
    const ro = $('#auRole');   ro && ro.addEventListener('change', e => { A.aud.f.role = e.target.value; applyAuditFilter(); });
    const fr = $('#auFrom');   fr && fr.addEventListener('change', e => { A.aud.f.from = e.target.value; applyAuditFilter(); });
    const to = $('#auTo');     to && to.addEventListener('change', e => { A.aud.f.to = e.target.value; applyAuditFilter(); });
    const rs = $('#auReset');
    rs && rs.addEventListener('click', () => {
      A.aud.f = { action_type: '', entity_type: '', role: '', q: '', from: '', to: '' };
      const qi = $('#auQ'); if (qi) qi.value = '';
      const ac2 = $('#auAction'); if (ac2) ac2.value = '';
      const en2 = $('#auEnt'); if (en2) en2.value = '';
      const ro2 = $('#auRole'); if (ro2) ro2.value = '';
      const fr2 = $('#auFrom'); if (fr2) fr2.value = '';
      const to2 = $('#auTo'); if (to2) to2.value = '';
      A.focus = null;
      applyAuditFilter();
    });

    const ex = $('#auExport');
    ex && ex.addEventListener('click', () => {
      const a = document.createElement('a');
      a.href = 'api/audit.php?action=export';
      a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
      toast(L('ማውረድ ተጀምሯል…', 'Download started…'));
    });

    /* re-apply the current filters whenever the page re-renders */
    applyAuditFilter();

    const pg = $('#auPurge');
    pg && pg.addEventListener('click', () => {
      openModal({
        title: esc(t('auPurge')),
        sub: L('ከተወሰነ ቀን በፊት ያሉ መዝገቦች ይሰረዛሉ።', 'Entries older than the given age are deleted.'),
        danger: true,
        body: `<label class="form-label" for="auDays">${L('ከስንት ቀናት በፊት', 'Older than (days)')}</label>
               <input class="form-control" id="auDays" type="number" min="1" value="365">`,
        submit: t('auPurge'),
        onSubmit: async () => {
          const r = await API.audit.purge({ older_than_days: val('auDays') });
          toast(r._message || t('saved'));
          closeModal(); A.aud.all = null;
          refresh(true);
        }
      });
    });
  }

  /* ================================================================== */
  /*  9 · BACKUP & RESTORE (Master)                                      */
  /* ================================================================== */
  async function renderBackup() {
    const res = await API.backup.list().catch(() => ({ backups: [], dir: 'backups' }));
    A.bak.list = res.backups || [];

    const rows = A.bak.list.map(b => `
      <tr>
        <td><strong>${esc(b.file)}</strong></td>
        <td>${num(Math.max(1, Math.round(b.bytes / 1024)))} KB</td>
        <td>${stamp(String(b.created_at).replace('T', ' '))}</td>
        <td class="text-end">
          <div class="d-inline-flex gap-1">
            <button class="mini-btn bk-restore" data-file="${esc(b.file)}" type="button">${esc(t('bkRestore'))}</button>
            <button class="mini-btn bk-del" data-file="${esc(b.file)}" type="button">${esc(t('del'))}</button>
          </div>
        </td>
      </tr>`).join('');

    const prev = A.bak.preview;
    const previewCard = prev ? card(L('የመልሶ ማግኛ ቅድመ እይታ', 'Restore preview'),
      `${esc(prev.created_by || '')} · ${stamp(String(prev.created_at || '').replace('T', ' '))}`,
      `<div class="pm-chip-row">
         ${Object.keys(prev.counts || {}).map(k => `<span class="pill b">${esc(k)} · ${num(prev.counts[k])}</span>`).join('')}
       </div>
       <p class="set-sub">${esc(t('bkWarn'))}</p>
       <div class="d-flex gap-2 flex-wrap">
         <button class="btn btn-primary" id="bkConfirm" type="button">${t('confirm')} — ${t('bkRestore')}</button>
         <button class="btn btn-ghost" id="bkCancel" type="button">${t('cancel')}</button>
       </div>`, 'set-danger') : '';

    return pageTop(t('bkT'), t('bkSub'),
        `<button class="btn btn-primary" id="bkCreate" type="button">${ICON.db} <span class="ph-btn-txt">${t('bkCreate')}</span></button>`)
      + card('',
          L('ሙሉ የውሂብ ቅጂ በJSON ይወርዳል፤ ቅጂውም በአገልጋዩ ላይ ይቀመጣል። የይለፍ ቃል ጠረጋዎች ተመስጥረው ናቸው፤ የመግቢያ መረጃ አይካተትም።',
            'Downloads a full JSON snapshot and keeps a copy on the server. Password hashes stay hashed and no database credentials are ever written into the file.'),
          `<div class="bk-act">
             <button class="btn btn-soft" id="bkCreate2" type="button">${ICON.down} ${t('bkCreate')}</button>
             <label class="bk-chk" for="bkAudit">
               <input class="form-check-input" type="checkbox" id="bkAudit" checked>
               <span>${L('የሥራ መዝገቡን አካትት', 'Include the audit log')}</span>
             </label>
           </div>`)
      + previewCard
      + card(L('ከፋይል መመለስ', 'Restore from a file'), esc(t('bkWarn')),
          `<div class="up-row">
             <div class="up-field">
               <label class="form-label" for="bkFile">${t('bkFile')}</label>
               <input class="form-control" id="bkFile" type="file" accept=".json,application/json">
             </div>
             <div class="up-go">
               <button class="btn btn-ghost" id="bkUpload" type="button">${ICON.upload} ${t('bkRestore')}</button>
             </div>
           </div>`, 'set-danger')
      + card(L('በአገልጋዩ ላይ ያሉ ምትኬዎች', 'Backups on the server'),
          L('የተቀመጡበት አቃፊ', 'Stored in') + ` <code class="bk-dir">${esc(res.dir || 'backups')}/</code>`,
          tblIn(`<th>${esc(t('bkFile'))}</th><th>${L('መጠን', 'Size')}</th><th>${esc(t('auWhen'))}</th><th class="text-end">${esc(t('thAction'))}</th>`,
            rows, L('ገና ምንም ምትኬ የለም።', 'No backups stored yet.')));
  }

  function bindBackup() {
    const create = () => {
      const a = document.createElement('a');
      a.href = 'api/backup.php?action=create&download=1&include_audit=' + (chk('bkAudit') ? '1' : '0');
      a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
      toast(L('ምትኬ እየተዘጋጀ ነው…', 'Preparing the backup…'));
      setTimeout(() => refresh(true), 1500);
    };
    const c1 = $('#bkCreate');  c1 && c1.addEventListener('click', create);
    const c2 = $('#bkCreate2'); c2 && c2.addEventListener('click', create);

    const up = $('#bkUpload');
    up && up.addEventListener('click', async () => {
      const inp = $('#bkFile');
      const file = inp && inp.files && inp.files[0];
      if (!file) { toast(L('እባክዎ ፋይል ይምረጡ።', 'Please choose a file.'), 'error'); return; }
      busy(up, true);
      try {
        const r = await API.backup.restoreFile(file, false);
        A.bak.preview = r; A.bak.pending = { kind: 'file', file: file };
        refresh(true);
      } catch (e) { fail(e); busy(up, false); }
    });

    $$('.bk-restore').forEach(b => b.addEventListener('click', async () => {
      busy(b, true);
      try {
        const r = await API.backup.restore({ file: b.dataset.file });
        A.bak.preview = r; A.bak.pending = { kind: 'name', file: b.dataset.file };
        refresh(true);
      } catch (e) { fail(e); busy(b, false); }
    }));

    const cf = $('#bkConfirm');
    cf && cf.addEventListener('click', async () => {
      if (!A.bak.pending) return;
      if (!(await srConfirm({ title: L('መልሶ ማግኘት', 'Restore'),
                               message: t('bkWarn') + '\n' + L('መቀጠል ይፈልጋሉ?', 'Continue?'),
                               danger: true, okLabel: L('አስመልስ', 'Restore') }))) return;
      busy(cf, true);
      try {
        const p = A.bak.pending;
        const r = p.kind === 'file'
          ? await API.backup.restoreFile(p.file, true)
          : await API.backup.restore({ file: p.file, confirm: true });
        toast(r._message || L('መልሶ ማግኘት ተጠናቋል።', 'Restore complete.'));
        A.bak.preview = null; A.bak.pending = null;
        setTimeout(() => location.reload(), 1200);
      } catch (e) { fail(e); busy(cf, false); }
    });
    const cc = $('#bkCancel');
    cc && cc.addEventListener('click', () => { A.bak.preview = null; A.bak.pending = null; refresh(true); });

    $$('.bk-del').forEach(b => b.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ምትኬ ማስወገድ', 'Delete backup'),
                               message: L(`«${b.dataset.file}» ይሰረዝ?`, `Delete “${b.dataset.file}”?`),
                               danger: true, okLabel: L('አስወግድ', 'Delete') }))) return;
      try { const r = await API.backup.remove(b.dataset.file); toast(r._message || t('removedToast')); refresh(true); }
      catch (e) { fail(e); }
    }));
  }

  /* ================================================================== */
  /*  10 · PERMISSIONS — the Master's role & access editor               */
  /* ================================================================== */
  const CAP_GROUP_LB = {
    general   : ['አጠቃላይ', 'General'],
    students  : ['ተማሪዎች', 'Students'],
    attendance: ['መገኘት', 'Attendance'],
    academics : ['ትምህርታዊ', 'Academics'],
    people    : ['ሰዎች', 'People'],
    system    : ['ሥርዓት', 'System']
  };
  /* Powers the server pins to the Master; shown locked so the reason is
     visible rather than the checkbox silently refusing to stick. */
  const CAP_RESERVED = ['permissions.manage', 'audit.view', 'audit.purge', 'backup.manage', 'features.manage'];

  const capLb = (labels, c) => {
    const l = labels && labels[c];
    return l ? (am() ? l.am : l.en) : c;
  };
  const groupLb = k => {
    const g = CAP_GROUP_LB[k];
    return g ? (am() ? g[0] : g[1]) : k;
  };

  async function renderPermissions(parts) {
    const P = A.prm;
    if (!P.matrix) P.matrix = await API.permissions.matrix();
    const M = P.matrix;
    const cat = M.catalog || {};
    const labels = M.labels || {};

    const tabs = `<div class="grp-tabs prm-tabs">
      <button class="grp-tab ${P.tab === 'roles' ? 'active' : ''}" data-ptab="roles" type="button">${L('በሚና', 'By role')}</button>
      <button class="grp-tab ${P.tab === 'user' ? 'active' : ''}" data-ptab="user" type="button">${L('በተጠቃሚ', 'By user')}</button>
    </div>`;

    const top = pageTop(L('ፈቃዶች', 'Roles & access'), '',
        P.tab === 'roles' ? `<button class="btn btn-primary" id="prmNewRole" type="button">${ICON.plus} ${L('አዲስ ሚና', 'New role')}</button>` : '');

    /* ---------------- by role ---------------- */
    if (P.tab === 'roles') {
      /* 'master' appears in this picker only for the Master itself. */
      const roles = (M.roles || []).filter(r => r.role !== 'master' || isMaster());
      const cur = roles.find(r => r.role === P.role) || roles[0];
      if (!cur) return top + tabs + emptyCard();
      P.role = cur.role;

      /* draft = the checkbox state being edited, seeded from the server */
      if (!P.draft || P.draft.role !== cur.role) {
        P.draft = { role: cur.role, caps: {} };
        (cur.caps || []).forEach(c => { P.draft.caps[c] = true; });
      }

      const pick = roles.map(r => `
        <button class="prm-rolebtn ${r.role === cur.role ? 'active' : ''} ${r.editable ? '' : 'locked'}"
                data-prole="${r.role}" type="button">
          <span class="prm-rb-n">${esc(roleLbl(r.role))}</span>
          <span class="prm-rb-c">${num((r.caps || []).length)} ${L('ፈቃድ', 'perms')}</span>
          ${r.customised ? `<span class="prm-dot" title="${L('ተስተካክሏል', 'Customised')}"></span>` : ''}
        </button>`).join('');

      const readOnly = !cur.editable;
      const defaults = cur.defaults || [];

      const groups = Object.keys(cat).map(gk => {
        const rows = (cat[gk] || []).map(c => {
          const on = readOnly ? true : !!P.draft.caps[c];
          const reserved = CAP_RESERVED.indexOf(c) !== -1;
          const locked = readOnly || reserved;
          const isDef = defaults.indexOf(c) !== -1;
          return `<label class="prm-row ${locked ? 'locked' : ''}">
            <input class="form-check-input prm-cap" type="checkbox" value="${esc(c)}"
                   ${on ? 'checked' : ''} ${locked ? 'disabled' : ''}>
            <span class="prm-cap-x">
              <span class="prm-cap-n">${esc(capLb(labels, c))}</span>
              <code class="prm-cap-k">${esc(c)}</code>
            </span>
            ${reserved && !readOnly ? `<span class="prm-tag">${L('ለዋና ብቻ', 'Master only')}</span>`
              : (isDef ? `<span class="prm-tag soft">${L('ነባሪ', 'Default')}</span>` : '')}
          </label>`;
        }).join('');
        return `<div class="prm-grp">
          <div class="prm-grp-h">${esc(groupLb(gk))}</div>
          <div class="prm-grp-b">${rows}</div>
        </div>`;
      }).join('');

      const bar = readOnly
        ? `<div class="prm-note">${ICON.shield}<span>${L('የዋና አስተዳዳሪ ፈቃዶች ሁልጊዜ ሙሉ ናቸው፤ ሊቀየሩ አይችሉም።',
            'The Master account always holds every permission and cannot be edited.')}</span></div>`
        : `<div class="set-savebar">
             ${cur.customised ? `<button class="btn btn-ghost" id="prmReset" type="button">${L('ወደ ነባሪ መልስ', 'Reset to defaults')}</button>` : ''}
             <span class="set-dim">${L('ለውጦች እስኪያስቀምጡ አይተገበሩም።', 'Changes apply once you save.')}</span>
             <button class="btn btn-primary" id="prmSave" type="button">${ICON.check} ${L('አስቀምጥ', 'Save')}</button>
           </div>`;

      return top + tabs
        + `<div class="prm-picker">${pick}</div>`
        + `<section class="card set-card"><div class="set-body prm-groups">${groups}</div></section>`
        + bar;
    }

    /* ---------------- by user ---------------- */
    if (!P.users) {
      const r = await API.users.list({ per_page: 200 }).catch(() => ({ users: [] }));
      /* Non-master viewers never see Master accounts here; the Master does. */
      P.users = (r.users || []).filter(u => u.role !== 'master' || isMaster());
    }
    const opts = `<option value="">${L('ተጠቃሚ ይምረጡ…', 'Choose a user…')}</option>` +
      P.users.map(u => `<option value="${u.id}" ${String(P.userId) === String(u.id) ? 'selected' : ''}>
          ${esc(u.full_name || u.username)} · ${esc(roleLbl(u.role))}</option>`).join('');

    const chooser = `<section class="card set-card"><div class="set-body">
        <div class="set-f w-half" style="max-width:420px">
          <label class="set-lb" for="prmUser">${L('ተጠቃሚ', 'User')}</label>
          <select class="form-select" id="prmUser">${opts}</select>
          <span class="set-hint">${isMaster()
            ? L('የዋና አስተዳዳሪ መለያዎች ሁልጊዜ ሙሉ ፈቃድ አላቸው፤ ሊቀየሩ አይችሉም።', 'Master accounts always hold every permission and cannot be overridden.')
            : L('የዋና አስተዳዳሪ መለያዎች እዚህ አይታዩም።', 'Master accounts are not listed — they always hold everything.')}</span>
        </div>
      </div></section>`;

    if (!P.userId) return top + tabs + chooser;

    const U = P.userData;
    if (!U || String(U.user.id) !== String(P.userId)) {
      P.userData = await API.permissions.user(P.userId);
      P.userDraft = Object.assign({}, P.userData.overrides || {});
      return renderPermissions(parts);
    }
    const roleCaps = U.role_caps || [];
    const draft = P.userDraft || {};

    const uGroups = Object.keys(cat).map(gk => {
      const rows = (cat[gk] || []).map(c => {
        const fromRole = roleCaps.indexOf(c) !== -1;
        const reserved = CAP_RESERVED.indexOf(c) !== -1;
        const ov = Object.prototype.hasOwnProperty.call(draft, c) ? (draft[c] ? 'allow' : 'deny') : 'inherit';
        const eff = ov === 'inherit' ? fromRole : ov === 'allow';
        return `<div class="prm-row urow ${reserved ? 'locked' : ''}">
          <span class="prm-cap-x">
            <span class="prm-cap-n">${esc(capLb(labels, c))}</span>
            <code class="prm-cap-k">${esc(c)}</code>
          </span>
          <span class="prm-eff ${eff ? 'yes' : 'no'}">${eff ? L('አለው', 'Allowed') : L('የለውም', 'Denied')}</span>
          <select class="form-select form-select-sm prm-ov" data-cap="${esc(c)}" ${reserved ? 'disabled' : ''}>
            <option value="inherit" ${ov === 'inherit' ? 'selected' : ''}>${L('ከሚናው', 'Inherit')} (${fromRole ? L('አለው', 'allowed') : L('የለውም', 'denied')})</option>
            <option value="allow"   ${ov === 'allow' ? 'selected' : ''}>${L('ፍቀድ', 'Grant')}</option>
            <option value="deny"    ${ov === 'deny' ? 'selected' : ''}>${L('ከልክል', 'Revoke')}</option>
          </select>
        </div>`;
      }).join('');
      return `<div class="prm-grp">
        <div class="prm-grp-h">${esc(groupLb(gk))}</div>
        <div class="prm-grp-b">${rows}</div>
      </div>`;
    }).join('');

    const nOv = Object.keys(draft).length;
    const head = `<div class="prm-uhead">
        <div>
          <b>${esc(U.user.full_name || U.user.username)}</b>
          <span class="set-dim"> · ${esc(roleLbl(U.user.role))}</span>
        </div>
        <span class="pill ${nOv ? 'b' : 'n'}">${nOv
          ? L(`${num(nOv)} ልዩ ፈቃድ`, `${nOv} override${nOv === 1 ? '' : 's'}`)
          : L('ሚናውን ይከተላል', 'Follows the role')}</span>
      </div>`;

    /* A Master account is read-only here too: it always holds everything. */
    const masterBar = U.user.role === 'master'
      ? `<div class="prm-note">${ICON.shield}<span>${L('የዋና አስተዳዳሪ ፈቃዶች ሁልጊዜ ሙሉ ናቸው፤ ሊቀየሩ አይችሉም።',
          'The Master account always holds every permission and cannot be edited.')}</span></div>`
      : `<div class="set-savebar">
           ${nOv ? `<button class="btn btn-ghost" id="prmUClear" type="button">${L('ሁሉንም አጽዳ', 'Clear all overrides')}</button>` : ''}
           <span class="set-dim">${L('ልዩ ፈቃዶች ከሚናው ላይ ተጨምረው ይሠራሉ።', 'Overrides are applied on top of the role.')}</span>
           <button class="btn btn-primary" id="prmUSave" type="button">${ICON.check} ${L('አስቀምጥ', 'Save')}</button>
         </div>`;

    return top + tabs + chooser
      + `<section class="card set-card">${head}<div class="set-body prm-groups">${uGroups}</div></section>`
      + masterBar;
  }

  function bindPermissions() {
    const P = A.prm;

    const newRole = $('#prmNewRole');
    newRole && newRole.addEventListener('click', () => {
      openModal({
        title: esc(L('አዲስ ሚና መፍጠር', 'Create a new role')),
        sub: esc(L('ከተጠበቁ ስሞች መጠቀም አይቻልም፤ አዲሱ ሚና ከመምህር ደረጃ በላይ ፈቃዶችን አይቀበልም።',
                   'Built-in names are taken; a new role never receives master-level powers.')),
        body: `<label class="form-label" for="nrName">${L('የሚና ስም', 'Role name')}</label>
               <input class="form-control" id="nrName" type="text" maxlength="32" autocomplete="off"
                      autocapitalize="none" spellcheck="false" placeholder="registrar"
                      pattern="[a-z][a-z0-9_-]{1,31}" required>
               <div class="form-text">${L('ከ2–32 ፊደላት፤ ትንሽ ፊደል፣ ቁጥር፣ `_` ወይም `-` ብቻ።',
                   '2–32 characters: lowercase letters, digits, _ or -.')}</div>`,
        submit: L('ፍጠር', 'Create'),
        onSubmit: async () => {
          const name = String(val('nrName') || '').toLowerCase();
          if (!name) { fail(L('ስም ያስገቡ።', 'Enter a name.'), 'none'); return; }
          const r = await API.permissions.createRole({ name: name });
          toast(r._message || L('ሚናው ተፈጥሯል።', 'Role created.'));
          closeModal();
          P.matrix = null; P.draft = null; P.tab = 'roles'; P.role = r.role;
          refresh(true);
        }
      });
    });

    $$('[data-ptab]').forEach(b => b.addEventListener('click', () => {
      P.tab = b.dataset.ptab; refresh(true);
    }));

    $$('[data-prole]').forEach(b => b.addEventListener('click', () => {
      P.role = b.dataset.prole; P.draft = null; refresh(true);
    }));

    /* keep the draft in sync without a re-render, so scroll position holds */
    $$('.prm-cap').forEach(c => c.addEventListener('change', () => {
      if (!P.draft) return;
      if (c.checked) P.draft.caps[c.value] = true; else delete P.draft.caps[c.value];
    }));

    const save = $('#prmSave');
    save && save.addEventListener('click', async () => {
      busy(save, true);
      try {
        const caps = Object.keys((P.draft || {}).caps || {});
        const r = await API.permissions.saveRole({ role: P.role, capabilities: caps });
        toast(r._message || t('setSavedToast'));
        P.matrix = null; P.draft = null;
        refresh(true);
      } catch (e) { fail(e); busy(save, false); }
    });

    const reset = $('#prmReset');
    reset && reset.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ሚና ወደ ነባሪ መመለስ', 'Reset role'),
                               message: L('ይህ ሚና ወደ ነባሪ ፈቃዶቹ ይመለስ?', 'Reset this role to its built-in defaults?'),
                               danger: true, okLabel: L('አስመልስ', 'Reset') }))) return;
      busy(reset, true);
      try {
        const r = await API.permissions.resetRole(P.role);
        toast(r._message || t('setSavedToast'));
        P.matrix = null; P.draft = null;
        refresh(true);
      } catch (e) { fail(e); busy(reset, false); }
    });

    const sel = $('#prmUser');
    sel && sel.addEventListener('change', () => {
      P.userId = sel.value; P.userData = null; P.userDraft = null; refresh(true);
    });

    $$('.prm-ov').forEach(s => s.addEventListener('change', () => {
      const cap = s.dataset.cap;
      if (!P.userDraft) P.userDraft = {};
      if (s.value === 'inherit') delete P.userDraft[cap];
      else P.userDraft[cap] = (s.value === 'allow');
      refresh(true);
    }));

    const us = $('#prmUSave');
    us && us.addEventListener('click', async () => {
      busy(us, true);
      try {
        /* send cleared caps explicitly as null so the server drops the row */
        const payload = {};
        const stored = (P.userData && P.userData.overrides) || {};
        Object.keys(stored).forEach(c => { payload[c] = null; });
        Object.keys(P.userDraft || {}).forEach(c => { payload[c] = P.userDraft[c]; });
        const r = await API.permissions.saveUser({ id: P.userId, overrides: payload });
        toast(r._message || t('setSavedToast'));
        P.userData = null; P.userDraft = null; P.matrix = null;
        refresh(true);
      } catch (e) { fail(e); busy(us, false); }
    });

    const uc = $('#prmUClear');
    uc && uc.addEventListener('click', async () => {
      if (!(await srConfirm({ title: L('ልዩ ፈቃዶች ማጽዳት', 'Clear overrides'),
                               message: L('ሁሉም ልዩ ፈቃዶች ይጥፉ?', 'Remove every override for this user?'),
                               danger: true, okLabel: L('አጽዳ', 'Clear') }))) return;
      busy(uc, true);
      try {
        const r = await API.permissions.clearUser(P.userId);
        toast(r._message || t('setSavedToast'));
        P.userData = null; P.userDraft = null;
        refresh(true);
      } catch (e) { fail(e); busy(uc, false); }
    });
  }

  /* ================================================================== */
  /*  N · STUDENT HISTORY — one student, everything on record            */
  /* ================================================================== */
  /* This used to be a side panel bolted onto the Edit Student form, which
     meant you had to open an edit screen you did not want in order to read
     a record you did. It is now its own destination under Students, laid
     out like the Students page: a search/filter bar on top, then results.
     Nothing here writes, so it needs only students.view. */

  async function renderHistory(parts) {
    const h = A.hist;
    /* #/history/<id> deep-links straight to one student (the Edit form and
       the roster both link in this way); #/history alone shows the picker. */
    const deep = (parts && parts[1]) ? String(parts[1]) : '';
    if (deep && deep !== h.studentId) { h.studentId = deep; h.q = ''; }

    /* -- picker: dashboard-style live search ---------------------------
       Type, and the matches appear in a dropdown under the box (nothing
       re-renders under your fingers, so fuzzy queries like "a s" can be
       typed in full). Picking one opens the record. */
    const picker = card(
      L('ተማሪ ይምረጡ', 'Choose a student'),
      '',
      `<div class="row g-2 align-items-end ${h.studentId ? '' : 'mb-2'}">
         <div class="col-12 col-md-7">
           <label class="form-label" for="hsQ">${esc(t('search'))}</label>
           <div class="lsq">
             <input class="form-control" id="hsQ" type="search" value="${esc(h.q)}"
                    autocomplete="off" role="combobox" aria-expanded="false"
                    aria-controls="hsPop" aria-autocomplete="list">
             <div class="lsq-pop" id="hsPop" role="listbox" hidden></div>
           </div>
         </div>
         <div class="col-12 col-md-5">
           <label class="form-label" for="hsGrade">${esc(t('thClass'))}</label>
           <select class="form-select" id="hsGrade">${gradeOptions(h.gradeId)}</select>
         </div>
       </div>`, 'lsq-card');

    const top = pageTop(esc(L('የተማሪ ታሪክ', 'Student history')), '');

    if (!h.studentId) return top + picker;

    /* -- the record itself ---------------------------------------------- */
    const detail = await API.students.get(h.studentId, { year_ec: h.yearEc || undefined });
    const stu    = detail.student || {};
    /* students.get returns the identity row only, so the class shown in the
       banner comes from the active enrolment (falling back to the latest). */
    const enr    = detail.enrollments || [];
    const cur    = enr.find(e => e.status === 'active') || enr[0] || {};
    const curGrade = am() ? cur.grade_am : cur.grade_en;
    const sum    = (detail.attendance || {}).summary || {};
    const byMon  = (detail.attendance || {}).by_month || {};

    const photo = stu.photo_path
      ? `<img class="cor-ph" src="${esc(stu.photo_path)}" alt="">`
      : `<div class="cor-ph cor-ph-x">${esc((stu.full_name || '?').slice(0, 1))}</div>`;

    const idCard = card('', '', `
      <div class="cor-head">
        ${photo}
        <div class="cor-id">
          <h4>${esc(stu.full_name || '')}</h4>
          <p>${esc(stu.student_code || '')}${stu.baptism_name ? ' · ' + esc(stu.baptism_name) : ''}
             ${curGrade ? ' · ' + esc(curGrade) : ''}</p>
        </div>
        <button class="btn btn-ghost btn-sm ms-auto" id="hsClear" type="button">${esc(L('ሌላ ተማሪ', 'Change student'))}</button>
      </div>
      <div class="pm-chip-row">
        <span class="pill g">${esc(t('present'))} · ${num(sum.present || 0)}</span>
        <span class="pill r">${esc(t('absent'))} · ${num(sum.absent || 0)}</span>
        <span class="pill late">${esc(t('excused'))} · ${num(sum.excused || 0)}</span>
        <span class="pill b">${esc(t('pmRate'))} · ${num(Math.round(sum.rate || 0))}%</span>
        ${detail.absence_streak
          ? `<span class="pill r">${esc(L('ተከታታይ ቀሪ', 'Absence streak'))} · ${num(detail.absence_streak)}</span>` : ''}
      </div>`);

    /* enrolments — one row per academic year, the identity trail */
    const enrolls = (detail.enrollments || []).map(e => `
      <tr><td>${esc(am() ? (e.year_am || e.year_ec) : (e.year_en || e.year_ec))}</td>
          <td>${esc(am() ? e.grade_am : e.grade_en)}</td>
          <td><span class="pill ${e.status === 'active' ? 'g' : 'n'}">${esc(e.status)}</span></td></tr>`).join('');
    const enrolCard = card(L('ምዝገባ', 'Enrolment'),
      L('ማንነቱ ሳይባዛ በየዓመቱ የተመዘገበበት ክፍል።', 'One identity, one enrolment row per academic year.'),
      tblIn(`<th>${esc(t('asYear'))}</th><th>${esc(t('pmClass'))}</th><th>${esc(t('thStatus'))}</th>`,
            enrolls, L('ምንም ምዝገባ የለም።', 'No enrolment on record.')));

    /* promotions */
    const promos = (detail.promotions || []).map(p => `
      <tr><td>${num(p.from_year)} → ${num(p.to_year)}</td>
          <td>${esc(p.from_grade || '—')} → ${esc(p.to_grade || '—')}</td>
          <td><span class="pill ${actCls(p.action)}">${esc(actLbl(p.action))}</span></td></tr>`).join('');
    const promoCard = promos
      ? card(t('prHistory'), L('ከዓመት ወደ ዓመት የተደረገ ውሳኔ።', 'Year-to-year outcomes.'),
          tblIn(`<th>${esc(t('asYear'))}</th><th>${esc(t('pmClass'))}</th><th>${esc(t('thAction'))}</th>`, promos, ''))
      : '';

    /* monthly attendance shape — a plain bar row, no library */
    const months = Object.keys(byMon);
    let monthCard = '';
    if (months.length) {
      /* by_month arrives keyed 1..13 (Pagume is 13) with raw counts and no
         label, so the month name comes from the ET_MONTHS table in app.js.
         Months with nothing recorded are skipped rather than drawn empty. */
      const bars = months.map(k => {
        const m = byMon[k] || {};
        const tot = (m.present || 0) + (m.absent || 0) + (m.excused || 0);
        if (!tot) return '';
        const pc = Math.round(((m.present || 0) / tot) * 100);
        const nm = (am() ? ET_MONTHS : ET_MONTHS_EN)[Number(k) - 1] || k;
        return `<div class="hs-bar">
            <span class="hs-bar-l">${esc(nm)}</span>
            <span class="hs-bar-t"><i style="width:${pc}%"></i></span>
            <span class="hs-bar-v">${num(pc)}%</span>
          </div>`;
      }).join('');
      if (bars) monthCard = card(L('በወር የመገኘት መጠን', 'Attendance by month'),
        L('የተስተካከሉ መዝገቦችን ጨምሮ።', 'Reflects corrected records.'), `<div class="hs-bars">${bars}</div>`);
    }

    /* the day-by-day log */
    let logCard = '';
    try {
      const hist = await API.attendance.history({ student_id: h.studentId, lang: lang(),
                                                  year_ec: h.yearEc || undefined });
      const rows = (hist.history || []).slice(0, 200).map(r => `
        <tr>
          <td>${esc(r.label || r.date)}</td>
          <td>${esc(r.grade || '')}</td>
          <td><span class="pill ${r.status === 'present' ? 'g' : r.status === 'absent' ? 'r' : 'late'}">${esc(statusLabel(r.status))}</span></td>
          <td>${esc(r.reason || '')}</td>
          <td class="pm-b-sub">${esc(r.recorded_by || '')}</td>
        </tr>`).join('');
      logCard = card(L('የመገኘት መዝገብ', 'Attendance log'),
        L('የቅርብ ጊዜው መጀመሪያ። እስከ 200 ቀናት።', 'Most recent first, up to 200 sessions.'),
        tblIn(`<th>${esc(L('ቀን', 'Date'))}</th><th>${esc(t('thClass'))}</th><th>${esc(t('thStatus'))}</th>
               <th>${esc(L('ምክንያት', 'Reason'))}</th><th>${esc(L('የመዘገበው', 'Recorded by'))}</th>`,
              rows, L('ምንም መዝገብ የለም።', 'Nothing recorded yet.')));
    } catch (err) {
      logCard = card(L('የመገኘት መዝገብ', 'Attendance log'), '',
        `<p class="tbl-empty">${esc((err && err.message) || String(err))}</p>`);
    }

    return top + idCard + enrolCard + promoCard + monthCard + logCard;
  }

  function bindHistory() {
    const h = A.hist;
    lsqLive({
      input: $('#hsQ'), pop: $('#hsPop'), grade: $('#hsGrade'),
      onPick: st => {
        h.studentId = st.id; h.q = '';
        history.replaceState(null, '', '#/history/' + encodeURIComponent(st.id));
        refresh(true);
      }
    });

    const c = $('#hsClear');
    c && c.addEventListener('click', () => {
      h.studentId = '';
      /* Drop the id from the URL too, otherwise the deep-link branch in
         renderHistory would immediately put the same student back. */
      if (location.hash.replace(/^#\/?/, '').split('/').length > 1) location.hash = '#/history';
      else refresh(true);
    });
  }

  /* ================================================================== */
  /*  N · ATTENDANCE CORRECTION — the register, unlocked for one student */
  /* ================================================================== */
  /* Picking a student opens the SAME full-year grid the grade record
     uses, but nothing is greyed out and every tick/untick asks WHY. The
     answer is kept in the audit trail. Only one record per student and
     day exists — the API updates it in place. */

  async function renderCorrection() {
    const c = A.cor;

    /* Student picker: dashboard-style live search (see lsqLive). */
    const picker = card(
      L('ተማሪ ይምረጡ', 'Choose the student'),
      '',
      `<div class="row g-2 align-items-end mb-2">
         <div class="col-12 col-md-7">
           <label class="form-label" for="corQ">${esc(t('search'))}</label>
           <div class="lsq">
             <input class="form-control" id="corQ" type="search" value="${esc(c.q)}"
                    autocomplete="off" role="combobox" aria-expanded="false"
                    aria-controls="corPop" aria-autocomplete="list">
             <div class="lsq-pop" id="corPop" role="listbox" hidden></div>
           </div>
         </div>
         <div class="col-12 col-md-5">
           <label class="form-label" for="corGrade">${esc(t('thClass'))}</label>
           <select class="form-select" id="corGrade">${gradeOptions(c.gradeId)}</select>
         </div>
       </div>`, 'lsq-card');

    /* Nothing chosen yet — stop here so the page stays quiet. */
    if (!c.studentId) {
      return pageTop(esc(L('የመገኘት ማስተካከያ', 'Attendance correction')), '') +
             picker;
    }

    const grid = await window.srRenderStudentCorrection(c.studentId);
    return pageTop(esc(L('የመገኘት ማስተካከያ', 'Attendance correction')), '') + grid;
  }

  function bindCorrection() {
    const c = A.cor;

    lsqLive({
      input: $('#corQ'), pop: $('#corPop'), grade: $('#corGrade'),
      onPick: st => {
        c.studentId = st.id; c.q = ''; c.to = ''; c.last = null;
        c.date = TODAY_ISO;           /* corrections start from today */
        refresh(true);
      }
    });

    const back = $('#corBack');
    back && back.addEventListener('click', () => {
      c.studentId = ''; c.to = ''; c.last = null;
      refresh(true);
    });

    /* The grid was rendered by app.js — bind its cells here. */
    if (c.studentId && typeof window.srBindAttCells === 'function'){
      window.srBindAttCells();
      if (typeof window.srCenterTodayCol === 'function') window.srCenterTodayCol();
    }
  }

  /* ================================================================== */
  /*  Registration + binding dispatch                                    */
  /* ================================================================== */
  window.SR_VIEWS = Object.assign(window.SR_VIEWS || {}, {
    students : { cap: 'students.view',    render: p => (p[1] ? renderStudentForm(p[1]) : renderStudentsList()) },
    add      : { cap: 'students.create',  render: p => renderStudentForm(null, p[1]) },
    settings : { cap: 'settings.view',    render: renderSettings },
    import   : { cap: 'students.import',  feature: 'studentImport',   render: renderImport },
    promotion: { cap: 'promotion.manage', feature: 'studentPromotion', render: renderPromotion },
    users    : { cap: 'users.view',       render: renderUsers },
    teachers : { cap: 'teachers.view',    render: renderTeachers },
    audit    : { cap: 'audit.view',       feature: 'auditTrail',      render: renderAudit },
    backup   : { cap: 'backup.manage',    feature: 'backupRestore',   render: renderBackup },
    permissions: { cap: 'permissions.manage', render: renderPermissions },
    corrections: { cap: 'attendance.correct', render: renderCorrection },
    /* Legacy alias for the pre-2.4 singular route #/correction: bounce any
       saved bookmark or old link to the canonical plural route instead of
       showing a not-found page. */
    correction : { cap: 'attendance.correct', render: () => { location.replace('#/corrections'); return ''; } },
    history    : { cap: 'students.view',      render: renderHistory }
  });

  const BINDERS = {
    students : parts => (parts[1] ? bindStudentForm(parts) : bindStudentsList()),
    add      : parts => bindStudentForm(parts),
    settings : bindSettings,
    import   : bindImport,
    promotion: bindPromotion,
    users    : bindUsers,
    teachers : bindTeachers,
    audit    : bindAudit,
    backup   : bindBackup,
    permissions: bindPermissions,
    corrections: bindCorrection,
    history    : bindHistory
  };

  window.srBindAdminView = function (parts, el) {
    const cur = parts[0];
    /* Leaving the Correction or History page clears the picked student, so
       coming back always STARTS at the search — it never resumes a record
       from the previous visit. */
    if (cur !== A._route){
      if (A._route === 'corrections'){
        A.cor.studentId = ''; A.cor.q = ''; A.cor.to = ''; A.cor.reason = '';
        A.cor.note = ''; A.cor.last = null; A.cor.date = TODAY_ISO;
      }
      if (A._route === 'history'){ A.hist.studentId = ''; A.hist.q = ''; }
      A._route = cur;
    }
    const fn = BINDERS[parts[0]];
    if (fn) {
      try { fn(parts, el); } catch (err) { console.warn('[SR admin]', err); }
    }
    restoreFocus();
  };
})();
