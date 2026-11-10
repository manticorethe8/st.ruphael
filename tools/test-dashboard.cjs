#!/usr/bin/env node
/* Headless dashboard smoke test. No browser, screenshots, database or packages needed. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function render(file, lang, stats, alertsEnabled) {
  const bell = { textContent: '', classList: { toggle() {} } };
  const document = {
    readyState: 'loading', addEventListener() {},
    querySelector(selector) { return selector === '#bellBadge' ? bell : null; },
    querySelectorAll() { return []; }, getElementById() { return null; }
  };
  const store = new Map();
  const localStorage = {
    getItem(key) { return store.get(key) ?? null; },
    setItem(key, value) { store.set(key, value); }
  };
  const window = {
    SR_BOOT: { lang, user: { full_name: 'Test Teacher' }, year: { year_ec: 2019 }, caps: {} },
    FEATURES: { attendanceAlerts: alertsEnabled }
  };
  const API = { attendance: {
    stats: async () => stats,
    alerts: async () => ({ alerts: [], unread: 0 })
  }};
  const context = vm.createContext({ window, document, localStorage, API, Date, Math, Intl, console });
  vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: file });
  return vm.runInContext('renderDashboard()', context);
}

async function main() {
  const blank = { rate: 0, students: 0, marked: 0, present: 0, absent: 0, excused: 0, by_month: [], by_day: {} };
  const recorded = { rate: 83, students: 120, marked: 100, present: 83, absent: 12, excused: 5,
    by_month: [{name_am:'መስከረም',name_en:'Meskerem',total:50,rate:80}, {name_am:'ጥቅምት',name_en:'Tikimt',total:50,rate:86}],
    by_day: { sat: { present: 40, absent: 10 }, sun: { present: 43, absent: 2 } } };
  // The offline shell must contain only static files, never authenticated HTML.
  const worker = fs.readFileSync('sw.js', 'utf8');
  const shell = worker.match(/const SHELL = \[([\s\S]*?)\];/);
  assert.ok(shell, 'service worker shell list');
  for (const item of shell[1].matchAll(/'([^']+)'/g)) {
    assert.ok(fs.existsSync(item[1]), `Missing offline shell asset: ${item[1]}`);
    assert.ok(!item[1].endsWith('.php'), `Authenticated HTML in offline shell: ${item[1]}`);
  }
  assert.ok(!worker.includes("cache.match('index.php')"), 'Do not serve cached private HTML');
  console.log('PASS offline shell assets');
  for (const file of ['js/app.js', 'js/app.min.js']) {
    for (const [lang, stats, alerts] of [['en', blank, false], ['am', recorded, true]]) {
      const html = await render(file, lang, stats, alerts);
      for (const cls of ['dashboard-modern', 'overview-ring', 'overview-stat', 'dash-classes', 'dash-intro', 'search-hero']) {
        const classes = [...html.matchAll(/class="([^"]*)"/g)].flatMap(match => match[1].split(/\s+/));
        assert.equal(classes.filter(name => name === cls).length, 1, `${cls} should occur once`);
      }
      assert.doesNotMatch(html, /class="[^"]*(?:dash-kpi|dn-wrap|dash-summary-year)\b/);
      if (stats.marked === 0) {
        assert.match(html, /Attendance Rate: No data/);
        assert.doesNotMatch(html, /Attendance Rate: 0%/);
        assert.match(html, /No trends to show yet/);
      } else {
        assert.match(html, /style="--progress:83%"/);
        assert.doesNotMatch(html, /dash-chart-empty/);
        assert.match(html, /aria-label="[^"]*መስከረም[^"]*ጥቅምት/);
      }
      assert.equal(html.includes('class="dash-alerts"'), alerts);
      console.log('PASS', file, lang, stats.marked ? 'recorded' : 'empty');
    }
  }
}
main().catch(err => { console.error(err); process.exitCode = 1; });
