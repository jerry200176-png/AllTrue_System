import assert from 'node:assert/strict';
import test from 'node:test';
import { aggregate, render, RUNS_PER_DAY_FLAG, WINDOW_DAYS } from '../../.github/scripts/actions-cost-report.mjs';

const run = (name, min, conclusion = 'success') => ({ name, run_started_at: '2026-10-20T00:00:00Z', updated_at: new Date(Date.parse('2026-10-20T00:00:00Z') + min * 60000).toISOString(), conclusion });

test('aggregate: sums runs and minutes per workflow, sorted by minutes', () => {
  const rows = aggregate([run('A', 2), run('A', 3, 'failure'), run('B', 10), run('B', 0, 'cancelled')]);
  assert.deepEqual(rows.map((r) => [r.name, r.runs, Math.round(r.minutes), r.failed, r.cancelled]), [['B', 2, 10, 0, 1], ['A', 2, 5, 1, 0]]);
});

test('aggregate: flag threshold follows the parameters', () => {
  const runs = Array.from({ length: 7 * 10 }, () => run('Q', 1)); // 10/day
  assert.equal(aggregate(runs)[0].flagged, false);
  assert.equal(aggregate(runs, { flagPerDay: 9 })[0].flagged, true);
  assert.equal(aggregate(runs, { days: 1, flagPerDay: RUNS_PER_DAY_FLAG })[0].flagged, true); // 70/day over a 1-day window
  assert.equal(WINDOW_DAYS > 0, true);
});

test('render: names flagged workflows and the spending-limit note; handles missing timestamps', () => {
  const rows = aggregate([run('land-queue', 1), { name: 'x', conclusion: 'success' }], { days: 1, flagPerDay: 0 });
  const md = render(rows, { date: '2026-10-21', days: 1, flagPerDay: 0 });
  assert.match(md, /\*\*land-queue\*\*/);
  assert.match(md, /spending limit/);
  assert.match(md, /<!-- actions-cost-report -->/);
});
