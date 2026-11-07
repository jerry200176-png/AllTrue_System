import assert from 'node:assert/strict';
import test from 'node:test';
import { findViolations } from './check-ratchet-baseline-touch.mjs';

const P = 'docs/design-style-baseline.json';
const j = (n) => JSON.stringify({ totals: { a: 999 }, files: { x: { a: n } } });

test('feature branch lowering counts is rejected with the weekly-PR message', () => {
  const v = findViolations([{ path: P, before: j(5), after: j(3) }], 'feat/x');
  assert.equal(v.length, 1);
  assert.match(v[0], /lock in gains via the weekly baseline PR/);
});
test('unchanged-sum rewrite is rejected', () => {
  assert.equal(findViolations([{ path: P, before: j(5), after: j(5).replace(' ', '') + '\n' }], 'fix/x').length, 1);
});
test('counts going up is allowed', () => {
  assert.deepEqual(findViolations([{ path: P, before: j(5), after: j(7) }], 'feat/x'), []);
});
test('chore branch may rewrite', () => {
  assert.deepEqual(findViolations([{ path: P, before: j(5), after: j(3) }], 'chore/ratchet-baseline-weekly'), []);
});
test('untouched or unlisted files are ignored', () => {
  assert.deepEqual(findViolations([{ path: P, before: j(5), after: j(5) }], 'feat/x'), []);
  assert.deepEqual(findViolations([{ path: 'other.json', before: '1', after: '0' }], 'feat/x'), []);
});
test('a pure file rename (same per-file counts, new key) is allowed', () => {
  const f = (k) => JSON.stringify({ files: { [k]: { a: 5 }, y: { a: 2 } } });
  assert.deepEqual(findViolations([{ path: P, before: f('x'), after: f('moved') }], 'feat/x'), []);
});
