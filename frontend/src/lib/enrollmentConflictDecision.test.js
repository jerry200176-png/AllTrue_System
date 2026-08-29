import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  ENROLLMENT_FORCE_REASONS,
  buildForceOverrideFields,
  collectStudentCourses,
  findCourseForPurchase,
  isAuditedForceReason,
  normalizeActiveCourseConflicts,
  resolveConflictCourseId,
} from './enrollmentConflictDecision.js';

assert.equal(isAuditedForceReason(ENROLLMENT_FORCE_REASONS.CREATE_TRIAL), true);
assert.equal(isAuditedForceReason('yes_i_know'), false);

const fields = buildForceOverrideFields({
  force_reason: ENROLLMENT_FORCE_REASONS.INDEPENDENT_PARALLEL,
  force_note: '同科不同時段',
  existing_contract_ids: ['12', 34, 0, null],
});
assert.deepEqual(fields, {
  force: true,
  force_reason: 'independent_parallel',
  force_note: '同科不同時段',
  existing_contract_ids: [12, 34],
});

assert.equal(resolveConflictCourseId({ id: 42 }), 42);
assert.equal(resolveConflictCourseId({ existing_course_id: '9', id: 1 }), 9);
assert.equal(resolveConflictCourseId({ id: '0' }), null);

const listed = [{ id: '42', subject: 'Math', payment_type: 'session' }];
const activeCourseFromApi = {
  id: 42,
  subject_name: '數學',
  remaining_sessions: 1,
  class_type: 'one_on_one',
  payment_type: 'session',
};
assert.equal(findCourseForPurchase(listed, activeCourseFromApi)?.subject, 'Math');
assert.equal(findCourseForPurchase(listed, { existing_course_id: 42 })?.id, '42');
assert.equal(findCourseForPurchase(listed, { existing_course_id: 99 }), null);

assert.deepEqual(
  collectStudentCourses(
    { 5: listed, 9: [{ id: 7, subject: 'English' }] },
    { id: 5, _laravelId: 9 },
  ).map((c) => Number(c.id)),
  [42, 7],
);

assert.equal(normalizeActiveCourseConflicts([activeCourseFromApi])[0].existing_course_id, 42);

const here = dirname(fileURLToPath(import.meta.url));
const studentsList = readFileSync(join(here, '../pages/StudentsList.vue'), 'utf8');
assert.ok(studentsList.includes('findCourseForPurchase'));
assert.ok(studentsList.includes('collectStudentCourses'));
assert.ok(studentsList.includes('normalizeActiveCourseConflicts'));
assert.equal(studentsList.includes('c.id === conflict.existing_course_id'), false);

const courseManagement = readFileSync(join(here, '../pages/CourseManagement.vue'), 'utf8');
assert.ok(courseManagement.includes('findCourseForPurchase'));
assert.equal(courseManagement.includes('c.id === conflict.existing_course_id'), false);

console.log('enrollmentConflictDecision.test.js: ok');
