import assert from 'node:assert/strict';
import test from 'node:test';
import { BATCH_BASE_MESSAGE, BATCH_MAX, batchSerial, guardedSquash, isBatchBase, TRUSTED_AUTHORS, fromPinned, suiteRuns, memberOf, staleReason, batchVerdict, checkStates, decide, marker, memberMessage, neutral, orderQueue, parseMember, planBatch, unresolvedCount } from '../../.github/scripts/land-queue.mjs';
import { CODEX_LOGIN, REVIEW_CHECK, decodeEntities, reviewGate, reviewMarker } from '../../.github/scripts/review-gate.mjs';
import fs from 'node:fs';

const req = ['A', 'B'];
const ok = [{ name: 'A', conclusion: 'SUCCESS' }, { name: 'B', conclusion: 'SUCCESS' }];
const pr = (o = {}) => ({ mergeStateStatus: 'CLEAN', rollup: ok, unresolvedThreads: 0, review: { ok: true, via: 'codex' }, ...o });

test('orderQueue: oldest label first, skips drafts and non-main', () => {
  const q = orderQueue([
    { number: 3, labeledAt: '2026-10-06T02:00Z', baseRefName: 'main' },
    { number: 1, labeledAt: '2026-10-06T03:00Z', baseRefName: 'main' },
    { number: 2, labeledAt: '2026-10-06T01:00Z', baseRefName: 'main', isDraft: true },
    { number: 4, labeledAt: '2026-10-06T00:00Z', baseRefName: 'dev' },
  ]);
  assert.deepEqual(q.map((p) => p.number), [3, 1]);
});

test('decide: BEHIND updates, DIRTY rejects', () => {
  assert.equal(decide(pr({ mergeStateStatus: 'BEHIND' }), req).action, 'update');
  assert.equal(decide(pr({ mergeStateStatus: 'DIRTY' }), req).reason, 'conflict');
});

test('decide: failed check names it; latest rerun wins', () => {
  const d = decide(pr({ rollup: [{ name: 'A', conclusion: 'FAILURE', startedAt: '1' }, ok[1]] }), req);
  assert.equal(d.reason, 'checks');
  assert.match(d.text, /\bA\b/);
  const rerun = [{ name: 'A', conclusion: 'FAILURE', startedAt: '1' }, { name: 'A', conclusion: 'SUCCESS', startedAt: '2' }, ok[1]];
  assert.equal(decide(pr({ rollup: rerun }), req).action, 'merge');
});

test('decide: cancelled-only required checks rerun their workflow runs (#4700); any real failure rejects', () => {
  const cx = (name, conclusion, id) => ({ name, conclusion, startedAt: '1', checkSuite: { workflowRun: id ? { databaseId: id } : null } });
  const d = decide(pr({ rollup: [cx('A', 'CANCELLED', 7), cx('B', 'CANCELLED', 7)] }), req);
  assert.equal(d.action, 'rerun');
  assert.equal(d.reason, 'rerun-cancelled');
  assert.deepEqual(d.runs, [7]); // one workflow run behind both contexts: rerun once
  assert.equal(decide(pr({ rollup: [cx('A', 'CANCELLED', 7), cx('B', 'FAILURE', 8)] }), req).reason, 'checks');
  assert.equal(decide(pr({ rollup: [cx('A', 'CANCELLED', null), ok[1]] }), req).reason, 'checks'); // no run to rerun
  assert.equal(decide(pr({ rollup: [cx('A', 'TIMED_OUT', 7), ok[1]] }), req).reason, 'checks');
  assert.equal(decide(pr({ unresolvedThreads: 1, rollup: [cx('A', 'CANCELLED', 7), ok[1]] }), req).reason, 'threads');
  // The accepted rerun is queued (no startedAt yet): it is the latest attempt, so wait instead of re-judging the cancel.
  const queued = { name: 'A', status: 'QUEUED', conclusion: null, startedAt: null, checkSuite: { workflowRun: { databaseId: 7 } } };
  assert.equal(decide(pr({ rollup: [cx('A', 'CANCELLED', 7), queued, ok[1]] }), req).action, 'wait');
  assert.deepEqual(checkStates([cx('A', 'CANCELLED', 7), queued], ['A']), { A: 'pending' });
});

test('decide: unresolved review threads reject; known zero keeps merge behavior', () => {
  const d = decide(pr({ unresolvedThreads: 2 }), req);
  assert.equal(d.action, 'reject');
  assert.equal(d.reason, 'threads');
  assert.match(d.text, /^2 unresolved review thread\(s\); reply to and resolve them, then re-add `queue`$/);
  assert.match(decide(pr({ unresolvedThreads: 7 }), req).text, /^7 unresolved/);
  // Threads reject even while checks are still pending (early feedback, label removed).
  assert.equal(decide(pr({ unresolvedThreads: 1, rollup: [ok[0]] }), req).reason, 'threads');
  // BEHIND / DIRTY are handled first.
  assert.equal(decide(pr({ unresolvedThreads: 1, behindBy: 2 }), req).action, 'update');
  assert.equal(decide(pr({ unresolvedThreads: 1, mergeStateStatus: 'DIRTY' }), req).reason, 'conflict');
  assert.equal(decide(pr({ unresolvedThreads: 0 }), req).action, 'merge');
});

