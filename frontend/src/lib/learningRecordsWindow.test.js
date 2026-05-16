import assert from 'node:assert/strict';
import {
  resolveLearningRecordsDefaultWindowStart,
  shouldApplyLearningRecordsDefaultWindow,
} from './learningRecordsWindow.js';

assert.equal(
  shouldApplyLearningRecordsDefaultWindow({
    days: 90,
    isTeacher: true,
    teacherFilterTab: 'all',
    teacherPriorityFilter: 'unfilled',
  }),
  false,
  'teacher unfilled priority must not hide old pending work behind the default window'
);

assert.equal(
  shouldApplyLearningRecordsDefaultWindow({
    days: 90,
    isTeacher: true,
    teacherFilterTab: 'pending',
    teacherPriorityFilter: 'all',
  }),
  false,
  'teacher pending tab must not apply the default window'
);

assert.equal(
  shouldApplyLearningRecordsDefaultWindow({
    days: 90,
    isTeacher: true,
    teacherFilterTab: 'approved',
    teacherPriorityFilter: 'all',
  }),
  true,
  'completed/non-todo views should keep the default history window'
);

assert.equal(
  shouldApplyLearningRecordsDefaultWindow({
    days: 90,
    isTeacher: false,
    reviewTab: 'pending',
  }),
  false,
  'director pending review must remain outside the default window'
);

assert.equal(
  resolveLearningRecordsDefaultWindowStart({
    days: 90,
    isTeacher: true,
    teacherFilterTab: 'approved',
    teacherPriorityFilter: 'all',
    now: '2026-05-16T12:00:00+08:00',
  }),
  '2026-02-15'
);
