import assert from 'node:assert/strict';
import {
  hasLeaveExceptionForCourseDate,
  scheduledExceptionStartSetForCourseDate,
  shouldRenderScheduledException,
} from './calendarExceptionMerge.js';

const zhangZhengleExceptions = [
  {
    id: 468,
    status: 'scheduled',
    schedule_date: '2026-04-29',
    student_course_id: 307,
    start_time: '19:30',
  },
  {
    id: 9001,
    status: 'leave',
    schedule_date: '2026-04-29',
    student_course_id: 307,
    start_time: '19:30',
  },
];

assert.equal(
  hasLeaveExceptionForCourseDate(zhangZhengleExceptions, '2026-04-29', 307),
  true,
  '張正樂 4/29 should be recognized as leave even when a scheduled exception also exists',
);

assert.deepEqual(
  [...scheduledExceptionStartSetForCourseDate(zhangZhengleExceptions, '2026-04-29', 307)],
  [],
  'scheduled exception must not hide the base leave card for the same course/date',
);

assert.equal(
  shouldRenderScheduledException(zhangZhengleExceptions[0], zhangZhengleExceptions, '2026-04-29'),
  false,
  'scheduled exception row should not render when the same course/date is on leave',
);

const normalScheduledOnly = [
  {
    id: 468,
    status: 'scheduled',
    schedule_date: '2026-04-29',
    student_course_id: 307,
    start_time: '19:30:00',
  },
];

assert.deepEqual(
  [...scheduledExceptionStartSetForCourseDate(normalScheduledOnly, '2026-04-29', '307')],
  ['19:30'],
  'scheduled-only exceptions should still suppress the replaced base slot',
);

assert.equal(
  shouldRenderScheduledException(normalScheduledOnly[0], normalScheduledOnly, '2026-04-29'),
  true,
  'scheduled-only exception should still render normally',
);

console.log('calendarExceptionMerge tests passed');
