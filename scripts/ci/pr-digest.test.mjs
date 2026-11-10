import assert from 'node:assert/strict';
import test from 'node:test';
import { summarizePr, formatRow, markersOn, toRollup, run, redact, parseArgs } from './pr-digest.mjs';

const HEAD = 'a'.repeat(40);
const OLD = 'b'.repeat(40);
const OWNER = 'octo';
const pr = (over = {}) => ({ number: 7, draft: false, mergeable_state: 'clean', head: { sha: HEAD }, labels: [{ name: 'queue' }, { name: 'bug' }], ...over });
const cr = (name, conclusion, started = '2026-11-10T01:00:00Z', status = 'completed') => ({ name, conclusion, status, started_at: started });
const owner = (body) => ({ user: { login: OWNER }, body, created_at: '2026-11-10T00:00:00Z' });
const base = (over = {}) => ({ pr: pr(), checkRuns: [], statuses: [], comments: [], behindBy: 0, threads: null, required: ['A', 'B'], owner: OWNER, ...over });

test('duplicate check names keep the newest run', () => {
  const r = summarizePr(base({ checkRuns: [cr('A', 'failure', '2026-11-10T01:00:00Z'), cr('A', 'success', '2026-11-10T02:00:00Z'), cr('B', 'success')] }));
  assert.deepEqual(r.req, { ok: 2, total: 2, fail: [], pending: [], missing: [] });
  const r2 = summarizePr(base({ checkRuns: [cr('A', 'success', '2026-11-10T01:00:00Z'), cr('A', 'failure', '2026-11-10T02:00:00Z'), cr('B', 'success')] }));
  assert.deepEqual(r2.req.fail, ['A']);
});

test('skipped and neutral count as ok', () => {
  const r = summarizePr(base({ checkRuns: [cr('A', 'skipped'), cr('B', 'neutral')] }));
  assert.equal(r.req.ok, 2);
  assert.equal(r.req.fail.length + r.req.pending.length + r.req.missing.length, 0);
});

test('cancelled, timed_out and failure are failing', () => {
  const r = summarizePr(base({ required: ['A', 'B', 'C'], checkRuns: [cr('A', 'cancelled'), cr('B', 'timed_out'), cr('C', 'failure')] }));
  assert.deepEqual(r.req.fail, ['A', 'B', 'C']);
  assert.equal(r.req.ok, 0);
});

test('in-progress is pending, absent required check is missing and never ok', () => {
  const r = summarizePr(base({ checkRuns: [cr('A', null, '2026-11-10T01:00:00Z', 'in_progress')] }));
  assert.deepEqual(r.req.pending, ['A']);
  assert.deepEqual(r.req.missing, ['B']);
  assert.equal(r.req.ok, 0);
  assert.match(formatRow(r), /req:0\/2 pend:A miss:B/);
});

test('commit statuses satisfy a required context; advisory failure is listed apart', () => {
  const r = summarizePr(base({ required: ['A'], statuses: [{ context: 'A', state: 'success', created_at: 'x' }], checkRuns: [cr('lint', 'failure')] }));
  assert.equal(r.req.ok, 1);
  assert.deepEqual(r.advisoryFail, ['lint']);
  assert.deepEqual(toRollup([], [{ context: 'A', state: 'error' }])[0].state, 'ERROR');
});

test('review/final-head state is reported and not treated as a required miss', () => {
  const r = summarizePr(base({ checkRuns: [cr('A', 'success'), cr('B', 'success'), cr('review/final-head', null, '2026-11-10T01:00:00Z', 'in_progress')] }));
  assert.equal(r.finalHead, 'pending');
  assert.equal(summarizePr(base()).finalHead, 'none');
});

