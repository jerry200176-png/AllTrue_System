import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { decide, control, emptyLedger, FileLedger, POLICY, keyOf, validateLedger } from '../../.github/scripts/codex-review-throttle.mjs';
import { githubClient, GitHubLedger, repositoryPolicy, Backoff, verifyRemoteCas, bootstrapState } from '../../.github/scripts/codex-review-shared.mjs';
import { commentTransport, requestBody, reconcile, runLive } from '../../.github/scripts/codex-review-runner.mjs';
import { reader } from '../../.github/scripts/codex-review-snapshot.mjs';
const a = 'a'.repeat(40), b = 'b'.repeat(40), c = 'c'.repeat(40), now = Date.parse('2026-10-14T12:00:00Z'), copy = structuredClone;
const check = () => ({ __typename: 'CheckRun', name: 'CI', status: 'COMPLETED', conclusion: 'SUCCESS', startedAt: '2026-10-14T11:00:00Z', checkSuite: { app: { databaseId: 15368 }, repository: { nameWithOwner: 'o/r' } } });
const snapshot = () => ({ repo: 'o/r', number: 1, head: a, base: b, state: 'open', draft: false, evidenceComplete: true, externalReview: false, threads: { nodes: [], pageInfo: { hasNextPage: false } }, ready: false, observedChangeAt: '2026-10-14T11:40:00Z', required: ['CI'], pins: [['CI', 15368]], checks: [check()], ciHead: a, risk: { machine_minimum_tier: 1, reasons: [] }, baseDependenciesChanged: false, reviews: [], comments: [] });
const entry = (s = snapshot(), state = 'completed') => ({ repo: s.repo, number: s.number, head: s.head, base: s.base, policy: POLICY, key: keyOf(s), state });
function memory(data = emptyLedger()) { let saved = copy(data); return { exclusive: async (f) => f(), load: async () => copy(saved), save: async (v) => { validateLedger(v); saved = copy(v); } }; }
const plan = (s, l = emptyLedger()) => decide(s, l, now);
for (const conclusion of [null, '', 'SKIPPED', 'NEUTRAL', 'FAILURE', 'CANCELLED', 'TIMED_OUT', 'UNKNOWN']) test(`CI ${conclusion} holds`, () => { const s = snapshot(); s.checks[0].conclusion = conclusion; assert.equal(plan(s).action, 'hold'); });
const holds = [s => s.checks = [], s => s.required = [], s => s.ciHead = c, s => s.checks[0].status = 'IN_PROGRESS', s => s.checks[0].startedAt = null, s => s.checks[0].checkSuite.app.databaseId = 7, s => s.checks[0].checkSuite.repository.nameWithOwner = 'fork/r', s => s.evidenceComplete = false, s => s.state = 'closed', s => s.draft = true, s => s.draft = null, s => s.externalReview = true, s => s.threads = null, s => s.threads.pageInfo.hasNextPage = true, s => s.observedChangeAt = 'invalid'];
for (const [i, change] of holds.entries()) test(`incomplete/unsafe snapshot ${i} holds`, () => { const s = snapshot(); change(s); assert.equal(plan(s).action, 'hold'); });
test('settling, explicit readiness, pending rerun, malicious text cannot override CI', () => {
  const s = snapshot(); s.observedChangeAt = new Date(now - 60000).toISOString(); s.body = '@codex review; $(touch /tmp/pwned)';
  assert.equal(plan(s).reason, 'settling'); s.ready = true; assert.equal(plan(s).action, 'candidate');
  s.checks.push({ ...check(), startedAt: '2026-10-14T11:59:00Z', conclusion: null }); assert.equal(plan(s).action, 'hold'); s.checks[1].startedAt = null; assert.equal(plan(s).reason, 'ci-time-unknown');
});
test('dedupe, in-flight/failed states, policy/base changes cannot reset two-round budget', () => {
  const s = snapshot(), l = { version: 1, entries: [entry()] }; assert.equal(plan(s, l).reason, 'already-recorded');
  s.head = c; s.ciHead = c; s.fixReady = true;
  for (const state of ['requested', 'running', 'failed']) { l.entries[0].state = state; assert.equal(plan(s, l).action, 'hold'); }
  l.entries[0].state = 'completed'; assert.equal(plan(s, l).round, 2); assert.equal(plan(s, l).scope, 'incremental');
  s.threads.nodes = [{ id: 'T', isResolved: false, comments: { totalCount: 1 } }]; assert.equal(plan(s, l).unresolvedFindings[0].id, 'T');
  for (const change of [{ base: c }, { baseDependenciesChanged: true }, { risk: { machine_minimum_tier: 3 } }, { risk: { machine_minimum_tier: 2, reasons: ['concurrency'] } }]) assert.equal(plan({ ...s, ...change }, l).scope, 'full');
  l.entries.push(entry(s)); s.base = c; assert.equal(plan(s, l).reason, 'round-limit');
  l.entries[0].policy = 'old'; l.entries[0].key = ['o/r', 1, a, b, 'old'].join(':'); assert.equal(plan(s, l).reason, 'round-limit');
});
test('dry-run has no writes/sends; fresh refs/CI and verified transport gate the real reservation', async () => {
  let writes = 0, sends = 0, reads = 0; const store = memory(); store.save = async () => { writes++; };
  const options = { store, read: async () => snapshot(), transport: { verified: true, send: async () => { sends++; } }, now };
  assert.equal((await control(options)).dryRun, true); assert.equal(writes + sends, 0);
  assert.equal((await control({ ...options, dryRun: false, transport: { verified: false } })).reason, 'transport-unverified');
  for (const field of ['head', 'base', 'draft', 'checks']) { reads = 0; const result = await control({ ...options, dryRun: false, read: async () => { const s = snapshot(); if (++reads > 1) s[field] = field === 'draft' ? true : field === 'checks' ? [] : c; return s; } }); assert.equal(result.action, 'hold'); }
  assert.equal(writes + sends, 0);
});
test('ambiguous POST survives restart and reconciles running/completed/failed without re-send', async () => {
  const store = memory(); let sends = 0; const transport = { verified: true, send: async () => { sends++; throw new Error('timeout'); }, lookup: async () => null };
  const options = { read: async () => snapshot(), store, transport, dryRun: false, now };
  assert.equal((await control(options)).action, 'requested'); assert.equal((await control(options)).reason, 'already-recorded'); assert.equal(sends, 1);
  for (const state of ['running', 'completed', 'failed']) { const st = memory({ version: 1, entries: [entry(snapshot(), 'requested')] }); await control({ ...options, store: st, transport: { ...transport, lookup: async () => ({ state }) } }); assert.equal((await st.load()).entries[0].state, state); } assert.equal(sends, 1);
});
function remote() { let data = emptyLedger(), sha = a, writes = 0; const api = async (method, route, body) => { if (method === 'GET' && route.includes('/contents?')) return [{ path: 'ledger.json', type: 'file' }]; if (method === 'GET') return { type: 'file', encoding: 'base64', sha, content: Buffer.from(JSON.stringify(data)).toString('base64') }; assert.equal(body.branch, 'codex-review-state'); if (body.sha !== sha) throw new Error('409 conflict'); data = JSON.parse(Buffer.from(body.content, 'base64')); sha = c; writes++; return { content: { sha } }; }; return { api, data: () => data, writes: () => writes }; }
test('remote CAS race has one send; lost reservation response never retries, missing/corrupt/history state stops', async () => {
  const r = remote(); let sends = 0; const options = { read: async () => snapshot(), dryRun: false, now, transport: { verified: true, send: async () => { sends++; }, lookup: async () => null } };
  await Promise.allSettled([control({ ...options, store: new GitHubLedger(r.api, 'o/r') }), control({ ...options, store: new GitHubLedger(r.api, 'o/r') })]); assert.equal(sends, 1); assert.equal(r.data().entries.length, 1);
  const s = new GitHubLedger(r.api, 'o/r'); await s.load(); await assert.rejects(s.save(emptyLedger()), /history/);
  const lost = remote(), api = async (...args) => { const value = await lost.api(...args); if (args[0] === 'PUT') throw new Error('lost'); return value; };
  await assert.rejects(control({ ...options, store: new GitHubLedger(api, 'o/r') }), /lost/); await control({ ...options, store: new GitHubLedger(api, 'o/r') }); assert.equal(lost.writes(), 1); assert.equal(sends, 1);
  await assert.rejects(new GitHubLedger(async () => { throw new Error('404'); }, 'o/r').load(), /404/); assert.throws(() => validateLedger({})); assert.throws(() => validateLedger({ version: 1, entries: [entry(), entry()] }));
});
test('shared rate limits honor seconds/date/reset/GraphQL and persisted cooldown blocks another run/repo', async () => {
  for (const r of [{ status: 403, headers: { 'Retry-After': '120' }, body: { message: 'secondary rate limit' } }, { status: 429, headers: { 'Retry-After': new Date(now + 180000).toUTCString() } }, { status: 403, headers: { 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': String((now + 300000) / 1000) } }, { status: 200, body: { errors: [{ type: 'RATE_LIMITED' }] } }]) {
    let calls = 0, saved; const api = githubClient(async () => { calls++; return r; }, { now: () => now, persist: (v) => { saved = copy(v); } });
    await assert.rejects(api('GET', '/one'), Backoff); await assert.rejects(api('GET', '/two'), Backoff); assert.equal(calls, 1);
    await assert.rejects(githubClient(async () => { calls++; }, { now: () => now, cooldown: saved })('POST', '/one'), Backoff); assert.equal(calls, 1); assert.ok(saved.until >= now + 60000);
  }
});
function serviceFixture() {
  const s = snapshot(), target = { repo: s.repo, number: 1, head: a, base: b }, plan = { ...target, key: keyOf(s), nonce: 'd'.repeat(32), scope: 'full', from: null, unresolvedFindings: [] };
  const e = { ...entry(s, 'requested'), nonce: plan.nonce, requestedAt: new Date(now).toISOString(), requestBody: requestBody(plan) };
  const own = { id: 1, user: { id: 10 }, body: e.requestBody, created_at: e.requestedAt, updated_at: e.requestedAt };
  const done = { id: 2, user: { id: 20, type: 'Bot' }, body: `<!-- codex-throttle:${e.key}:${e.nonce} -->\nhead=${a} base=${b}`, commit_id: a, submitted_at: e.requestedAt, state: 'COMMENTED' };
  return { s, plan, e, own, done };
}
test('transport authenticates exact sender/bot, immutable refs/marker/body/time; no heuristic completion', async () => {
  const f = serviceFixture(); let comments = [f.own], reviews = [f.done], sends = 0;
  const api = async (method, route, body) => { if (method === 'POST') { sends++; assert.equal(body.body, f.e.requestBody); return {}; } if (route.includes('/comments?')) return comments; if (route.includes('/reviews?')) return reviews; return { state: 'open', draft: false, head: { sha: a }, base: { sha: b } }; };
  const t = commentTransport(api, 'o/r', 1, 10, 20); assert.equal((await t.lookup(f.e)).state, 'completed'); await t.send(f.plan, f.e); assert.equal(sends, 1);
  for (const patch of [{ user: { id: 999, type: 'Bot' } }, { commit_id: c }, { body: 'looks good' }, { body: f.done.body.replace(b, c) }, { submitted_at: '2000-01-01' }, { body: f.done.body.replace('d'.repeat(32), 'e'.repeat(32)) }]) { reviews = [{ ...f.done, ...patch }]; assert.equal(await t.lookup(f.e), null); }
  reviews = [f.done]; for (const patch of [{ user: { id: 999 } }, { body: f.own.body + 'edit' }, { updated_at: '2000-01-01' }]) { comments = [{ ...f.own, ...patch }]; assert.equal(await t.lookup(f.e), null); }
  reviews = [];
  for (const state of ['running', 'failed']) { comments = [f.own, { ...f.done, created_at: f.e.requestedAt, body: `Codex review ${state}\n${f.done.body}` }]; assert.equal((await t.lookup(f.e)).state, state); }
  comments = [f.own, f.own]; assert.equal(await t.lookup(f.e), null); await assert.rejects(t.send({ ...f.plan, head: c }, f.e), /mismatch/);
});
test('external completions consume rounds; unknown/running comments hold, retained across observations', () => {
  const f = serviceFixture(), old = emptyLedger(); f.s.senderId = 10; f.s.reviews = [f.done];
  let l = reconcile(f.s, old, 20); assert.equal(l.external.length, 1); f.s.head = c; f.s.ciHead = c; f.s.fixReady = true; assert.equal(plan(f.s, l).round, 2);
  f.s.comments = [{ id: 3, body: '@codex review', user: { id: 77 } }]; l = reconcile(f.s, l, 20); assert.equal(plan(f.s, l).reason, 'external-review-unknown');
  f.s.reviews = []; f.s.comments = []; assert.equal(reconcile(f.s, l, 20).external.length, 2);
  f.s.reviews = [{ ...f.done, state: 'PENDING' }]; assert.equal(plan(f.s, reconcile(f.s, emptyLedger(), 20)).action, 'hold');
});
function activation() { return { version: 1, policy: POLICY, repositories: [{ repo: 'o/r', base: 'main', riskAdapter: 'full', enabled: true, overrideOffVerified: true }], activation: { enabled: true, decisionApproved: true, stateCasVerified: true, durableExecutorVerified: true, sourceSha: a, mode: 'canary', senderId: 10, botId: 20, canary: { repo: 'o/r', number: 1, head: a, base: b } } }; }
function cooldown() { const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'review-cooldown-')), store = new FileLedger(path.join(dir, 'state')); store.save({ ...emptyLedger(), cooldownUntil: 0 }); return { dir, store }; }
test('runner is disabled by default; eight repos/sample/settings baseline stay unverified', async () => {
  const cfg = JSON.parse(fs.readFileSync('.github/scripts/codex-review-fleet.json')); assert.equal(cfg.repositories.length, 8); assert.equal(cfg.settingsUserReportedAt, '2026-10-14T12:19:58Z');
  for (const p of cfg.repositories) { assert.equal(p.enabled, false); assert.equal(p.overrideOffVerified, false); assert.throws(() => repositoryPolicy(cfg, p.repo, true)); }
  await assert.rejects(runLive({ config: cfg, repo: cfg.repositories[0].repo, number: 1, sha: a, apiRequest: () => assert.fail('network') }), /disabled/);
});
test('runner uses durable cooldown, authenticated executor, remote reservation and one comment (fixtures only)', async () => {
  const cd = cooldown(); let posts = 0; const state = memory(), cfg = activation();
  const req = async (method, route) => { if (method === 'POST') { posts++; return { status: 201, body: {} }; } const body = route.endsWith('/commits/main') ? { sha: a } : route.includes('/users/') ? { id: 20, type: 'Bot' } : route === '/user' ? { id: 10 } : route === '/repos/o/r' ? { permissions: { push: true }, default_branch: 'main' } : route.includes('?') ? [] : { state: 'open', draft: false, head: { sha: a }, base: { sha: b } }; return { status: 200, body }; };
  const opts = { config: cfg, repo: 'o/r', number: 1, sha: a, apiRequest: req, store: state, cooldownStore: cd.store, readSnapshot: async () => snapshot(), now: () => now };
  try {
    assert.equal((await runLive(opts)).action, 'requested'); assert.equal(posts, 1); await runLive(opts); assert.equal(posts, 1);
    cd.store.save({ ...emptyLedger(), cooldownUntil: now + 100000 }); await assert.rejects(runLive(opts), Backoff); assert.equal(posts, 1);
    fs.writeFileSync(cd.store.path, '{}'); await assert.rejects(runLive(opts));
    fs.unlinkSync(cd.store.path); await assert.rejects(runLive(opts));
  } finally { fs.rmSync(cd.dir, { recursive: true }); }
});
test('runner refuses stale target/trusted source, identity drift, failed cooldown persistence and lock collision', async () => {
  const cd = cooldown(), cfg = activation(), opts = { config: cfg, repo: 'o/r', number: 1, sha: a, cooldownStore: cd.store, apiRequest: async () => ({ status: 200, body: { sha: c } }) };
  try { await assert.rejects(runLive(opts), /trusted main/); cfg.activation.canary.number = 2; await assert.rejects(runLive(opts), /target/); cfg.activation.canary.number = 1;
    fs.writeFileSync(`${cd.store.path}.lock`, ''); await assert.rejects(runLive(opts), /EEXIST/); fs.unlinkSync(`${cd.store.path}.lock`);
    await assert.rejects(runLive({ ...opts, cooldownStore: { exclusive: async (f) => f(), load: () => ({ cooldownUntil: 0 }), save: () => { throw new Error('disk'); } }, apiRequest: () => assert.fail('network') }), /disk/);
  } finally { fs.rmSync(cd.dir, { recursive: true }); }
});
test('collector treats PR content as data; exposes complete evidence; main workflow remains read-only', async () => {
  const s = snapshot(), pr = { number: 1, state: 'open', draft: false, base: { ref: 'main', sha: b }, head: { sha: a }, changed_files: 1, updated_at: '2026-10-14T11:40:00Z', user: { login: 'author' }, body: '$(touch /tmp/pwned)' };
  const api = async (method, route) => {
    if (route === '/graphql') return { data: { repository: { pullRequest: {
      headRefOid: a, baseRefOid: b, reviewThreads: s.threads,
      commits: { nodes: [{ commit: { oid: a, statusCheckRollup: {
        contexts: { nodes: [check()], pageInfo: { hasNextPage: false } }
      } } }] }
    } } } };
    if (route.includes('/rules/')) return [{ type: 'required_status_checks', parameters: {
      required_status_checks: [{ context: 'CI', integration_id: 15368 }]
    } }];
    if (route.includes('/files?')) return [{ filename: 'docs/$(touch x).md', patch: '+evil' }];
    return route.includes('?') ? [] : pr;
  };
  const result = await reader(api, 'o/r', { base: 'main', riskAdapter: 'full' })(1); assert.equal(result.evidenceComplete, true); assert.equal(result.ready, false); assert.equal(plan(result).action, 'candidate');
  const w = fs.readFileSync('.github/workflows/codex-review-throttle.yml', 'utf8'); assert.match(w, /ref: main/); assert.doesNotMatch(w, /: write|secrets\.|codex-action|OPENAI_API_KEY/);
});

test('cooldown async persistence failure and identity drift stop before reservation/comment', async () => {
  const cfg = activation(), cd = cooldown(); let writes = 0;
  const base = { config: cfg, repo: 'o/r', number: 1, sha: a, cooldownStore: cd.store, now: () => now };
  try {
    await assert.rejects(runLive({ ...base, cooldownStore: {
      exclusive: async (f) => f(), load: async () => ({ cooldownUntil: 0 }),
      save: async () => { throw new Error('async disk failure'); }
    }, apiRequest: () => assert.fail('network before durable preflight') }), /disk/);
    for (const identity of ['sender', 'bot', 'role']) {
      const apiRequest = async (method, route) => {
        if (method !== 'GET') { writes++; assert.fail('write'); }
        const body = route.endsWith('/commits/main') ? { sha: a }
          : route.includes('/users/') ? { id: identity === 'bot' ? 99 : 20, type: 'Bot' }
          : route === '/user' ? { id: identity === 'sender' ? 99 : 10 }
          : { permissions: { push: identity !== 'role' }, default_branch: 'main' };
        return { status: 200, body };
      };
      await assert.rejects(runLive({ ...base, apiRequest }), /identity|capability/);
    }
    assert.equal(writes, 0);
  } finally { fs.rmSync(cd.dir, { recursive: true }); }
});

test('bounded CAS probe fixture proves two writes conflict; never substitutes for live acceptance', async () => {
  const r = remote(); assert.equal((await verifyRemoteCas(r.api, 'o/r', 'f'.repeat(32))).verified, true);
  assert.equal(r.writes(), 1); assert.equal(r.data().entries.length, 0);
  await assert.rejects(verifyRemoteCas(r.api, 'o/r', 'bad'), /nonce/);
});

test('bootstrap fixture creates only a root data tree; uncertain ref creation reads back, never repeats', async () => {
  let ref = null, posts = 0;
  const api = async (method, route, body) => {
    if (method === 'GET') { if (!ref) throw new Error('404'); return { object: { sha: ref } }; }
    posts++; if (route.endsWith('/trees')) { assert.deepEqual(body.tree.map((f) => f.path), ['ledger.json']); assert.equal(body.base_tree, undefined); return { sha: a }; }
    if (route.endsWith('/commits')) { assert.deepEqual(body.parents, []); return { sha: b }; }
    assert.equal(body.ref, 'refs/heads/codex-review-state'); ref = b; throw new Error('response lost');
  };
  assert.equal((await bootstrapState(api, 'o/r')).commit, b); assert.equal(posts, 3);
  await assert.rejects(bootstrapState(api, 'o/r'), /exists/); assert.equal(posts, 3);
});
