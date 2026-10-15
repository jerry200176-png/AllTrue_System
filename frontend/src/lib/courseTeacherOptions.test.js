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
import { buildCreateTeacherOptions } from './courseTeacherOptions.js';
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