test('decide: unknown thread count never merges (fail closed)', () => {
  for (const unresolvedThreads of [null, undefined, NaN, -1, 1.5, '0']) {
    const d = decide(pr({ unresolvedThreads }), req);
    assert.notEqual(d.action, 'merge', String(unresolvedThreads));
    assert.equal(d.action, 'wait');
  }
  // Failed checks still reject with their own reason when the thread count is unknown.
  assert.equal(decide(pr({ unresolvedThreads: null, rollup: [{ name: 'A', conclusion: 'FAILURE' }, ok[1]] }), req).reason, 'checks');
});

test('unresolvedCount: a thread is open until resolved AND replied; otherwise unknown', () => {
  const page = (nodes, hasNextPage = false) => ({ nodes, pageInfo: { hasNextPage } });
  const t = (isResolved, replies) => ({ isResolved, comments: { totalCount: replies } });
  assert.equal(unresolvedCount(page([])), 0);
  assert.equal(unresolvedCount(page([t(true, 2), t(false, 2), t(false, 1)])), 2);
  assert.equal(unresolvedCount(page([t(true, 3)])), 0);
  // Resolved without a reply still blocks.
  assert.equal(unresolvedCount(page([t(true, 1)])), 1);
  assert.equal(unresolvedCount(page([t(true, 2), t(true, 1)])), 1);
  // Missing comment count is unknown, never zero.
  assert.equal(unresolvedCount(page([{ isResolved: true }])), null);
  assert.equal(unresolvedCount(page(Array(100).fill(t(true, 2)), true)), null); // >100 threads
  assert.equal(unresolvedCount({ nodes: [] }), null); // no pageInfo
  assert.equal(unresolvedCount(null), null);
  assert.equal(unresolvedCount(undefined), null);
});

test('batch eligibility honors the thread gate (neutral keeps unresolvedThreads)', () => {
  const behind = (o) => pr({ mergeStateStatus: 'BEHIND', behindBy: 3, ...o });
  assert.equal(decide(neutral(behind({ unresolvedThreads: 0 })), req).action, 'merge');
  assert.equal(decide(neutral(behind({ unresolvedThreads: 1 })), req).reason, 'threads');
  assert.equal(decide(neutral(behind({ unresolvedThreads: null })), req).action, 'wait');
  // A member whose thread opened after the batch was cut is no longer ready -> batch dropped.
  const ready = (h) => decide(neutral(h), req).action === 'merge';
  const members = [{ n: 1, sha: 'a' }, { n: 2, sha: 'b' }];
  const current = new Map([[1, { sha: 'a', ready: ready(pr({ unresolvedThreads: 0 })) }], [2, { sha: 'b', ready: ready(pr({ unresolvedThreads: 1 })) }]]);
  assert.equal(staleReason(members, 'm', 'm', current), '#2 no longer ready');
});

test('decide: pending or missing checks wait', () => {
  assert.equal(decide(pr({ rollup: [ok[0], { name: 'B', status: 'IN_PROGRESS', conclusion: null }] }), req).action, 'wait');
  assert.equal(decide(pr({ rollup: [ok[0]] }), req).action, 'wait');
  assert.equal(decide(pr({ mergeStateStatus: 'BLOCKED' }), req).action, 'wait');
});

test('decide: CLEAN and all green merges; helpers', () => {
  assert.equal(decide(pr(), req).action, 'merge');
  assert.deepEqual(checkStates([], ['A']), { A: 'pending' });
  assert.equal(marker('conflict', 'abc'), '<!-- land-queue:conflict:abc -->');
});

test('decide: UNSTABLE with all required green merges (non-required cancel/fail)', () => {
  assert.equal(decide(pr({ mergeStateStatus: 'UNSTABLE' }), req).action, 'merge');
  assert.equal(decide(pr({ mergeStateStatus: 'UNSTABLE', unresolvedThreads: null }), req).action, 'wait');
  assert.equal(decide(pr({ mergeStateStatus: 'UNSTABLE', unresolvedThreads: 1 }), req).reason, 'threads');
  assert.equal(
    decide(pr({
      mergeStateStatus: 'UNSTABLE',
      rollup: [ok[0], { name: 'B', status: 'IN_PROGRESS', conclusion: null }],
    }), req).action,
    'wait',
  );
});

