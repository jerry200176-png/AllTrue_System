import assert from 'node:assert/strict';
import { buildEditTeacherOptions, shouldClearTeacherSelection } from './courseTeacherOptions.js';

const currentCourse = {
  teacher_id: 42,
  teacher_name: '蔡老師',
};

const branchTeachers = [
  { id: 7, username: '王老師', branch_ids: [12] },
  { id: 8, username: '李老師', branch_ids: [12] },
];

const options = buildEditTeacherOptions(branchTeachers, currentCourse);
assert.equal(options[0].id, 42);
assert.equal(options[0].username, '蔡老師');
assert.equal(options[0].is_current_course_teacher, true);
assert.equal(options.length, 3);

const existingOptions = buildEditTeacherOptions([{ id: 42, username: '蔡老師' }], currentCourse);
assert.equal(existingOptions.length, 1, 'current teacher should not be duplicated when already loaded');

assert.equal(
  shouldClearTeacherSelection(42, branchTeachers, { isEditing: true }),
  false,
  'editing an existing course must preserve the original teacher even if async options missed it'
);

assert.equal(
  shouldClearTeacherSelection(42, branchTeachers, { isEditing: false }),
  true,
  'non-editing forms may still clear stale teacher selections'
);
