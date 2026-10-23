/* ============================================================
   js/api.js — the bridge that replaces localStorage.

   Exposes window.API: a thin fetch() wrapper that attaches the
   session cookie and the CSRF token, normalises errors, and
   keeps an offline queue for attendance marks.
   ============================================================ */
(function () {
  'use strict';

  var BOOT = window.SR_BOOT || {};
  var CSRF = BOOT.csrf || '';

  /* ---------------------------------------------------------
     Offline queue (attendance only).
     Deliberately the ONLY thing we persist locally: it is a
     write-ahead log of marks that have not reached MySQL yet,
     never a mirror of the database. Each item carries a
     client_uuid so replaying it is harmless.
     --------------------------------------------------------- */
  var QUEUE_KEY = 'sr_sync_queue_v2';

  function queueRead() {
    try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }
    catch (e) { return []; }
  }
  function queueWrite(items) {
    try { localStorage.setItem(QUEUE_KEY, JSON.stringify(items)); } catch (e) {}
    emit('queue', { pending: items.length });
  }
  function queueAdd(marks) {
    var q = queueRead();
    marks.forEach(function (m) {
      if (!m.client_uuid) m.client_uuid = uuid();
      /* one entry per student+date — a re-mark replaces the pending one */
      var i = q.findIndex(function (x) {
        return x.student_id === m.student_id && x.date === m.date;
      });
      if (i >= 0) { m.client_uuid = q[i].client_uuid; q[i] = m; }
      else q.push(m);
    });
    queueWrite(q);
    return q.length;
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  /* simple event bus so the UI can show Offline / Pending / Synced */
  var listeners = {};
  function on(evt, fn) { (listeners[evt] = listeners[evt] || []).push(fn); }
  function emit(evt, data) { (listeners[evt] || []).forEach(function (fn) { try { fn(data); } catch (e) {} }); }

  /* ---------------------------------------------------------
     Core request
     --------------------------------------------------------- */
  function request(endpoint, action, params, opts) {
    opts = opts || {};
    params = params || {};
    var method = opts.method || (opts.get ? 'GET' : 'POST');
    var url = 'api/' + endpoint + '.php';

    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    };

    if (method === 'GET') {
      var qs = new URLSearchParams({ action: action });
      Object.keys(params).forEach(function (k) {
        var v = params[k];
        if (v === undefined || v === null || v === '') return;
        qs.set(k, Array.isArray(v) ? v.join(',') : v);
      });
      url += '?' + qs.toString();
    } else if (opts.formData) {
      opts.formData.set('action', action);
      init.body = opts.formData;
      init.headers['X-CSRF-Token'] = CSRF;
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = CSRF;
      init.body = JSON.stringify(Object.assign({ action: action }, params));
    }

    return fetch(url, init).then(function (res) {
      /* file downloads come back as a blob, not JSON */
      var ctype = res.headers.get('Content-Type') || '';
      if (opts.raw) {
        if (!res.ok) return res.json().then(throwApi);
        return res.blob();
      }
      if (ctype.indexOf('application/json') < 0) {
        return res.text().then(function (txt) {
          throw apiError('server', 'Unexpected response from the server.', res.status, txt.slice(0, 300));
        });
      }
      return res.json().then(function (body) {
        if (body && body.ok) {
          if (body.data && body.data.csrf_token) CSRF = body.data.csrf_token;
          return body.data === undefined ? body : Object.assign({ _message: body.message }, body.data);
        }
        return throwApi(body, res.status);
      });
    }).catch(function (err) {
      if (err && err.__api) throw err;
      /* genuine network failure — surface it as offline */
      emit('offline', {});
      throw apiError('network', 'Could not reach the server.', 0);
    });
  }

  function apiError(type, message, status, detail) {
    var e = new Error(message);
    e.__api = true; e.type = type; e.status = status || 0; e.detail = detail;
    return e;
  }
  function throwApi(body, status) {
    var err = (body && body.error) || {};
    var e = apiError(err.type || 'server', err.message || 'Something went wrong.', status);
    e.fields = err.fields || null;
    e.data = (body && body.data) || null;
    /* session gone → back to the login page */
    if (e.type === 'unauthenticated') { location.href = 'auth/login.php'; }
    throw e;
  }

  var get  = function (ep, action, params, opts) { return request(ep, action, params, Object.assign({ get: true }, opts || {})); };
  var post = function (ep, action, params, opts) { return request(ep, action, params, opts); };

  /* ---------------------------------------------------------
     Public surface, grouped per endpoint
     --------------------------------------------------------- */
  var API = {
    boot: BOOT,
    on: on,
    csrf: function () { return CSRF; },

    auth: {
      me            : function ()  { return get('auth', 'me'); },
      login         : function (p) { return post('auth', 'login', p); },
      logout        : function ()  { return post('auth', 'logout'); },
      changePassword: function (p) { return post('auth', 'change_password', p); },
      updateProfile : function (p) { return post('auth', 'update_profile', p); }
    },

    grades: {
      list   : function (p) { return get('grades', 'list', p); },
      create : function (p) { return post('grades', 'create', p); },
      update : function (p) { return post('grades', 'update', p); },
      remove : function (id){ return post('grades', 'delete', { id: id }); },
      restore: function (id){ return post('grades', 'restore', { id: id }); }
    },

    students: {
      list      : function (p) { return get('students', 'list', p); },
      get       : function (id, p){ return get('students', 'get', Object.assign({ id: id }, p || {})); },
      create    : function (p) { return post('students', 'create', p); },
      update    : function (p) { return post('students', 'update', p); },
      remove    : function (id){ return post('students', 'delete', { id: id }); },
      restore   : function (id){ return post('students', 'restore', { id: id }); },
      bulkDelete: function (ids){ return post('students', 'bulk_delete', { ids: ids }); }
    },

    attendance: {
      periods      : function (p) { return get('attendance', 'periods', p); },
      register     : function (p) { return get('attendance', 'register', p); },
      stats        : function (p) { return get('attendance', 'stats', p); },
      alerts       : function (p) { return get('attendance', 'alerts', p); },
      history      : function (p) { return get('attendance', 'history', p); },
      lookup       : function (p) { return get('attendance', 'lookup', p); },
      correct      : function (p) { return post('attendance', 'correct', p); },
      markAlertRead: function (k) {
        return post('attendance', 'mark_alert_read', { keys: Array.isArray(k) ? k : [k] });
      },
      markAll      : function (p) { return post('attendance', 'mark_all', p); },
      copyLastWeek : function (p) { return post('attendance', 'copy_last_week', p); },

      /* Save marks. When the browser is offline the marks go to the
         queue instead and are flushed on reconnect. */
      save: function (p) {
        if (!navigator.onLine) {
          var n = queueAdd((p.marks || []).map(function (m) {
            return Object.assign({ grade_id: p.grade_id }, m);
          }));
          emit('queued', { pending: n });
          if (API.requestSync) API.requestSync();
          return Promise.resolve({ queued: true, pending: n, saved: 0, _message: 'Saved on this device. Will sync when back online.' });
        }
        return post('attendance', 'save', p).catch(function (err) {
          if (err.type === 'network') {
            var n = queueAdd((p.marks || []).map(function (m) {
              return Object.assign({ grade_id: p.grade_id }, m);
            }));
            emit('queued', { pending: n });
            if (API.requestSync) API.requestSync();
            return { queued: true, pending: n, saved: 0, _message: 'Connection lost — kept on this device.' };
          }
          throw err;
        });
      },

      pending: function () { return queueRead().length; },

      /* Push the queue to MySQL. Safe to call repeatedly. */
      flush: function () {
        var q = queueRead();
        if (!q.length) return Promise.resolve({ synced: 0, failed: 0, pending: 0 });
        if (!navigator.onLine) return Promise.resolve({ synced: 0, failed: 0, pending: q.length, offline: true });
        emit('syncing', { pending: q.length });
        return post('attendance', 'sync', { queue: q }).then(function (res) {
          var failed = {};
          (res.results || []).forEach(function (r) {
            if (r.state === 'failed') failed[r.client_uuid] = r.message || 'failed';
          });
          var left = q.filter(function (item) { return failed[item.client_uuid]; });
          queueWrite(left);
          emit('synced', { synced: res.synced || 0, failed: left.length });
          return { synced: res.synced || 0, failed: left.length, pending: left.length, errors: failed };
        }).catch(function (err) {
          emit('syncfail', { error: err.message, pending: q.length });
          throw err;
        });
      }
    },

    users: {
      list         : function (p) { return get('users', 'list', p); },
      get          : function (id){ return get('users', 'get', { id: id }); },
      create       : function (p) { return post('users', 'create', p); },
      update       : function (p) { return post('users', 'update', p); },
      resetPassword: function (p) { return post('users', 'reset_password', p); },

      /* Master recovery keys (v2.4 item 15). recoveryKeyCreate returns the
         plaintext key exactly once — the server keeps only a hash. */
      recoveryKeys      : function ()  { return get('users', 'recovery_keys'); },
      recoveryKeyCreate : function (p) { return post('users', 'recovery_key_create', p); },
      recoveryKeyRevoke : function (p) { return post('users', 'recovery_key_revoke', p); },
      remove       : function (id){ return post('users', 'delete', { id: id }); },
      restore      : function (id){ return post('users', 'restore', { id: id }); }
    },

    permissions: {
      matrix      : function ()   { return get('permissions', 'matrix'); },
      createRole  : function (p)  { return post('permissions', 'create_role', p); },
      saveRole    : function (p)  { return post('permissions', 'save_role', p); },
      resetRole   : function (r)  { return post('permissions', 'reset_role', { role: r }); },
      user      : function (id) { return get('permissions', 'user', { id: id }); },
      saveUser  : function (p)  { return post('permissions', 'save_user', p); },
      clearUser : function (id) { return post('permissions', 'clear_user', { id: id }); }
    },

    teachers: {
      list       : function (p) { return get('teachers', 'list', p); },
      assignments: function (p) { return get('teachers', 'assignments', p); },
      myClasses  : function (p) { return get('teachers', 'my_classes', p); },
      assign     : function (p) { return post('teachers', 'assign', p); },
      unassign   : function (p) { return post('teachers', 'unassign', p); }
    },

    settings: {
      get           : function ()  { return get('settings', 'get'); },
      save          : function (p) { return post('settings', 'save', p); },
      years         : function ()  { return get('settings', 'years'); },
      addYear       : function (p) { return post('settings', 'add_year', p); },
      setCurrentYear: function (p) { return post('settings', 'set_current_year', p); }
    },

    promotion: {
      preview: function (p) { return post('promotion', 'preview', p); },
      run    : function (p) { return post('promotion', 'run', p); },
      history: function (p) { return get('promotion', 'history', p); },
      undo   : function (p) { return post('promotion', 'undo', p); }
    },

    importer: {
      upload : function (file, extra) {
        var fd = new FormData();
        fd.append('file', file);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return post('import', 'upload', {}, { formData: fd });
      },
      confirm: function (p) { return post('import', 'confirm', p); },
      batches: function ()  { return get('import', 'batches'); }
    },

    audit: {
      list   : function (p) { return get('audit', 'list', p); },
      filters: function ()  { return get('audit', 'filters'); },
      purge  : function (p) { return post('audit', 'purge', p); }
    },

    backup: {
      list  : function ()  { return get('backup', 'list'); },
      create: function (p) { return post('backup', 'create', p); },
      remove: function (f) { return post('backup', 'delete', { file: f }); },
      restore: function (p) { return post('backup', 'restore', p); },
      restoreFile: function (file, confirm) {
        var fd = new FormData();
        fd.append('file', file);
        if (confirm) fd.append('confirm', 'true');
        return post('backup', 'restore', {}, { formData: fd });
      }
    },

    /* Downloads: build a URL the browser can navigate to directly. */
    exportUrl: function (action, params) {
      var qs = new URLSearchParams({ action: action });
      Object.keys(params || {}).forEach(function (k) {
        var v = params[k];
        if (v === undefined || v === null || v === '') return;
        qs.set(k, Array.isArray(v) ? v.join(',') : v);
      });
      return 'api/export.php?' + qs.toString();
    },
    download: function (action, params) {
      var url = API.exportUrl(action, params);
      var a = document.createElement('a');
      a.href = url; a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
    },
    openPrint: function (action, params) {
      window.open(API.exportUrl(action, params), '_blank', 'noopener');
    }
  };

  /* auto-flush the queue when the connection returns */
  window.addEventListener('online', function () {
    emit('online', {});
    if (API.attendance.pending()) API.attendance.flush().catch(function () {});
  });
  window.addEventListener('offline', function () { emit('offline', {}); });

  /* The service worker asks us to flush after a Background Sync wakeup. */
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (ev) {
      var d = ev.data || {};
      if (d.type === 'SR_SYNC_NOW' && API.attendance.pending()) {
        API.attendance.flush().catch(function () {});
      }
    });
  }

  /* Ask the browser to wake the SW when connectivity returns, so a queued
     register still reaches MySQL even if the tab is closed. */
  API.requestSync = function () {
    if (!('serviceWorker' in navigator) || !window.SyncManager) return Promise.resolve(false);
    return navigator.serviceWorker.ready
      .then(function (reg) { return reg.sync.register('sr-attendance-sync'); })
      .then(function () { return true; })
      .catch(function () { return false; });
  };

  window.API = API;
})();
