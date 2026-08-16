/**
 * TD-076 Phase 0 lock: named so a "simplify the merge" PR cannot drop R102/R103
 * without this file failing. Fixtures are the production ids from R102; do not
 * invent new production rows. No schema / write-path change.
 */
import assert from 'node:assert/strict';
import { shouldRenderScheduledException } from './calendarExceptionMerge.js';
import { mergeWeekCalendarOccurrences } from './calendarOccurrenceMerge.js';
import { rowOccupiesPurchasedQuota } from './sessionOccurrenceFilter.js';

const wuAitong = [
  { id: 7583, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '15:00' },
  { id: 7584, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30', original_schedule_id: 7583 },
  { id: 7588, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30' },
  { id: 7589, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, start_time: '14:30', original_schedule_id: 7588 },
];
assert.equal(shouldRenderScheduledException(wuAitong[1], wuAitong, '2026-08-08'), false, 'R102 SC#2688 stale 7584');
assert.equal(shouldRenderScheduledException(wuAitong[3], wuAitong, '2026-08-08'), true, 'R102 SC#2688 live 7589');

const chenYuhan = [
  { id: 7138, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '18:00' },
  { id: 7139, status: 'scheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '14:00', original_schedule_id: 7138 },
  { id: 7207, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '14:00' },
  { id: 7208, status: 'scheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '13:00', original_schedule_id: 7207 },
  { id: 7422, status: 'rescheduled', schedule_date: '2026-08-07', student_course_id: 1249, start_time: '13:00' },
  { id: 7423, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 1249, start_time: '10:00', original_schedule_id: 7422 },
];
assert.equal(shouldRenderScheduledException(chenYuhan[3], chenYuhan, '2026-08-07'), false, 'R102 SC#1249 stale 7208');
assert.equal(shouldRenderScheduledException(chenYuhan[5], chenYuhan, '2026-08-08'), true, 'R102 SC#1249 live 7423');

const course = {
  id: 22601,
  student_id: 226001,
  student_name: 'lock',
  teacher_id: 17,
  days_of_week: [6],
  day_time_slots: [{ day: 6, start_time: '11:00', duration_hours: 2 }],
};
const orphan = mergeWeekCalendarOccurrences({
  courses: [course],
  allCourses: [course],
  weekDatesByDow: { 6: '2026-08-08' },
  sessionDatesByCourseId: { 22601: [] },
  courseLastSessionDate: { 22601: '2026-08-08' },
  exceptions: [
    { id: 2261001, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 22601, student_id: 226001, start_time: '10:00' },
    { id: 2261002, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 22601, student_id: 226001, start_time: '11:00', original_schedule_id: 2261001 },
  ],
});
assert.equal(
  orphan.filter((row) => Number(row.student_course_id) === 22601).length,
  0,
  'R103: orphan reschedule destination without ClassSession must not paint a calendar-only box',
);

assert.equal(rowOccupiesPurchasedQuota({ status: 'leave' }), false, 'leave still exists but does not occupy / bill a session');
assert.equal(rowOccupiesPurchasedQuota({ status: 'attended' }), true);

console.log('ok: scheduleOccurrencePhase0.lock');
