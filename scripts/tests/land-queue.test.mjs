import assert from 'node:assert/strict';
import test from 'node:test';
import { checkStates, decide, marker, orderQueue } from '../../.github/scripts/land-queue.mjs';

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

test('decide: unresolved threads never block (advisory), pending or missing checks wait', () => {
  assert.notEqual(decide(pr({ unresolvedThreads: 2 }), req).reason, 'threads');
  assert.equal(decide(pr({ rollup: [ok[0], { name: 'B', status: 'IN_PROGRESS', conclusion: null }] }), req).action, 'wait');
  assert.equal(decide(pr({ rollup: [ok[0]] }), req).action, 'wait');
  assert.equal(decide(pr({ mergeStateStatus: 'BLOCKED' }), req).action, 'wait');
});

test('decide: CLEAN and all green merges; helpers', () => {
  assert.equal(decide(pr(), req).action, 'merge');
  assert.deepEqual(checkStates([], ['A']), { A: 'pending' });
  assert.equal(marker('conflict', 'abc'), '<!-- land-queue:conflict:abc -->');
});