test('orderQueue: fork PRs never queue (batch merges heads into this repo)', () => {
  const q = orderQueue([{ number: 1, baseRefName: 'main', isCrossRepository: true }, { number: 2, baseRefName: 'main', isCrossRepository: false }]);
  assert.deepEqual(q.map((p) => p.number), [2]);
});

test('decide: a head behind main updates even when GitHub stops reporting BEHIND (strict off)', () => {
  assert.equal(decide(pr({ behindBy: 3 }), req).action, 'update');
  assert.equal(decide(pr({ behindBy: 0 }), req).action, 'merge');
});

test('planBatch: oldest first, max 4, needs 2, serial-only head of queue goes alone', () => {
  const c = (n, serial = false) => ({ n, serial });
  assert.deepEqual(planBatch([1, 2, 3, 4, 5, 6].map((n) => c(n))).map((x) => x.n), [1, 2, 3, 4]);
  assert.equal(BATCH_MAX, 4);
  assert.deepEqual(planBatch([c(1)]), []);
  assert.deepEqual(planBatch([c(1, true), c(2), c(3)]), []);
  assert.deepEqual(planBatch([c(1), c(2), c(3, true), c(4)]).map((x) => x.n), [1, 2]);
});

test('member message round-trips; foreign commits do not parse', () => {
  const sha = 'a'.repeat(40);
  assert.deepEqual(parseMember(memberMessage(42, sha)), { n: 42, sha });
  assert.equal(parseMember('Merge branch main'), null);
  assert.equal(parseMember(`land-queue batch member #4 ${'a'.repeat(39)}`), null);
});

test('batchVerdict: only all-pass lands; any fail is red; pending waits then times out', () => {
  assert.equal(batchVerdict({ A: 'pass', B: 'pass' }, 0), 'land');
  assert.equal(batchVerdict({ A: 'pass', B: 'fail' }, 0), 'red');
  assert.equal(batchVerdict({ A: 'fail', B: 'pending' }, 0), 'red');
  assert.equal(batchVerdict({ A: 'pass', B: 'pending' }, 1000), 'wait');
  assert.equal(batchVerdict({ A: 'pass', B: 'pending' }, 3 * 3600e3), 'red');
  assert.equal(batchVerdict({}, 0), 'wait'); // no required contexts is never a vacuous pass
});

const m = (n, sha = String(n).repeat(40).slice(0, 40)) => ({ n, sha });
const cur = (...ms) => new Map(ms.map((x) => [x.n, { sha: x.sha, ready: true }]));

