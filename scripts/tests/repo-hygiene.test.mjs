import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { alarmFindings, decideStale, failStreak, queuedSince, STALE_DAYS, CLOSE_DRAFT_DAYS, EXEMPT_LABELS } from '../../.github/scripts/repo-hygiene.mjs';

const NOW = Date.parse('2026-10-21T00:00:00Z');
const ago = (d) => new Date(NOW - d * 86400e3).toISOString();
const pr = (o = {}) => ({ state: 'open', user: { login: 'o', type: 'User' }, base: { ref: 'main' }, labels: [], draft: false, ...o });

test('stale: labels at the threshold, not before; boundaries follow the constants', () => {
  assert.equal(decideStale(pr(), ago(STALE_DAYS - 1), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr(), ago(STALE_DAYS), NOW, 'o').action, 'label');
  assert.equal(decideStale(pr({ labels: [{ name: 'stale' }] }), ago(STALE_DAYS + 1), NOW, 'o').action, 'none');
});

test('stale: original close decision requires explicit opt-in; non-draft never closes', () => {
  assert.equal(decideStale(pr({ draft: true }), ago(CLOSE_DRAFT_DAYS), NOW, 'o', { closeDrafts: true }).action, 'close');
  assert.equal(decideStale(pr({ draft: true }), ago(CLOSE_DRAFT_DAYS - 1), NOW, 'o', { closeDrafts: true }).action, 'label');
  for (const d of [30, 365]) assert.notEqual(decideStale(pr(), ago(d), NOW, 'o', { closeDrafts: true }).action, 'close');
  for (const closeDrafts of [undefined, false, 'true']) assert.equal(decideStale(pr({ draft: true }), ago(CLOSE_DRAFT_DAYS), NOW, 'o', { closeDrafts }).action, 'label');
});

