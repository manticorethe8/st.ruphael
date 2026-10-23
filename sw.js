/* ============================================================
   sw.js — St. Rufael attendance platform service worker.

   Scope of responsibility, deliberately narrow:

     * OFFLINE CACHE  — static app shell only (HTML shell, CSS,
       fonts, JS, icons). Versioned; replaced wholesale on
       deploy. It never contains student, attendance or user
       data.

     * MYSQL SYNC     — NOT handled here. Every /api/*.php call
       goes straight to the network. Attendance marks taken
       while offline are queued in js/api.js (localStorage key
       sr_sync_queue_v2) as a write-ahead log and replayed
       through api/attendance.php?action=sync when the
       connection returns. The service worker only tells the
       page when it is back online.

   Keeping the two apart is the whole point: nothing the worker
   caches is ever treated as the truth about the database.
   ============================================================ */
'use strict';

/* Bump this on EVERY deploy. The activate handler deletes any cache whose
   name does not match, so changing it is what forces returning browsers to
   drop the old shell and pick up new HTML/CSS/JS. Forgetting to bump it is
   the classic "my changes aren't showing up" bug. Keep it in step with
   APP_VERSION in config/config.php. */
const VERSION     = 'sr-v2.5.0';
const SHELL_CACHE = VERSION + '-shell';
const RUNTIME     = VERSION + '-runtime';

/* Files that make the app usable with no network. Relative URLs
   so the app works from any sub-directory under htdocs/. */
const SHELL = [
  './',
  'index.php',
  'offline.html',
  'manifest.json',
  'css/styles.min.css',
  'css/fonts.min.css',
  'vendor/bootstrap.min.css',
  'js/features.min.js',
  'js/api.min.js',
  'js/app.min.js',
  'js/admin.min.js',
  'assets/logo.png',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/maskable-192.png'
];

/* Fonts are big; fetch them lazily so install never fails on a
   slow first load. */
const OPTIONAL = [
  'assets/fonts/Inter-latin-400.woff2',
  'assets/fonts/Inter-latin-500.woff2',
  'assets/fonts/Inter-latin-600.woff2',
  'assets/fonts/Inter-latin-700.woff2',
  'assets/fonts/NotoSansEthiopic-ethiopic-400.woff2',
  'assets/fonts/NotoSansEthiopic-ethiopic-500.woff2',
  'assets/fonts/NotoSansEthiopic-ethiopic-600.woff2',
  'assets/fonts/NotoSansEthiopic-ethiopic-700.woff2'
];

/* ---------------------------------------------------------------- */
self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    /* addAll() is all-or-nothing; add one by one so a single
       missing optional file cannot break the whole install. */
    await Promise.all(SHELL.map(url =>
      cache.add(new Request(url, { cache: 'reload' })).catch(() => {})
    ));
    OPTIONAL.forEach(url => cache.add(url).catch(() => {}));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys.filter(k => k !== SHELL_CACHE && k !== RUNTIME).map(k => caches.delete(k))
    );
    if (self.registration.navigationPreload) {
      await self.registration.navigationPreload.enable().catch(() => {});
    }
    await self.clients.claim();
  })());
});

/* ---------------------------------------------------------------- */
function isApi(url) {
  return url.pathname.includes('/api/') && url.pathname.endsWith('.php');
}
function isAuthPage(url) {
  return /\/(login|logout|new-password|install)\.php$/.test(url.pathname);
}
function isStatic(url) {
  return /\.(css|js|woff2?|ttf|png|jpe?g|svg|webp|ico|json)$/i.test(url.pathname);
}

self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  /* Only same-origin GETs are ours to manage. Everything else —
     POSTs, CDN requests, exports — goes straight through. */
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  /* --- API: network only. Never cached, never served stale. --- */
  if (isApi(url)) {
    event.respondWith(
      fetch(req).catch(() => new Response(
        JSON.stringify({
          ok: false,
          error: {
            type: 'offline',
            message: 'You are offline. Attendance marks are queued and will sync automatically.'
          }
        }),
        { status: 503, headers: { 'Content-Type': 'application/json' } }
      ))
    );
    return;
  }

  /* --- Auth pages: always live, so a stale session page is
         never shown after a sign-out. --- */
  if (isAuthPage(url)) return;

  /* --- Navigations: network first, shell as the fallback. --- */
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const preload = await event.preloadResponse;
        if (preload) return preload;
        return await fetch(req);
      } catch (e) {
        const cache = await caches.open(SHELL_CACHE);
        return (await cache.match('index.php'))
            || (await cache.match('offline.html'))
            || new Response('Offline', { status: 503 });
      }
    })());
    return;
  }

  /* --- Static assets: cache first, refresh in the background. --- */
  if (isStatic(url)) {
    event.respondWith((async () => {
      const cache  = await caches.open(SHELL_CACHE);
      const hit    = await cache.match(req, { ignoreSearch: true });
      const update = fetch(req).then(res => {
        if (res && res.ok && res.type === 'basic') cache.put(req, res.clone());
        return res;
      }).catch(() => null);
      return hit || (await update) || new Response('', { status: 504 });
    })());
    return;
  }

  /* Anything else: try the network, fall back to whatever we hold. */
  event.respondWith(
    fetch(req).catch(async () => (await caches.match(req)) || new Response('', { status: 504 }))
  );
});

/* ---------------------------------------------------------------- */
/* The page asks for an immediate activation after an update, and
   asks to be told when the network is usable again so js/api.js
   can drain the attendance queue into MySQL. */
self.addEventListener('message', event => {
  const msg = event.data || {};
  if (msg.type === 'SKIP_WAITING') self.skipWaiting();
  if (msg.type === 'PING') {
    event.source && event.source.postMessage({ type: 'PONG', version: VERSION });
  }
});

/* Background Sync, when the browser supports it: a nudge to the
   page, which owns the queue and the CSRF token. The worker
   never writes to MySQL by itself. */
self.addEventListener('sync', event => {
  if (event.tag !== 'sr-attendance-sync') return;
  event.waitUntil((async () => {
    const clientList = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    clientList.forEach(c => c.postMessage({ type: 'SR_SYNC_NOW' }));
  })());
});
