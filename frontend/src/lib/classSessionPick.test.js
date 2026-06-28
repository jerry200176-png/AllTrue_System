import assert from 'node:assert/strict';
import { dedupeSessionsByStudentSlot, pickBestSessionRow, resolveSessionIdForSubstitute } from './classSessionPick.js';

const rows = [
  { id: 9421, session_date: '2026-05-17', start_time: '10:00', status: 'attended' },
  { id: 9427, session_date: '2026-05-17', start_time: '12:30', status: 'scheduled' },
];

assert.equal(
  resolveSessionIdForSubstitute(rows, '2026-05-17', '12:30'),
  9427,
  'substitute must target the cell start_time, not the attended row'
);

assert.equal(
  resolveSessionIdForSubstitute(rows, '2026-05-17', ''),
  9421,
  'without start hint, attended row still wins'
);

assert.equal(
  resolveSessionIdForSubstitute(
    [
      { id: 1, session_date: '2026-05-17', start_time: '12:30:00', status: 'scheduled' },
      { id: 2, session_date: '2026-05-17', start_time: '10:00:00', status: 'attended' },
    ],
    '2026-05-17',
    '12:30'
  ),
  1,
  'HH:mm:ss start times normalize correctly'
);

assert.equal(
  pickBestSessionRow([
    { id: 10, status: 'scheduled' },
    { id: 20, status: 'attended' },
  ]).id,
  20,
  'pickBestSessionRow prefers attended when no time hint'
);

const deduped = dedupeSessionsByStudentSlot([
  { id: 100, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'scheduled', learning_record_status: 'missing' },
  { id: 200, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'attended', learning_record_status: 'approved' },
]);
assert.equal(deduped.length, 1, 'same student slot collapses to one row');
assert.equal(deduped[0].id, 200, 'attended session wins over scheduled duplicate');
