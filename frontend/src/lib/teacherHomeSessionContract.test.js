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
  /todayAllSessions\.value = rows/,
  'today attendance rows must be stored after SessionViewModel mapping',
);
assert.match(
  source,
  /fetchClassSessions\(\{ token, start: today, end: today/,
  'today attendance must reuse fetchClassSessions, not a second raw class-sessions contract',
);
assert.match(
  source,
  /fetchClassSessionsProjection\(\{ token, start: startStr, end: endStr \}\)/,
  'weekly teacher schedule must use the completeness-safe projection API',
);
assert.equal(source.includes('branchId: s.branchId || 0'), false, 'missing branchId must not coerce to 0');
assert.equal(source.includes('Branch #'), false, 'teacher home must not render Branch #N labels');

for (const field of ['s.date', 's.startTime', 's.studentName', 's.branchId', 's.learningRecordStatus']) {
  assert.equal(source.includes(field), true, `TeacherHomePage must read SessionViewModel field ${field}`);
}
assert.equal(source.includes('isProjected: !!s.isProjected'), true,
  'weekly events must preserve projected-vs-materialized state for safe actions');
assert.equal(source.includes('!ev.isProjected'), true,
  'projected weekly slots must not invoke ClassSession-only actions before materialization');

// #236: a reactive week reload must retain the last good projection while the
// next request is loading, and must not let an older response replace it.
assert.equal(source.includes('weekSessions.value = [];'), false,
  'week reload must not blank the schedule before the replacement is ready');
assert.match(source, /const requestSequence = \+\+weekLoadSequence[\s\S]*requestSequence === weekLoadSequence/,
  'week reload must guard against stale async responses');

console.log('teacher home SessionViewModel contract tests passed');
