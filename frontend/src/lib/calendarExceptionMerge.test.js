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

// 木柵吳艾潼 SC#2688, 2026-08-08 (production incident): rescheduled twice in a row to
// the *same* destination time (14:30) — both the stale (7584) and current (7589)
// 'scheduled' markers share the same date, so a same-date-only dedupe would work, but
// the precise rule (a later same-slot 'rescheduled' marker supersedes) is what's
// actually shipped and must cover this case too.
const wuAitongExceptions = [
  { id: 7583, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '15:00' },
  { id: 7584, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30', original_schedule_id: 7583 },
  { id: 7588, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30' },
  { id: 7589, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30', original_schedule_id: 7588 },
];
assert.equal(
  shouldRenderScheduledException(wuAitongExceptions[1], wuAitongExceptions, '2026-08-08'),
  false,
  '木柵吳艾潼 SC#2688: stale scheduled marker (7584) superseded by a later same-slot rescheduled marker (7588) must not render',
);
assert.equal(
  shouldRenderScheduledException(wuAitongExceptions[3], wuAitongExceptions, '2026-08-08'),
  true,
  '木柵吳艾潼 SC#2688: the current scheduled marker (7589) must still render',
);

// 木柵陳宥翰 SC#1249, 2026-08-07/08 (production incident, in-app #225, same day as the
// #1685 fix shipped): a slot rescheduled THREE times in a row, where the final
// reschedule's destination lands on a *different date* (8/8) than the slot being
// vacated (8/7). A same-date "keep the latest scheduled" dedupe (the #1685 fix's first
// version) does NOT catch this — 7208 (scheduled, 8/7) has no competing 'scheduled'
// entry on 8/7 to lose to, but it WAS itself superseded by 7422 (rescheduled, same
// slot: 8/7 13:00) whose own destination (7423) moved to 8/8, not 8/7. The precise
// same-slot-superseded rule (not date-scoped) is required to catch this.
const chenYuhanExceptions = [
  { id: 7138, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '18:00' },
  { id: 7139, status: 'scheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '14:00', original_schedule_id: 7138 },
  { id: 7207, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '14:00' },
  { id: 7208, status: 'scheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '13:00', original_schedule_id: 7207 },
  { id: 7422, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '13:00' },
  { id: 7423, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 1249, start_time: '10:00', original_schedule_id: 7422 },
];
assert.equal(
  shouldRenderScheduledException(chenYuhanExceptions[3], chenYuhanExceptions, '2026-08-07'),
  false,
  '木柵陳宥翰 SC#1249 in-app #225: 7208 (scheduled, 8/7 13:00) was superseded by 7422 (rescheduled, same slot) even though 7422\'s own destination (7423) moved to a different date (8/8) — must not render on 8/7',
);
assert.equal(
  shouldRenderScheduledException(chenYuhanExceptions[1], chenYuhanExceptions, '2026-08-07'),
  false,
  '木柵陳宥翰 SC#1249: earlier-in-chain 7139 (scheduled, 8/7 14:00) was also superseded (by 7207) and must not render',
);
assert.equal(
  shouldRenderScheduledException(chenYuhanExceptions[5], chenYuhanExceptions, '2026-08-08'),
  true,
  '木柵陳宥翰 SC#1249: the final destination (7423, scheduled, 8/8 10:00) must still render',
);
