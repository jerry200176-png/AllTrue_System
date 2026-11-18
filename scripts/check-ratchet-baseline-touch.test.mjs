import assert from 'node:assert/strict';
import test from 'node:test';
import { findViolations, frozenListViolations, nightlyPinViolations } from './check-ratchet-baseline-touch.mjs';

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

const G = 'backend/tests/Fixtures/write_routes_grandfathered.txt';
test('frozen route list: deleting lines is allowed on any branch', () => {
  assert.deepEqual(frozenListViolations([{ path: G, before: '# head\nPOST a\nPOST b\n', after: '# head\nPOST a\n' }]), []);
});
test('frozen route list: adding a line is rejected, even when another line is deleted', () => {
  const v = frozenListViolations([{ path: G, before: '# head\nPOST a\nPOST b\n', after: '# head\nPOST a\nPOST c\n' }]);
  assert.equal(v.length, 1);
  assert.match(v[0], /frozen.*POST c/);
});
test('frozen route list: comments, blank lines and reordering do not count', () => {
  assert.deepEqual(frozenListViolations([{ path: G, before: 'POST a\nPOST b\n', after: '# note\n\nPOST b\nPOST a\n' }]), []);
});
test('frozen route list: the introducing PR (absent at base) is skipped; other paths ignored', () => {
  assert.deepEqual(frozenListViolations([{ path: G, before: null, after: 'POST a\n' }]), []);
  assert.deepEqual(frozenListViolations([{ path: 'other.txt', before: 'a\n', after: 'a\nb\n' }]), []);
});
test('frozen route list: CRLF and trailing spaces are not new lines; a case change is', () => {
  assert.deepEqual(frozenListViolations([{ path: G, before: 'POST a\nPOST b\n', after: 'POST a \r\nPOST b\r\n' }]), []);
  assert.equal(frozenListViolations([{ path: G, before: 'POST a\n', after: 'post a\n' }]).length, 1);
});

const pins = (enforced, allowances) => JSON.stringify({ enforcedReproductions: enforced, reproductionBaselines: allowances });
test('nightly pins: unpinning an enforced condition fails; adding one is fine', () => {
  assert.equal(nightlyPinViolations(pins(['a', 'b'], {}), pins(['a'], {})).length, 1);
  assert.deepEqual(nightlyPinViolations(pins(['a'], {}), pins(['a', 'c'], {})), []);
});
test('nightly pins: an allowance may shrink or go away, never rise or turn null', () => {
  assert.deepEqual(nightlyPinViolations(pins([], { x: 5 }), pins([], { x: 3 })), []);
  assert.deepEqual(nightlyPinViolations(pins([], { x: 5 }), pins([], {})), []);
  assert.equal(nightlyPinViolations(pins([], { x: 5 }), pins([], { x: 6 })).length, 1);
  assert.equal(nightlyPinViolations(pins([], { x: 5 }), pins([], { x: null })).length, 1);
  assert.deepEqual(nightlyPinViolations(pins([], { x: null }), pins([], { x: 4 })), []);
  assert.deepEqual(nightlyPinViolations(null, pins([], { x: 9 })), []);
});
test('nightly pins: an enforced condition with no listed allowance may not gain one (Codex P2)', () => {
  assert.equal(nightlyPinViolations(pins(['a'], {}), pins(['a'], { a: 3 })).length, 1);
  assert.equal(nightlyPinViolations(pins(['a'], {}), pins(['a'], { a: null })).length, 1);
  assert.deepEqual(nightlyPinViolations(pins(['a'], {}), pins(['a'], { a: 0 })), []);
  assert.deepEqual(nightlyPinViolations(pins([], {}), pins(['n'], { n: 4 })), [], 'newly enforced in this PR: FIT-20 asks for the reason');
});
