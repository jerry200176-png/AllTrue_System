import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/TeacherHomePage.vue', import.meta.url), 'utf8');

// fetchClassSessions returns SessionViewModel (camelCase). Reading the old API
// row names makes valid sessions render as "—" / "Branch #0" (in-app #235).
const staleFields = [
  's.start_time',
  's.end_time',
  's.student_class_id',
  's.student_name',
  's.subject_name',
  's.teacher_name',
  's.learning_record_status',
  's.learning_record_id',
];

for (const field of staleFields) {
  assert.equal(source.includes(field), false, `TeacherHomePage must not read stale class-session field ${field}`);
}

assert.match(
  source,
  /todayAllSessions\.value\.filter[\s\S]*s\?\.branch_id[\s\S]*s\?\.session_date/,
  'the direct App.vue todayAllSessions payload must retain its raw snake_case contract',
);

for (const field of ['s.date', 's.startTime', 's.studentName', 's.branchId', 's.learningRecordStatus']) {
  assert.equal(source.includes(field), true, `TeacherHomePage must read SessionViewModel field ${field}`);
}

assert.equal(source.includes('o.branch_id'), false, 'overdue evaluation actions must use SessionViewModel branchId');
assert.equal(source.includes('o.session_date'), false, 'overdue evaluation actions must use SessionViewModel date');
assert.match(source, /branchId: o\.branchId[\s\S]*sessionDate: o\.date/);

// #236: a reactive week reload must retain the last good projection while the
// next request is loading, and must not let an older response replace it.
assert.equal(source.includes('weekSessions.value = [];'), false,
  'week reload must not blank the schedule before the replacement is ready');
assert.match(source, /const requestSequence = \+\+weekLoadSequence[\s\S]*requestSequence === weekLoadSequence/,
  'week reload must guard against stale async responses');

console.log('teacher home SessionViewModel contract tests passed');
