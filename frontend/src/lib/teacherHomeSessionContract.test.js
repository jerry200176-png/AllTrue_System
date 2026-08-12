import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/TeacherHomePage.vue', import.meta.url), 'utf8');

// fetchClassSessions returns SessionViewModel (camelCase). Reading the old API
// row names makes valid sessions render as "—" / "Branch #0" (in-app #235).
const staleFields = [
  's.session_date',
  's.start_time',
  's.end_time',
  's.student_class_id',
  's.student_name',
  's.subject_name',
  's.teacher_name',
  's.branch_id',
  's.learning_record_status',
  's.learning_record_id',
];

for (const field of staleFields) {
  assert.equal(source.includes(field), false, `TeacherHomePage must not read stale class-session field ${field}`);
}

for (const field of ['s.date', 's.startTime', 's.studentName', 's.branchId', 's.learningRecordStatus']) {
  assert.equal(source.includes(field), true, `TeacherHomePage must read SessionViewModel field ${field}`);
}

console.log('teacher home SessionViewModel contract tests passed');
