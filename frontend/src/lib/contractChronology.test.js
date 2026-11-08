import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildContractChronology } from './contractChronology.js';

const row = (id, first_class_date, history_reason = null, extra = {}) => ({ id, first_class_date, history_reason, ...extra });
const ids = (segments) => segments.flatMap((segment) => segment.courses.map((course) => course.id ?? course.ID));

test('#527 orders contracts newest-first before making contiguous working/history segments', () => {
  const segments = buildContractChronology([
    row(5, '2026-05-01'), row(4, '2026-04-01', 'settled'), row(3, '2026-03-01'),
    row(2, '2026-02-01', 'completed'), row(1, '2026-01-01'),
  ]);
  assert.deepEqual(ids(segments), [5, 4, 3, 2, 1]);
  assert.deepEqual(segments.map((segment) => segment.kind), ['working', 'history', 'working', 'history', 'working']);
  assert.equal(new Set(segments.map((segment) => segment.key)).size, 5);
});

test('contract IDs determine order regardless of same-day, legacy or missing starts without mutating input', () => {
  const courses = [
    row(9, ''), row(12, '2026-07-02'), row(3, null, null, { StartDate: '2026-07-02 00:00:00' }),
    row(2, undefined), row(5, '2026-01-01'),
  ].map(Object.freeze);
  Object.freeze(courses);
  const snapshot = JSON.stringify(courses);
  assert.deepEqual(ids(buildContractChronology(courses)), [12, 9, 5, 3, 2]);
  assert.equal(JSON.stringify(courses), snapshot);
});

test('pending reconciliation remains working, adjacent same-kind contracts share a segment, empty stays empty', () => {
  const pending = row(2, '2026-02-01', 'settled_pending');
  const segments = buildContractChronology([row(3, '2026-03-01'), pending, row(1, '2026-01-01', 'settled')]);
  assert.deepEqual(segments.map((segment) => segment.courses.map((course) => course.id)), [[3, 2], [1]]);
  assert.equal(segments[0].kind, 'working');
  assert.equal(segments[0].courses[1], pending);
  assert.deepEqual(buildContractChronology([]), []);
});

test('#527 a newly created backdated contract precedes older contracts with later declared starts', () => {
  const backdated = row(30, '2025-01-01');
  const sameId = row(30, '2027-01-01');
  const missingId = { first_class_date: '2028-01-01' };
  const segments = buildContractChronology([
    row(10, '2026-12-01'), { ID: 20, StartDate: '2026-06-01' }, backdated, sameId, missingId,
  ]);
  const ordered = segments.flatMap((segment) => segment.courses);
  assert.deepEqual(ids(segments), [30, 30, 20, 10, undefined]);
  assert.equal(ordered[0], backdated);
  assert.equal(ordered[1], sameId);
  assert.equal(ordered[4], missingId);
});