test('owner marker on the exact head counts; marker on an older head does not', () => {
  const mk = (sha) => `<!-- independent-review:v1 sha=${sha} verdict=pass -->`;
  const now = summarizePr(base({ comments: [owner(mk(HEAD))] }));
  assert.equal(now.review, 'independent-review');
  assert.deepEqual(now.markers, ['review']);
  const old = summarizePr(base({ comments: [owner(mk(OLD))] }));
  assert.match(old.review, /^no\(/);
  assert.deepEqual(old.markers, []);
  const stranger = summarizePr(base({ comments: [{ user: { login: 'someone' }, body: mk(HEAD) }] }));
  assert.match(stranger.review, /^no\(/);
  assert.deepEqual(markersOn([owner(`<!-- gate:qa-lead:v1 sha=${HEAD} verdict=fail --> <!-- gate:tech-lead:v1 sha=${OLD} verdict=pass -->`)], HEAD, OWNER), ['gate:qa-lead(fail)']);
});

test('unreadable comments never read as reviewed', () => {
  const r = summarizePr(base({ comments: null }));
  assert.match(r.review, /unreadable/);
});

test('unknown thread count prints ?, known count prints a number', () => {
  assert.match(formatRow(summarizePr(base())), /thr:\?/);
  const conn = { nodes: [{ isResolved: false, comments: { totalCount: 1 } }, { isResolved: true, comments: { totalCount: 2 } }], pageInfo: { hasNextPage: false } };
  assert.match(formatRow(summarizePr(base({ threads: conn }))), /thr:1/);
});

test('row carries draft flag, watched labels only, behind count, short sha', () => {
  const line = formatRow(summarizePr(base({ pr: pr({ draft: true }), behindBy: 3, checkRuns: [cr('A', 'success'), cr('B', 'success')] })));
  assert.match(line, /^#7 aaaaaaaa draft ms:clean req:2\/2 /);
  assert.match(line, /behind:3/);
  assert.match(line, /L:queue$/);
  assert.doesNotMatch(line, /bug/);
  assert.match(formatRow(summarizePr(base({ behindBy: null }))), /behind:\?/);
});

test('--pr verbose adds failing job names', () => {
  const line = formatRow(summarizePr(base({ checkRuns: [cr('A', 'failure'), cr('B', 'success'), cr('lint', 'failure')] })), true);
  assert.match(line, /\n {2}failing jobs: A; lint$/);
});

test('parseArgs validates input', () => {
  assert.deepEqual(parseArgs(['--json', '--pr', '5', '--max-lines', '3', '--repo', 'o/r']), { json: true, pr: 5, repo: 'o/r', maxLines: 3 });
  assert.throws(() => parseArgs(['--pr', 'x']));
  assert.throws(() => parseArgs(['--bogus']));
});

// --- run(): injected API, no network ---
const TOKEN = 'ghp_SECRET_TOKEN_VALUE';
const env = { GH_TOKEN: TOKEN, GITHUB_REPOSITORY: 'octo/repo' };
const fakeApi = (handlers) => ({
  one: async (p) => { for (const [k, v] of handlers) if (p.startsWith(k)) return typeof v === 'function' ? v(p) : v; throw new Error(`unexpected ${p}`); },
  all: async (p, key) => { for (const [k, v] of handlers) if (p.startsWith(k)) { const x = typeof v === 'function' ? v(p) : v; return key ? x[key] : x; } throw new Error(`unexpected ${p}`); },
});
const required = () => ({ required: ['A', 'B'] });
const happy = () => fakeApi([
  ['pulls?', [{ number: 7 }, { number: 8 }]],
  ['pulls/', (p) => pr({ number: Number(p.split('/')[1]) })],
  ['commits/' + HEAD + '/check-runs', { check_runs: [cr('A', 'success'), cr('B', 'failure')] }],
  ['commits/' + HEAD + '/status', { statuses: [] }],
  ['issues/', []],
  ['compare/', { behind_by: 2 }],
]);

test('run: one line per PR, max-lines truncates, json is compact', async () => {
  const r = await run([], env, { api: happy(), requiredChecks: required });
  assert.equal(r.code, 0);
  assert.equal(r.stdout.split('\n').length, 2);
  assert.match(r.stdout, /#7 aaaaaaaa .*req:1\/2 fail:B/);
  const t = await run(['--max-lines', '1'], env, { api: happy(), requiredChecks: required });
  assert.match(t.stdout, /\.\.\. 1 more/);
  const j = await run(['--json', '--pr', '7'], env, { api: happy(), requiredChecks: required });
  const rows = JSON.parse(j.stdout);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].n, 7);
  assert.equal(j.stdout.includes('\n'), false);
});

test('run: API error is non-zero and never renders as green', async () => {
  const bad = fakeApi([['pulls?', [{ number: 7 }]], ['pulls/', () => { throw new Error(`GET pulls/7 -> HTTP 502 ${TOKEN}`); }]]);
  const r = await run([], env, { api: bad, requiredChecks: required });
  assert.equal(r.code, 1);
  assert.match(r.stdout, /#7 ERROR/);
  assert.doesNotMatch(r.stdout, /req:\d+\/\d+/);
  const list = fakeApi([['pulls?', () => { throw new Error('GET pulls -> HTTP 401'); }]]);
  const r2 = await run([], env, { api: list, requiredChecks: required });
  assert.equal(r2.code, 1);
  assert.match(r2.stderr, /HTTP 401/);
});

test('run: no token, no repo and empty required list all fail closed', async () => {
  assert.equal((await run([], { GITHUB_REPOSITORY: 'o/r' }, {})).code, 1);
  assert.equal((await run([], { GH_TOKEN: TOKEN }, { api: happy(), requiredChecks: required })).code, 1);
  assert.equal((await run([], env, { api: happy(), requiredChecks: () => ({ required: [] }) })).code, 1);
  assert.equal((await run([], env, { api: happy(), requiredChecks: () => { throw new Error('gh failed'); } })).code, 1);
});

test('token never appears in stdout or stderr', async () => {
  const bad = fakeApi([['pulls?', [{ number: 7 }]], ['pulls/', () => { throw new Error(`boom ${TOKEN}`); }]]);
  for (const deps of [{ api: bad, requiredChecks: required }, { api: happy(), requiredChecks: required }, { api: happy(), requiredChecks: () => { throw new Error(`x ${TOKEN}`); } }]) {
    const r = await run([], env, deps);
    assert.equal((r.stdout + r.stderr).includes(TOKEN), false);
  }
  assert.equal(redact(`a ${TOKEN} b`, TOKEN), 'a *** b');
});
