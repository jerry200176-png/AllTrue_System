import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildContractChronology } from './contractChronology.js';

const row = (id, first_class_date, history_reason = null, extra = {}) => ({ id, first_class_date, history_reason, ...extra });
const ids = (segments) => segments.flatMap((segment) => segment.courses.map((course) => course.id ?? course.ID));

test('#5124 orders the entire chain before making contiguous working/history segments', () => {
  const segments = buildContractChronology([
    row(5, '2026-05-01'), row(4, '2026-04-01', 'settled'), row(3, '2026-03-01'),
    row(2, '2026-02-01', 'completed'), row(1, '2026-01-01'),
  ]);
  assert.deepEqual(ids(segments), [1, 2, 3, 4, 5]);
  assert.deepEqual(segments.map((segment) => segment.kind), ['working', 'history', 'working', 'history', 'working']);
  assert.equal(new Set(segments.map((segment) => segment.key)).size, 5);
});

test('same-day ID ties, legacy StartDate and missing dates are deterministic and do not mutate input', () => {
  const courses = [
    row(9, ''), row(12, '2026-07-02'), row(3, null, null, { StartDate: '2026-07-02 00:00:00' }),
    row(2, undefined), row(5, '2026-01-01'),
  ].map(Object.freeze);
  Object.freeze(courses);
  const snapshot = JSON.stringify(courses);
  assert.deepEqual(ids(buildContractChronology(courses)), [5, 3, 12, 2, 9]);
  assert.equal(JSON.stringify(courses), snapshot);
});

test('pending reconciliation remains working, adjacent same-kind contracts share a segment, empty stays empty', () => {
  const pending = row(2, '2026-02-01', 'settled_pending');
  const segments = buildContractChronology([row(3, '2026-03-01'), pending, row(1, '2026-01-01', 'settled')]);
  assert.deepEqual(segments.map((segment) => segment.courses.map((course) => course.id)), [[1], [2, 3]]);
  assert.equal(segments[1].kind, 'working');
  assert.equal(segments[1].courses[0], pending);
  assert.deepEqual(buildContractChronology([]), []);
});
