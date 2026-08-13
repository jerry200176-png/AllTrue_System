import assert from 'node:assert/strict';
import {
  dedupeSessionsByStudentSlot,
  pickBestSessionRow,
  resolveSessionIdForSubstitute,
  resolveSessionRowForCell,
} from './classSessionPick.js';

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

// in-app #224: a manually-booked session (逐堂手動排課, #211) can have a
// start_time that differs from the course's default/contract start_time.
// findSessionRowForCell() in SmartCalendar.vue must still find it (via this
// resolver) so 取消本堂/roll-call/eval badges stay available — not just the
// grid-render path, which never had this restriction.
assert.equal(
  resolveSessionRowForCell(
    [{ id: 5001, session_date: '2026-08-08', start_time: '17:00', status: 'scheduled' }],
    '2026-08-08',
    '18:00' // course.start_time — the course's usual slot, different from this row's actual time
  )?.id,
  5001,
  'off-template session (time differs from course default) must still resolve via same-date fallback'
);

assert.equal(
  resolveSessionRowForCell(
    [{ id: 5002, session_date: '2026-08-08', start_time: '18:00', status: 'scheduled' }],
    '2026-08-08',
    '18:00'
  )?.id,
  5002,
  'exact-time match still wins when the row matches the course default (no regression)'
);

assert.equal(
  resolveSessionRowForCell([], '2026-08-08', '18:00'),
  null,
  'no rows for the date resolves to null'
);

assert.equal(
  resolveSessionRowForCell(
    [{ id: 5003, session_date: '2026-08-09', start_time: '18:00', status: 'scheduled' }],
    '2026-08-08',
    '18:00'
  ),
  null,
  'rows on a different date must not match'
);

const deduped = dedupeSessionsByStudentSlot([
  { id: 100, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'scheduled', learning_record_status: 'missing' },
  { id: 200, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'attended', learning_record_status: 'approved' },
]);
assert.equal(deduped.length, 1, 'same student slot collapses to one row');
assert.equal(deduped[0].id, 200, 'attended session wins over scheduled duplicate');

// fetchClassSessions normalizes rows to camelCase before TeacherHomePage uses
// this helper. The same renewal-overlap pair must still collapse.
const camelCaseDeduped = dedupeSessionsByStudentSlot([
  { id: 210, studentId: 5, date: '2026-06-28', startTime: '12:00', status: 'scheduled', learningRecordStatus: 'missing' },
  { id: 220, studentId: 5, date: '2026-06-28', startTime: '12:00', status: 'attended', learningRecordStatus: 'approved' },
]);
assert.equal(camelCaseDeduped.length, 1, 'normalized SessionViewModels collapse to one teacher-home row');
assert.equal(camelCaseDeduped[0].id, 220, 'normalized attended session wins over scheduled duplicate');

// in-app #188 regression: rows that cannot be keyed must NEVER be dropped.
const twoStudentsSameSlot = dedupeSessionsByStudentSlot([
  { id: 301, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'attended' },
  { id: 302, student_id: 9, session_date: '2026-06-28', start_time: '10:00', status: 'scheduled' },
]);
assert.equal(twoStudentsSameSlot.length, 2, 'distinct students sharing a slot are both kept');

const missingStart = dedupeSessionsByStudentSlot([
  { id: 401, student_id: 5, session_date: '2026-06-28', start_time: '', status: 'attended' },
  { id: 402, student_id: 9, session_date: '2026-06-28', start_time: null, status: 'scheduled' },
]);
assert.equal(missingStart.length, 2, 'rows with empty/null start_time pass through instead of being dropped');

const missingStudent = dedupeSessionsByStudentSlot([
  { id: 501, session_date: '2026-06-28', start_time: '10:00', status: 'attended' },
  { id: 502, session_date: '2026-06-28', start_time: '11:00', status: 'scheduled' },
]);
assert.equal(missingStudent.length, 2, 'rows with no student identifier pass through instead of being dropped');

const mixed = dedupeSessionsByStudentSlot([
  { id: 601, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'scheduled' },
  { id: 602, student_id: 5, session_date: '2026-06-28', start_time: '10:00', status: 'attended' },
  { id: 603, session_date: '2026-06-28', start_time: '', status: 'scheduled' },
]);
assert.equal(mixed.length, 2, 'true duplicate collapses while the unkeyable row is still kept');
assert.ok(mixed.some((r) => r.id === 603), 'unkeyable row survives alongside collapsed duplicate');
