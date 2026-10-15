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

// in-app #411: the create-course picker (SmartCalendar 快速排課) must hide deactivated teachers.
import { buildCreateTeacherOptions, resolveCreateTeacherId } from './courseTeacherOptions.js';
import { readFileSync } from 'node:fs';

const calendarTeachers = [
  { id: 7, username: '王老師', status: 'active' },
  { id: 9, username: '停用老師', status: 'inactive' },
  { id: 10, username: '暫停老師', status: 'suspended' },
  { id: 11, username: '舊資料老師' },
];
const createOptions = buildCreateTeacherOptions(calendarTeachers);
assert.deepEqual(
  createOptions.map((t) => t.id),
  [7, 11],
  'new-course picker must exclude non-active teachers but keep legacy rows without status'
);
assert.equal(createOptions[0].name, '王老師');

// Edit flow still shows a now-deactivated historical teacher.
const editOptions = buildEditTeacherOptions(createOptions, { teacher_id: 9, teacher_name: '停用老師' });
assert.equal(editOptions[0].id, 9, 'editing must still show the course\'s historical (deactivated) teacher');

const calendarPage = readFileSync(new URL('../pages/SmartCalendar.vue', import.meta.url), 'utf8');
assert.match(
  calendarPage,
  /const schedulerTeachers = computed\(\(\) => buildCreateTeacherOptions\(teachers\.value\)\)/,
  'SmartCalendar create-course scheduler must use buildCreateTeacherOptions'
);
const calendarLoader = readFileSync(new URL('../composables/calendar/useCalendarDataLoad.js', import.meta.url), 'utf8');
assert.match(calendarLoader, /status: t\?\.status \?\? null/, 'calendar teacher loader must keep status for filtering');

// Codex P2 (#4157): create options keep branch metadata the scheduler / availability
// planner read for branch labels and eligibility.
const branchCreateOptions = buildCreateTeacherOptions([
  { id: '7', username: '王老師', status: 'active', branch_ids: [2, 3], branch_id: 2, email: 't7@example.test' },
]);
assert.deepEqual(branchCreateOptions[0].branch_ids, [2, 3], 'create options must keep branch_ids');
assert.equal(branchCreateOptions[0].branch_id, 2, 'create options must keep branch_id');
assert.equal(branchCreateOptions[0].email, 't7@example.test', 'create options must keep other source fields');
assert.equal(branchCreateOptions[0].id, 7, 'id is normalized to a number');
assert.equal(branchCreateOptions[0].name, '王老師', 'name is normalized');

// Codex P2 (#4157): clicking an empty cell under a deactivated / historical teacher
// column must not seed that teacher into the create picker.
assert.equal(resolveCreateTeacherId(9, createOptions), '', 'deactivated teacher id from a cell click is cleared');
assert.equal(resolveCreateTeacherId('10', createOptions), '', 'string ids of suspended teachers are cleared too');
assert.equal(resolveCreateTeacherId(404, createOptions), '', 'unknown historical teacher id is cleared');
assert.equal(resolveCreateTeacherId(7, createOptions), 7, 'active teacher id is kept');
assert.equal(resolveCreateTeacherId('7', createOptions), '7', 'active teacher id keeps its original form');
assert.equal(resolveCreateTeacherId('', createOptions), '', 'empty stays empty');
assert.equal(resolveCreateTeacherId(null, createOptions), '', 'null becomes empty');
assert.match(
  calendarPage,
  /const onSlotClick = [\s\S]*?teacher_id: resolveCreateTeacherId\(teacherId, schedulerTeachers\.value\)/,
  'SmartCalendar cell click must validate the seeded teacher against the create options'
);
assert.match(
  calendarPage,
  /:initial-teacher-id="schedulerInitialTeacherId"/,
  'create scheduler must receive the validated initial teacher id'
);
assert.match(
  calendarPage,
  /const schedulerInitialTeacherId = computed\(\(\) => resolveCreateTeacherId\(modalForm\.value\.teacher_id, schedulerTeachers\.value\)\)/,
  'initial teacher id must be re-validated against the create options'
);
