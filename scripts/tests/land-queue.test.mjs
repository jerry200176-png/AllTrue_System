import assert from 'node:assert/strict';
import test from 'node:test';
import { BATCH_MAX, TRUSTED_AUTHORS, fromPinned, memberOf, staleReason, batchVerdict, checkStates, decide, marker, memberMessage, orderQueue, parseMember, planBatch } from '../../.github/scripts/land-queue.mjs';

const req = ['A', 'B'];
const ok = [{ name: 'A', conclusion: 'SUCCESS' }, { name: 'B', conclusion: 'SUCCESS' }];
const pr = (o = {}) => ({ mergeStateStatus: 'CLEAN', rollup: ok, ...o });

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

test('decide: unresolved review threads do not gate; pending or missing checks wait', () => {
  assert.equal(decide(pr({ unresolvedThreads: 2 }), req).action, 'merge');
  assert.equal(decide(pr({ rollup: [ok[0], { name: 'B', status: 'IN_PROGRESS', conclusion: null }] }), req).action, 'wait');
  assert.equal(decide(pr({ rollup: [ok[0]] }), req).action, 'wait');
  assert.equal(decide(pr({ mergeStateStatus: 'BLOCKED' }), req).action, 'wait');
});

test('decide: CLEAN and all green merges; helpers', () => {
  assert.equal(decide(pr(), req).action, 'merge');
  assert.deepEqual(checkStates([], ['A']), { A: 'pending' });
  assert.equal(marker('conflict', 'abc'), '<!-- land-queue:conflict:abc -->');
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

test('fromPinned: a pinned context counts only from its app; unpinned matches by name like the ruleset', () => {
  const run = (id, name = 'A') => ({ __typename: 'CheckRun', name, conclusion: 'SUCCESS', checkSuite: { app: { databaseId: id } } });
  const pins = new Map([['A', 15368], ['U', null]]);
  const kept = fromPinned([run(15368), run(999), { __typename: 'StatusContext', context: 'A', state: 'SUCCESS' }, { __typename: 'CheckRun', name: 'A' }], pins);
  assert.equal(kept.length, 1);
  assert.deepEqual(checkStates(fromPinned([run(999)], pins), ['A']), { A: 'pending' });
  assert.equal(fromPinned([run(999, 'U'), { __typename: 'StatusContext', context: 'U', state: 'SUCCESS' }], pins).length, 2);
  assert.deepEqual(checkStates(fromPinned([run(999)], new Map([['A', 999]])), ['A']), { A: 'pass' }); // repinned in the ruleset
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
