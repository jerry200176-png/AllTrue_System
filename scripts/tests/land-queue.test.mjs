import assert from 'node:assert/strict';
import test from 'node:test';
import { BATCH_MAX, batchVerdict, checkStates, decide, marker, memberMessage, orderQueue, parseMember, planBatch } from '../../.github/scripts/land-queue.mjs';

const req = ['A', 'B'];
const ok = [{ name: 'A', conclusion: 'SUCCESS' }, { name: 'B', conclusion: 'SUCCESS' }];
const pr = (o = {}) => ({ mergeStateStatus: 'CLEAN', unresolvedThreads: 0, rollup: ok, ...o });

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

test('decide: unresolved threads reject, pending or missing checks wait', () => {
  assert.equal(decide(pr({ unresolvedThreads: 2 }), req).reason, 'threads');
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