test('stale: other authors, bots, non-main base and exempt labels are untouched', () => {
  assert.equal(decideStale(pr({ user: { login: 'human', type: 'User' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr({ user: { login: 'o', type: 'Bot' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr({ base: { ref: 'dev' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  for (const l of EXEMPT_LABELS) assert.equal(decideStale(pr({ labels: [{ name: l }], draft: true }), ago(99), NOW, 'o').action, 'none', l);
  for (const l of EXEMPT_LABELS) assert.equal(decideStale(pr({ labels: [{ name: l }], draft: true }), ago(99), NOW, 'o', { closeDrafts: true }).action, 'none', l);
});

test('stale: activity resuming clears the label', () => {
  assert.equal(decideStale(pr({ labels: [{ name: 'stale' }] }), ago(1), NOW, 'o').action, 'unlabel');
});

test('queuedSince: last labeled wins, unlabeled clears', () => {
  const ev = (event, at, name = 'queue') => ({ event, label: { name }, created_at: at });
  assert.equal(queuedSince([ev('labeled', 'a'), ev('unlabeled', 'b'), ev('labeled', 'c')]), 'c');
  assert.equal(queuedSince([ev('labeled', 'a'), ev('unlabeled', 'b')]), null);
  assert.equal(queuedSince([ev('labeled', 'a', 'other')]), null);
});

test('failStreak: skips cancelled, stops at success', () => {
  const r = (conclusion, status = 'completed') => ({ conclusion, status });
  assert.equal(failStreak([r('failure'), r('cancelled'), r('failure'), r('failure'), r('success'), r('failure')]), 3);
  assert.equal(failStreak([r('success'), r('failure')]), 0);
  assert.equal(failStreak([r(null, 'in_progress'), r('failure')]), 1);
});

test('alarmFindings: >2h queue and 3 failures; thresholds are parameters', () => {
  const runs = [{ status: 'completed', conclusion: 'failure' }, { status: 'completed', conclusion: 'failure' }, { status: 'completed', conclusion: 'failure' }];
  assert.equal(alarmFindings({ queued: [{ number: 1, since: ago(0.05) }], runs: [], now: NOW }).length, 0);
  const f = alarmFindings({ queued: [{ number: 7, since: new Date(NOW - 3 * 3600e3).toISOString() }], runs, now: NOW });
  assert.equal(f.length, 2);
  assert.match(f[0], /#7/);
  assert.equal(alarmFindings({ queued: [], runs, now: NOW, streak: 4 }).length, 0);
  assert.equal(alarmFindings({ queued: [{ number: 7, since: new Date(NOW - 3 * 3600e3).toISOString() }], runs: [], now: NOW, hours: 4 }).length, 0);
});

test('stale: unknown activity or eligibility metadata cannot mutate a PR', () => {
  for (const activity of [undefined, null, '', 'invalid', '9999-01-01T00:00:00Z']) {
    assert.equal(decideStale(pr({ draft: true, labels: [{ name: 'stale' }] }), activity, NOW, 'o').action, 'none');
  }
  for (const fields of [{ labels: undefined }, { labels: [null] }, { draft: 'true' }, { draft: undefined }, { state: undefined }, { base: null }, { user: { login: 'o' } }]) {
    assert.equal(decideStale(pr({ draft: true, ...fields }), ago(99), NOW, 'o').action, 'none');
  }
});

const HEAD = 'a'.repeat(40);
const old = () => new Date(Date.now() - 60 * 86400e3).toISOString();
const recent = () => new Date(Date.now() - 86400e3).toISOString();
const apiPr = (fields = {}) => pr({ number: 1, draft: true, created_at: old(), head: { sha: HEAD }, ...fields });
const routesFor = (pull = apiPr()) => ({
  'pulls?state=open&per_page=100': { data: [pull] },
  [`commits/${HEAD}`]: { data: { sha: HEAD, commit: { committer: { date: old() } } } },
  'issues/1/comments?per_page=100': { data: [] },
  'pulls/1/comments?per_page=100': { data: [] },
  'pulls/1/reviews?per_page=100': { data: [] },
  'issues/1/events?per_page=100': { data: [] },
});

// No real gh or git executable is on the child's PATH. Every GitHub response is
// local, every request is recorded, and an unexpected endpoint fails the test.
function fixture(t, routes, labels = [{ name: 'stale' }, { name: 'ops-hygiene' }]) {
  const dir = mkdtempSync(join(tmpdir(), 'repo-hygiene-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  const stateFile = join(dir, 'state.json');
  writeFileSync(stateFile, JSON.stringify({ routes, labels, issues: [], calls: [] }));
  writeFileSync(join(dir, 'gh'), String.raw`#!${process.execPath}
const fs = require('node:fs');
const file = process.env.GH_STUB_STATE;
const s = JSON.parse(fs.readFileSync(file, 'utf8'));
const args = process.argv.slice(2);
const method = args.includes('-X') ? args[args.indexOf('-X') + 1] : 'GET';
const path = args.find(a => a.startsWith('/repos/')).replace('/repos/o/r/', '');
const input = args.includes('--input') ? JSON.parse(fs.readFileSync(0, 'utf8') || 'null') : null;
s.calls.push({ method, path, input, args });
let r = s.routes[method + ' ' + path] || (method === 'GET' ? s.routes[path] : null);
if (!r && path === 'labels' && method === 'POST') { s.labels.push(input); r = { data: input }; }
if (!r && path === 'labels?per_page=100') r = { data: s.labels };
if (!r && path.startsWith('issues?state=open&')) r = { data: s.issues };
if (!r && method === 'POST' && path === 'issues') {
  const issue = { ...input, number: 9000 + s.issues.length }; s.issues.push(issue); r = { data: issue };
}
if (!r && method === 'PATCH' && path.startsWith('issues/900')) {
  const issue = s.issues.find(i => path === 'issues/' + i.number); Object.assign(issue, input); r = { data: issue };
}
if (!r && method !== 'GET' && /^(issues|pulls)\/1(\/|$)/.test(path)) r = { data: {} };
fs.writeFileSync(file, JSON.stringify(s));
if (!r || r.status) { process.stderr.write('stub failure: ' + method + ' ' + path); process.exit(1); }
process.stdout.write(JSON.stringify(r.pages ?? (args.includes('--paginate') ? [r.data] : r.data)));
`, { mode: 0o755 });
  return {
    run: (mode = 'stale') => spawnSync(process.execPath, ['.github/scripts/repo-hygiene.mjs', mode], {
      cwd: new URL('../..', import.meta.url), encoding: 'utf8',
      env: { PATH: dir, GITHUB_REPOSITORY: 'o/r', GH_TOKEN: 'stub-only', GH_STUB_STATE: stateFile },
    }),
    state: () => JSON.parse(readFileSync(stateFile, 'utf8')),
  };
}
const prWrites = (f) => f.state().calls.filter((c) => c.method !== 'GET' && /^(issues|pulls)\/1(\/|$)/.test(c.path));

test('stale: 101-commit active draft reads the true head, never the first page tail', (t) => {
  const routes = routesFor(apiPr({ commits: 101 }));
  routes['pulls/1/commits?per_page=100'] = { pages: [Array(100).fill({ commit: { committer: { date: old() } } }), [{ sha: HEAD, commit: { committer: { date: recent() } } }]] };
  routes[`commits/${HEAD}`].data.commit.committer.date = recent();
  const f = fixture(t, routes);
  const result = f.run();
  assert.equal(result.status, 0, result.stderr);
  assert.equal(prWrites(f).length, 0);
  assert.ok(f.state().calls.some((c) => c.path === `commits/${HEAD}`));
  assert.ok(!f.state().calls.some((c) => c.path.startsWith('pulls/1/commits')));
});

test('stale: a recent reopen on a later event page clears stale instead of closing', (t) => {
  const routes = routesFor(apiPr({ labels: [{ name: 'stale' }] }));
  routes['issues/1/events?per_page=100'] = { pages: [Array(100).fill({ event: 'closed', created_at: old() }), [{ event: 'reopened', created_at: recent() }]] };
  const f = fixture(t, routes);
  const result = f.run();
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(prWrites(f).map((c) => [c.method, c.path]), [['DELETE', 'issues/1/labels/stale']]);
  assert.match(result.stdout, /drafts_reported=0/);
  const call = f.state().calls.find((c) => c.path === 'issues/1/events?per_page=100');
  assert.ok(call.args.includes('--paginate') && call.args.includes('--slurp'));
});

test('stale: default sweep labels and reports old drafts without any PR-close request', (t) => {
  for (const draft of [false, true]) {
    const routes = routesFor(apiPr({ draft }));
    routes['issues/1/comments?per_page=100'].data.push({ body: '<!-- repo-hygiene:stale --> closing', created_at: recent() });
    const f = fixture(t, routes);
    const result = f.run();
    assert.equal(result.status, 0, result.stderr);
    assert.ok(prWrites(f).some((c) => c.path === 'issues/1/labels'));
    assert.ok(!prWrites(f).some((c) => c.input?.state === 'closed'));
    assert.ok(!prWrites(f).some((c) => c.method === 'PATCH'));
    assert.match(f.state().issues[0].body, /Draft auto-close: deferred/);
    if (draft) {
      assert.match(result.stdout, /drafts_reported=1/);
      assert.match(f.state().issues[0].body, /explicit owner disposition\/approval required\): #1/);
    }
  }
});

test('labels: provision missing definitions before stale labels and reuse one hourly alarm', (t) => {
  const stale = fixture(t, routesFor(apiPr({ draft: false })), []);
  const result = stale.run();
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(stale.state().labels.map((l) => l.name).sort(), ['ops-hygiene', 'stale']);
  const writes = stale.state().calls.filter((c) => c.method !== 'GET');
  assert.deepEqual(writes.slice(0, 2).map((c) => c.path), ['labels', 'labels']);
  assert.ok(writes.some((c) => c.path === 'issues/1/labels'));
  const alarm = fixture(t, {
    'pulls?state=open&per_page=100': { data: [] },
    'actions/workflows/land-queue.yml/runs?per_page=30': { data: { workflow_runs: Array(3).fill({ status: 'completed', conclusion: 'failure' }) } },
  }, []);
  for (let n = 0; n < 2; n++) { const r = alarm.run('queue-alarm'); assert.equal(r.status, 0, r.stderr); }
  assert.equal(alarm.state().issues.length, 1);
  assert.equal(alarm.state().calls.filter((c) => c.method === 'POST' && c.path === 'labels').length, 2);
  assert.equal(alarm.state().calls.filter((c) => c.method === 'POST' && c.path === 'issues').length, 1);
});

test('manual hygiene: the serialized report and alarm reuse newly created labels', (t) => {
  const f = fixture(t, {
    'pulls?state=open&per_page=100': { data: [] },
    'actions/workflows/land-queue.yml/runs?per_page=30': { data: { workflow_runs: Array(3).fill({ status: 'completed', conclusion: 'failure' }) } },
  }, []);
  for (const mode of ['stale', 'queue-alarm']) {
    const r = f.run(mode); assert.equal(r.status, 0, r.stderr);
  }
  assert.equal(f.state().calls.filter((c) => c.method === 'POST' && c.path === 'labels').length, 2);
  assert.equal(f.state().issues.length, 2, 'weekly report and queue alarm both publish');
  assert.ok(f.state().issues.some((issue) => issue.title === '[ops] Land queue alarm'));
});

test('queue alarm: unknown age or eligibility cannot close or update an existing alarm', (t) => {
  const event = { event: 'labeled', label: { name: 'queue' }, created_at: old() };
  const pull = { number: 1, labels: [{ name: 'queue' }] };
  const base = {
    'pulls?state=open&per_page=100': { data: [pull] },
    'issues/1/events?per_page=100': { data: [event] },
    'actions/workflows/land-queue.yml/runs?per_page=30': { data: { workflow_runs: [] } },
    'issues?state=open&per_page=100&labels=ops-hygiene': { data: [{ number: 9000, body: '<!-- repo-hygiene:queue-alarm -->' }] },
    'POST issues/9000/comments': { data: {} }, 'PATCH issues/9000': { data: { state: 'closed' } },
  };
  const bad = [
    ...[undefined, '', 'invalid-timestamp', '2026-02-30T05:00:00Z', '9999-01-01T00:00:00Z'].map((created_at) => ({ 'issues/1/events?per_page=100': { data: [{ ...event, created_at }] } })),
    ...[[], [{ ...event, event: undefined }], [{ ...event, label: {} }], [{ ...event, event: 'unlabeled' }], [{ ...event, created_at: recent() }, event]].map((data) => ({ 'issues/1/events?per_page=100': { data } })),
    ...[{ number: 0 }, { labels: undefined }, { labels: [{}] }].map((fields) => ({ 'pulls?state=open&per_page=100': { data: [{ ...pull, ...fields }] } })),
    ...[undefined, null, [{}], [{ status: 'completed', conclusion: null }]].map((workflow_runs) => ({ 'actions/workflows/land-queue.yml/runs?per_page=30': { data: { workflow_runs } } })),
  ];
  for (const override of bad) {
    const f = fixture(t, { ...base, ...override }); const r = f.run('queue-alarm');
    assert.notEqual(r.status, 0, JSON.stringify(override));
    assert.equal(f.state().calls.filter((c) => c.method !== 'GET').length, 0, 'unknown is never tracker recovery or an upsert');
    assert.doesNotMatch(r.stdout, /queue-alarm: clear/);
  }
  const healthy = fixture(t, { ...base, 'issues/1/events?per_page=100': { data: [{ ...event, created_at: new Date(Date.now() - 30 * 60e3).toISOString() }] } });
  const r = healthy.run('queue-alarm'); assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(healthy.state().calls.filter((c) => c.method !== 'GET').map((c) => c.path), ['issues/9000/comments', 'issues/9000']);
});

test('fail closed: failed or malformed activity/labels never close an old draft', (t) => {
  const failures = [
    { 'pulls?state=open&per_page=100': { data: [apiPr({ head: {} })] } },
    { [`commits/${HEAD}`]: { status: 1 } },
    { [`commits/${HEAD}`]: { data: { sha: 'b'.repeat(40) } } },
    { [`commits/${HEAD}`]: { data: { sha: HEAD, commit: { committer: {} } } } },
    { 'issues/1/events?per_page=100': { status: 1 } },
    { 'issues/1/events?per_page=100': { data: [{}] } },
    { 'issues/1/events?per_page=100': { data: [{ event: 'reopened', created_at: 'invalid' }] } },
    { 'issues/1/comments?per_page=100': { data: [{}] } },
    { 'pulls/1/comments?per_page=100': { pages: [null] } },
    { 'pulls/1/reviews?per_page=100': { data: [{ submitted_at: null }] } },
    { 'POST labels': { status: 1 }, 'labels?per_page=100': { data: [] } },
    { 'POST labels': { data: {} }, 'labels?per_page=100': { data: [] } },
    { 'labels?per_page=100': { status: 1 } },
  ];
  for (const overrides of failures) {
    const f = fixture(t, { ...routesFor(), ...overrides });
    const result = f.run();
    assert.notEqual(result.status, 0, JSON.stringify(overrides));
    assert.match(result.stderr, /stub failure|Unknown|Incomplete|Label was not created/);
    assert.ok(f.state().calls.length > 0);
    assert.equal(prWrites(f).length, 0, JSON.stringify(overrides));
  }
});
