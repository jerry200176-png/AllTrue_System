import assert from 'node:assert/strict';
import test from 'node:test';
import { alarmFindings, decideStale, failStreak, queuedSince, STALE_DAYS, CLOSE_DRAFT_DAYS, EXEMPT_LABELS } from '../../.github/scripts/repo-hygiene.mjs';

const NOW = Date.parse('2026-10-21T00:00:00Z');
const ago = (d) => new Date(NOW - d * 86400e3).toISOString();
const pr = (o = {}) => ({ user: { login: 'o', type: 'User' }, base: { ref: 'main' }, labels: [], draft: false, ...o });

test('stale: labels at the threshold, not before; boundaries follow the constants', () => {
  assert.equal(decideStale(pr(), ago(STALE_DAYS - 1), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr(), ago(STALE_DAYS), NOW, 'o').action, 'label');
  assert.equal(decideStale(pr({ labels: [{ name: 'stale' }] }), ago(STALE_DAYS + 1), NOW, 'o').action, 'none');
});

test('stale: only drafts are ever closed; non-draft never', () => {
  assert.equal(decideStale(pr({ draft: true }), ago(CLOSE_DRAFT_DAYS), NOW, 'o').action, 'close');
  assert.equal(decideStale(pr({ draft: true }), ago(CLOSE_DRAFT_DAYS - 1), NOW, 'o').action, 'label');
  for (const d of [30, 365]) assert.notEqual(decideStale(pr(), ago(d), NOW, 'o').action, 'close');
});

test('stale: other authors, bots, non-main base and exempt labels are untouched', () => {
  assert.equal(decideStale(pr({ user: { login: 'human', type: 'User' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr({ user: { login: 'o', type: 'Bot' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  assert.equal(decideStale(pr({ base: { ref: 'dev' }, draft: true }), ago(99), NOW, 'o').action, 'none');
  for (const l of EXEMPT_LABELS) assert.equal(decideStale(pr({ labels: [{ name: l }], draft: true }), ago(99), NOW, 'o').action, 'none', l);
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