test('staleReason: batch survives only if main, every member head and readiness are unchanged', () => {
  const ms = [m(1), m(2)];
  assert.equal(staleReason(ms, 'b', 'b', cur(...ms)), '');
  assert.equal(staleReason([], 'b', 'b', cur()), 'no members');
  assert.equal(staleReason(ms, 'b', 'c', cur(...ms)), 'main moved');
  assert.match(staleReason(ms, 'b', 'b', cur(m(1))), /#2 left the queue/);
  assert.match(staleReason(ms, 'b', 'b', cur(m(1), m(2, 'f'.repeat(40)))), /#2 head moved/);
  const notReady = cur(...ms); notReady.get(1).ready = false;
  assert.match(staleReason(ms, 'b', 'b', notReady), /#1 no longer ready/);
  assert.match(staleReason([{ n: 1, sha: '' }], 'b', 'b', cur(m(1))), /head moved/); // head not pinned by a merge parent
});

test('suiteRuns: a dispatch-only batch tip (no statusCheckRollup) is judged from its check suites', () => {
  // 2026-10-28: batch chore/land-queue-batch-40631598403 had every required check green and waited for the timeout.
  const suite = (id, repo, runs) => ({ app: { databaseId: id }, repository: { nameWithOwner: repo }, checkRuns: { nodes: runs } });
  const commit = { checkSuites: { nodes: [
    suite(15368, 'o/r', [{ name: 'A', conclusion: 'SUCCESS', status: 'COMPLETED', startedAt: '2026-10-28T04:20:00Z' }]),
    suite(15368, 'o/r', [{ name: 'B', conclusion: 'SUCCESS', status: 'COMPLETED', startedAt: '2026-10-28T04:21:00Z' }]),
    suite(999, 'o/r', [{ name: 'C', conclusion: 'SUCCESS', status: 'COMPLETED' }]), // another app never counts
    suite(15368, 'evil/fork', [{ name: 'C', conclusion: 'SUCCESS', status: 'COMPLETED' }]),
    { app: { databaseId: 1 }, repository: { nameWithOwner: 'o/r' }, checkRuns: { nodes: [] } }, // queued third-party suite
  ] } };
  const pins = new Map([['A', 15368], ['B', 15368], ['C', 15368]]);
  assert.deepEqual(checkStates(fromPinned(suiteRuns(commit), pins, 'o/r'), ['A', 'B', 'C']), { A: 'pass', B: 'pass', C: 'pending' });
  assert.equal(batchVerdict(checkStates(fromPinned(suiteRuns(commit), pins, 'o/r'), ['A', 'B']), 0), 'land');
  assert.deepEqual(suiteRuns(null), []);
});

test('fromPinned: only check runs of the pinned app from this repo count, pinned or not', () => {
  const run = (id, name = 'A', repo = 'o/r') => ({ __typename: 'CheckRun', name, conclusion: 'SUCCESS', checkSuite: { app: { databaseId: id }, repository: { nameWithOwner: repo } } });
  const pins = new Map([['A', 15368], ['U', null]]);
  const kept = fromPinned([run(15368), run(999), { __typename: 'StatusContext', context: 'A', state: 'SUCCESS' }, { __typename: 'CheckRun', name: 'A' }], pins, 'o/r');
  assert.equal(kept.length, 1);
  assert.deepEqual(checkStates(fromPinned([run(999)], pins, 'o/r'), ['A']), { A: 'pending' });
  // unpinned in the ruleset: still bound to GitHub Actions, never name-only
  assert.equal(fromPinned([run(999, 'U'), { __typename: 'StatusContext', context: 'U', state: 'SUCCESS' }], pins, 'o/r').length, 0);
  assert.equal(fromPinned([run(15368, 'U')], pins, 'o/r').length, 1);
  // run from another repository (fork) on the same name/app does not count
  assert.equal(fromPinned([run(15368, 'A', 'evil/fork')], pins, 'o/r').length, 0);
  assert.deepEqual(checkStates(fromPinned([run(999)], new Map([['A', 999]]), 'o/r'), ['A']), { A: 'pass' }); // repinned in the ruleset
});

test('memberOf: only a two-parent merge whose 2nd parent is the recorded head is batch metadata', () => {
  const sha = 'a'.repeat(40);
  const c = (o = {}) => ({ message: memberMessage(7, sha), parents: [{ sha: 'b'.repeat(40) }, { sha }], tree: { sha: 't1' }, ...o });
  assert.deepEqual(memberOf(c()), { n: 7, sha, tree: 't1' });
  assert.equal(memberOf(c({ parents: [{ sha: 'b'.repeat(40) }] })), null); // squash whose title mimics the message
  assert.equal(memberOf(c({ parents: [{ sha: 'b'.repeat(40) }, { sha: 'c'.repeat(40) }] })), null);
  assert.equal(memberOf(c({ message: `${memberMessage(7, sha)} (#12)\n\nbody` })), null);
});

test('decide: DIRTY wins over behindBy (update cannot fix a conflict; queue must reject)', () => {
  assert.equal(decide(pr({ mergeStateStatus: 'DIRTY', behindBy: 4 }), req).reason, 'conflict');
});

test('forks and non-owner authors: forks never queue, only trusted associations may be batched', () => {
  assert.deepEqual(orderQueue([{ number: 1, baseRefName: 'main', isCrossRepository: true }]), []);
  assert.deepEqual([...TRUSTED_AUTHORS].sort(), ['COLLABORATOR', 'MEMBER', 'OWNER']);
  assert.ok(!TRUSTED_AUTHORS.has('CONTRIBUTOR') && !TRUSTED_AUTHORS.has('NONE'));
});

test('isBatchBase: only the queue-made empty base commit proves batch ownership (prefix alone does not)', () => {
  const base = (o = {}) => ({ message: BATCH_BASE_MESSAGE, parents: [{ sha: 'p' }], tree: { sha: 't' }, ...o });
  assert.equal(isBatchBase(base(), 't'), true);
  assert.equal(isBatchBase(base({ message: 'wip on my branch' }), 't'), false); // a developer's chore/land-queue-batch-* branch
  assert.equal(isBatchBase(base(), 'other-tree'), false); // not an empty commit
  assert.equal(isBatchBase(base({ parents: [{ sha: 'a' }, { sha: 'b' }] }), 't'), false);
  assert.equal(isBatchBase(undefined, 't'), false);
});

test('batchSerial: intentional-revert, untrusted author or an earlier red batch keep a PR out of batches', () => {
  const trusted = [...TRUSTED_AUTHORS][0];
  assert.equal(batchSerial({ authorAssociation: trusted, labels: ['queue'] }, false), false);
  assert.equal(batchSerial({ authorAssociation: trusted, labels: ['queue', 'intentional-revert'] }, false), true);
  assert.equal(batchSerial({ authorAssociation: trusted, labels: ['queue'] }, true), true); // red batch is not rebuilt with it
  assert.equal(batchSerial({ authorAssociation: 'NONE', labels: [] }, false), true);
  assert.equal(batchSerial({ authorAssociation: trusted }, false), false);
});

test('guardedSquash: re-reads threads right before squash; only a known 0 merges (serial and batch)', () => {
  const run = (reading) => {
    const calls = [];
    const logs = [];
    const did = guardedSquash(7, 'abc', (n) => { calls.push(['read', n]); return reading; }, () => ({ ok: true }), (n, sha) => calls.push(['squash', n, sha]), (m) => logs.push(m));
    return { did, calls, logs };
  };
  const ok = run(0);
  assert.equal(ok.did, true);
  assert.deepEqual(ok.calls, [['read', 7], ['squash', 7, 'abc']]); // read happens before squash
  for (const reading of [1, 3, null, undefined]) {
    const r = run(reading);
    assert.equal(r.did, false);
    assert.deepEqual(r.calls, [['read', 7]]); // never squashed
    assert.equal(r.logs.length, 1);
    assert.match(r.logs[0], /^#7: review threads (\d+ open|unknown) right before squash; not merging$/);
  }
});

// ---- Final-head review gate (Founder 2026-10-21: green CI alone must not merge an unreviewed head) ----
const H = 'a'.repeat(40);
const OLD = 'b'.repeat(40);
const OWNER = 'jerry200176-png';
const codex = (cr, o = {}) => ({ user: { login: CODEX_LOGIN }, updated_at: '2026-10-21T05:00:00Z', body: `<!-- codex-pull-request-review-summary -->\n<!-- codex-review-state:v1 ${JSON.stringify({ code_review: cr, security_review: { commit_sha: null, status: null } })} -->\n## Codex Review Summary`, ...o });
const clean = (sha = H, o = {}) => ({ commit_sha: sha, has_any_findings: false, has_unresolved_findings: false, status: 'completed', ...o });
const fallback = (sha, login = OWNER) => ({ user: { login }, body: `independent /code-review: no findings\n${reviewMarker(sha)}` });
const gateOf = (comments) => reviewGate(comments, H, OWNER);
const decideWith = (comments) => decide(pr({ sha: H, review: gateOf(comments) }), req);

test('review gate: green + 0 threads + Codex running -> wait review-pending', () => {
  const d = decideWith([codex(clean(H, { status: 'running', has_unresolved_findings: null }))]);
  assert.equal(d.action, 'wait');
  assert.equal(d.reason, 'review-pending');
  assert.match(d.text, new RegExp(H));
  assert.equal(decideWith([]).reason, 'review-pending'); // Codex never ran
});

test('review gate: Codex completed clean on an older SHA -> wait', () => {
  const g = gateOf([codex(clean(OLD))]);
  assert.equal(g.ok, false);
  assert.match(g.why, /not head/);
  assert.equal(decideWith([codex(clean(OLD))]).reason, 'review-pending');
});

test('review gate: completed with unresolved findings on head -> wait, and vetoes the fallback marker', () => {
  const findings = codex(clean(H, { has_any_findings: true, has_unresolved_findings: true }));
  assert.equal(decideWith([findings]).action, 'wait');
  assert.match(gateOf([findings]).why, /unresolved findings/);
  assert.equal(gateOf([findings, fallback(H)]).ok, false);
  // completed but findings flag missing is unknown -> also a veto
  assert.equal(gateOf([codex(clean(H, { has_unresolved_findings: null })), fallback(H)]).ok, false);
});

test('review gate: Codex completed clean on head -> merge (latest summary wins)', () => {
  assert.deepEqual(gateOf([codex(clean())]), { ok: true, via: 'codex' });
  assert.equal(decideWith([codex(clean())]).action, 'merge');
  const older = codex(clean(OLD), { updated_at: '2026-10-21T04:00:00Z' });
  assert.equal(gateOf([codex(clean()), older]).ok, true);
  // HTML-entity encoded state (e.g. &#123;) is decoded before parsing
  const enc = codex(clean());
  enc.body = enc.body.replace(/\{/g, '&#123;').replace(/\}/g, '&#125;').replace(/"/g, '&quot;');
  assert.equal(gateOf([enc]).ok, true);
  assert.equal(decodeEntities('&#123;&#x7d;&quot;&amp;'), '{}"&');
});

test('review gate: fallback marker by the owner with the exact head SHA -> merge', () => {
  assert.deepEqual(gateOf([fallback(H)]), { ok: true, via: 'independent-review' });
  assert.equal(decideWith([codex(clean(OLD)), fallback(H)]).action, 'merge'); // Codex stale/rate-limited
  assert.equal(gateOf([fallback(H, 'JERRY200176-PNG')]).ok, true); // logins are case-insensitive
});

test('review gate: incomplete current-head Codex cannot be overridden by an owner fallback', () => {
  for (const status of ['running', 'queued', 'pending', 'cancelled', 'failed', 'unknown', null, undefined]) {
    const comments = [codex(clean(H, { status, has_unresolved_findings: null })), fallback(H)];
    assert.equal(gateOf(comments).ok, false, String(status));
    assert.equal(decideWith(comments).reason, 'review-pending', String(status));
  }
  assert.equal(gateOf([codex(clean(H, { status: 'running' })), fallback(H)]).ok, false);
  // A rate-limit notice without an on-head review state does not erase an actual independent review.
  assert.equal(gateOf([{ user: { login: CODEX_LOGIN }, body: 'Code review could not start: rate limit exceeded.' }, fallback(H)]).ok, true);
});

test('review gate: fallback marker with a mismatched SHA or another author -> wait', () => {
  assert.equal(decideWith([fallback(OLD)]).reason, 'review-pending');
  assert.equal(gateOf([fallback(H.slice(0, 39) + 'b')]).ok, false);
  assert.equal(gateOf([fallback(H, 'someone-else')]).ok, false);
  assert.equal(gateOf([fallback(H, 'github-actions[bot]')]).ok, false);
  assert.equal(gateOf([{ user: { login: OWNER }, body: `<!-- independent-review:v1 sha=${H} verdict=fail -->` }]).ok, false);
  // A Codex-looking state from a non-Codex author is ignored.
  assert.equal(gateOf([{ ...codex(clean()), user: { login: OWNER } }]).ok, false);
});

test('review gate: malformed / unknown never merges (fail closed)', () => {
  const bad = codex(clean());
  bad.body = '<!-- codex-review-state:v1 {"code_review": {oops -->';
  assert.match(gateOf([bad]).why, /unreadable/);
  assert.equal(decideWith([bad]).action, 'wait');
  assert.equal(gateOf([bad, fallback(H)]).ok, false); // malformed state is not overridden
  assert.equal(gateOf([codex(null)]).ok, false); // code_review missing
  assert.equal(reviewGate(null, H, OWNER).ok, false); // comments unreadable
  assert.equal(reviewGate([codex(clean())], undefined, OWNER).ok, false);
  assert.equal(reviewGate([codex(clean(H.slice(0, 7)))], H.slice(0, 7), OWNER).ok, false); // short SHA
  assert.equal(reviewGate([codex(clean(H.toUpperCase()))], H.toUpperCase(), OWNER).ok, false);
  for (const review of [undefined, null, {}, { ok: 'true' }, { ok: false, why: 'x' }]) assert.equal(decide(pr({ review }), req).action, 'wait');
  // Review-pending never strips the label and never outranks a real rejection.
  assert.equal(decide(pr({ review: undefined, unresolvedThreads: 1 }), req).reason, 'threads');
  assert.equal(decide(pr({ review: undefined, mergeStateStatus: 'BLOCKED' }), req).reason, undefined);
});

test('review gate: malformed structured Codex identity vetoes owner fallback', () => {
  for (const commit_sha of [undefined, null, '', 'not-a-sha', H.slice(0, 7), H.toUpperCase(), 42]) {
    for (const state of [clean(commit_sha, { status: 'running' }), clean(commit_sha, { has_unresolved_findings: true })]) {
      // clean() defaults undefined to H; explicitly exercise an absent property.
      if (commit_sha === undefined) delete state.commit_sha;
      const comments = [codex(state), fallback(H)];
      assert.equal(gateOf(comments).ok, false, `invalid native SHA ${String(commit_sha)}`);
      assert.equal(decideWith(comments).action, 'wait');
    }
  }
  assert.equal(gateOf([codex([]), fallback(H)]).ok, false);
  assert.equal(gateOf([codex(clean(OLD)), fallback(H)]).ok, true, 'valid stale identity still allows fallback');
});

test('review gate: a later stale summary never erases the latest current-head review', () => {
  const stale = codex(clean(OLD), { updated_at: '2026-10-21T07:00:00Z' });
  const fresh = codex(clean(), { updated_at: '2026-10-21T06:00:00Z' });
  for (const state of [clean(H, { status: 'running', has_unresolved_findings: null }), clean(H, { has_unresolved_findings: true })]) {
    const current = codex(state);
    for (const native of [[current, stale], [stale, current]]) {
      assert.equal(gateOf([...native, fallback(H)]).ok, false, state.status);
      assert.equal(decideWith([...native, fallback(H)]).reason, 'review-pending');
      assert.deepEqual(gateOf([...native, fresh, fallback(H)]), { ok: true, via: 'codex' }, 'newer current-head clean may supersede an older current review');
    }
  }
  assert.deepEqual(gateOf([fresh, stale, fallback(H)]), { ok: true, via: 'codex' });
});

test('review gate: later stale summaries cannot hide malformed structured evidence', () => {
  const stale = codex(clean(OLD), { updated_at: '2026-10-21T07:00:00Z' });
  const missing = clean(); delete missing.commit_sha;
  const malformed = [codex(missing), codex([]), codex(clean('not-a-sha')),
    codex(clean(), { body: '<!-- codex-review-state:v1 {"code_review": {oops -->' }),
    codex(clean(), { body: '<!-- codex-review-state:v1 {"code_review":' })];
  for (const bad of malformed) {
    for (const native of [[bad, stale], [stale, bad]]) {
      assert.equal(gateOf([...native, fallback(H)]).ok, false);
      assert.equal(decideWith([...native, fallback(H)]).reason, 'review-pending');
    }
  }
});

test('review gate: ambiguous current summaries and unknown timestamps fail closed regardless of order', () => {
  const cleanCurrent = codex(clean());
  const stale = codex(clean(OLD), { updated_at: '2026-10-21T07:00:00Z' });
  for (const state of [clean(H, { status: 'running' }), clean(H, { has_unresolved_findings: true })]) {
    const conflicting = codex(state); // same update time as cleanCurrent
    for (const pair of [[cleanCurrent, conflicting], [conflicting, cleanCurrent]]) {
      assert.equal(gateOf([...pair, stale, fallback(H)]).ok, false);
    }
  }
  for (const updated_at of [undefined, '', 'invalid', '2026-02-30T05:00:00Z']) {
    assert.equal(gateOf([codex(clean(), { updated_at }), stale, fallback(H)]).ok, false, String(updated_at));
  }
  const duplicate = codex(clean(), { body: cleanCurrent.body + '\n' + stale.body });
  assert.equal(gateOf([duplicate, fallback(H)]).ok, false, 'multiple markers are ambiguous');
  assert.deepEqual(gateOf([cleanCurrent, codex(clean()), stale]), { ok: true, via: 'codex' }, 'equivalent duplicate evidence agrees');
  const created = codex(clean(), { updated_at: undefined, created_at: '2026-10-21T05:00:00Z' });
  assert.deepEqual(gateOf([created, stale]), { ok: true, via: 'codex' }, 'created_at supplies a known timestamp');
});

test('guardedSquash: re-reads the final-head review for the exact SHA before squash (serial and batch)', () => {
  for (const [review, did] of [[{ ok: true }, true], [{ ok: false, why: 'Codex review running' }, false], [null, false], [undefined, false]]) {
    const calls = [];
    const r = guardedSquash(7, H, () => 0, (n, sha) => { calls.push(['review', n, sha]); return review; }, (n, sha) => calls.push(['squash', n, sha]), () => {});
    assert.equal(r, did);
    assert.deepEqual(calls, did ? [['review', 7, H], ['squash', 7, H]] : [['review', 7, H]]);
  }
  // Batch eligibility (neutral) honors the review gate too.
  assert.equal(decide(neutral(pr({ mergeStateStatus: 'BEHIND', behindBy: 2, review: { ok: false } })), req).action, 'wait');
});

test('review gate workflows: default-branch script only; auto-merge waits on the required check', () => {
  const wf = fs.readFileSync(new URL('../../.github/workflows/review-gate.yml', import.meta.url), 'utf8');
  assert.match(wf, /ref: \$\{\{ github\.event\.repository\.default_branch \}\}/);
  assert.doesNotMatch(wf, /head\.sha|head\.ref|pull_request\.head/);
  assert.match(wf, /run: node \.github\/scripts\/review-gate\.mjs/);
  assert.match(wf, /checks: write/);
  assert.match(wf, /if: \$\{\{ steps\.installation\.outputs\.installed == 'true' \}\}/);
  const am = fs.readFileSync(new URL('../../.github/workflows/auto-merge-safe.yml', import.meta.url), 'utf8');
  assert.ok(am.includes(`index("${REVIEW_CHECK}")`));
  assert.ok(am.indexOf(REVIEW_CHECK) < am.indexOf('gh pr merge "$PR_NUMBER"'));
  const lq = fs.readFileSync(new URL('../../.github/scripts/land-queue.mjs', import.meta.url), 'utf8');
  assert.match(lq, /filter\(\(c\) => c !== REVIEW_CHECK\)/); // batch branches have no PR review; gated per member
});

test('review publisher bootstrap never substitutes for a missing required verdict', async () => {
  const { spawnSync } = await import('node:child_process');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');
  const wf = fs.readFileSync(new URL('../../.github/workflows/review-gate.yml', import.meta.url), 'utf8');
  const step = wf.split('      - name: Check default-branch publisher installation\n')[1]?.split('      - name: Evaluate final-head review\n')[0];
  assert.ok(step, 'installation is checked before any evaluation');
  const script = step.split('        run: |\n')[1].replace(/^ {10}/gm, '');
  const dir = fs.mkdtempSync(join(tmpdir(), 'review-bootstrap-'));
  try {
    fs.mkdirSync(join(dir, 'bin'));
    fs.mkdirSync(join(dir, '.github/scripts'), { recursive: true });
    fs.writeFileSync(join(dir, 'bin/gh'), '#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALLS"\ncat "$RULES_FIXTURE"\nexit "$API_RC"\n', { mode: 0o755 });
    const run = (rules, apiRc = 0, present = false) => {
      const publisher = join(dir, '.github/scripts/review-gate.mjs');
      if (present) fs.writeFileSync(publisher, 'throw Error("must not execute in bootstrap");');
      else fs.rmSync(publisher, { force: true });
      fs.writeFileSync(join(dir, 'rules'), typeof rules === 'string' ? rules : JSON.stringify(rules));
      fs.writeFileSync(join(dir, 'out'), ''); fs.writeFileSync(join(dir, 'calls'), '');
      const result = spawnSync('bash', ['-e', '-c', script], { cwd: dir, encoding: 'utf8',
        env: { PATH: `${join(dir, 'bin')}:${process.env.PATH}`, RUNNER_TEMP: dir, REPO: 'o/r',
          RULES_FIXTURE: join(dir, 'rules'), API_RC: String(apiRc), CALLS: join(dir, 'calls'), GITHUB_OUTPUT: join(dir, 'out') } });
      return { ...result, output: fs.readFileSync(join(dir, 'out'), 'utf8'), calls: fs.readFileSync(join(dir, 'calls'), 'utf8') };
    };
    const installed = run(null, 7, true);
    assert.equal(installed.status, 0); assert.equal(installed.output, 'installed=true\n'); assert.equal(installed.calls, '');
    for (const rules of [[], [{ type: 'pull_request' }], [{ type: 'required_status_checks', parameters: { required_status_checks: [{ context: 'CI' }] } }]]) {
      const result = run(rules);
      assert.equal(result.status, 0); assert.equal(result.output, 'installed=false\n');
      assert.match(result.stdout, /not installed.*no review verdict published/);
      assert.equal(result.calls, 'api repos/o/r/rules/branches/main\n');
    }
    for (const rules of [null, {}, '{', [{}], [{ type: 'required_status_checks' }],
      [{ type: 'required_status_checks', parameters: { required_status_checks: {} } }],
      [{ type: 'required_status_checks', parameters: { required_status_checks: [{}] } }],
      [{ type: 'required_status_checks', parameters: { required_status_checks: [{ context: REVIEW_CHECK, integration_id: 99 }] } }]]) {
      const result = run(rules);
      assert.notEqual(result.status, 0); assert.equal(result.output, '', 'unknown or pinned gate cannot bootstrap');
      assert.equal(result.calls, 'api repos/o/r/rules/branches/main\n', 'only a rules read is authorized');
    }
    const failed = run([], 7);
    assert.notEqual(failed.status, 0); assert.equal(failed.output, '');
    assert.equal(fs.readdirSync(dir).filter((name) => name.startsWith('review-gate-rules.')).length, 0);
    assert.doesNotMatch(script, /check-runs|statuses|node /);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

test('review gate publications serialize sweep and PR events across refs without dropping pending runs', () => {
  const wf = fs.readFileSync(new URL('../../.github/workflows/review-gate.yml', import.meta.url), 'utf8');
  const concurrency = wf.match(/^concurrency:\n((?:  [^\n]*\n)+)/m)?.[1];
  assert.ok(concurrency, 'all check publishers need one workflow lock');
  const group = concurrency.match(/^  group:\s*(\S[^\n]*)$/m)?.[1];
  assert.ok(group);
  for (const varyingContext of ['github.event', 'github.run_id', 'github.run_attempt', 'github.ref', 'github.sha']) {
    assert.ok(!group.includes(varyingContext), `concurrent publishers must not split by ${varyingContext}`);
  }
  assert.match(concurrency, /^  cancel-in-progress:\s*false\s*$/m);
  assert.match(concurrency, /^  queue:\s*max\s*$/m);
});

test('review gate refreshes withdrawn evidence through the deleted issue-comment event', () => {
  const wf = fs.readFileSync(new URL('../../.github/workflows/review-gate.yml', import.meta.url), 'utf8');
  const types = wf.match(/\n  issue_comment:\n    types: \[([^\]]+)\]/)?.[1].split(',').map((s) => s.trim());
  assert.deepEqual(types?.sort(), ['created', 'deleted', 'edited']);
});

test('canonical merge policy and SOP describe the same final-head review gate', () => {
  const policy = fs.readFileSync(new URL('../../docs/governance/RISK_BASED_MERGE_POLICY.md', import.meta.url), 'utf8');
  const sop = fs.readFileSync(new URL('../../docs/sop/MERGE_SOP.md', import.meta.url), 'utf8');
  for (const [name, doc] of [['policy', policy], ['SOP', sop]]) {
    assert.ok(doc.includes(REVIEW_CHECK), `${name} must identify the gate native auto-merge waits on`);
    assert.match(doc, /review-pending/, `${name} must describe pending review as a wait`);
    assert.match(doc, /approval continuity/i, `${name} must preserve existing scoped approvals`);
  }
});
