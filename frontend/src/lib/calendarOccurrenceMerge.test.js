import assert from 'node:assert/strict';
import { mergeWeekCalendarOccurrences } from './calendarOccurrenceMerge.js';

const weekDatesByDow = {
  1: '2026-05-04',
  2: '2026-05-05',
  3: '2026-05-06',
  4: '2026-05-07',
  5: '2026-05-08',
  6: '2026-05-09',
  7: '2026-05-10',
};

const baseCourse = {
  id: 382,
  student_id: 1205,
  student_name: '吳艾潼',
  teacher_id: 17,
  teacher_name: '原老師',
  subject: 'Math',
  class_type: 'one_on_one',
  days_of_week: [7],
  day_time_slots: [{ day: 7, start_time: '15:00', duration_hours: 2 }],
  duration_hours: 2,
  sessions_purchased: 0,
};

const merge = (overrides = {}) => mergeWeekCalendarOccurrences({
  courses: overrides.courses || [baseCourse],
  allCourses: overrides.allCourses || overrides.courses || [baseCourse],
  exceptions: overrides.exceptions || [],
  sessionDatesByCourseId: overrides.sessionDatesByCourseId || {},
  weekDatesByDow,
  courseLastSessionDate: overrides.courseLastSessionDate || {},
  resolveAllCourseGridTimesForDate: (course, dow, ymd) => {
    const rows = (overrides.sessionDatesByCourseId || {})[String(course.id)] || [];
    const sameDate = rows.filter((row) => String(row.session_date).slice(0, 10) === ymd && row.status !== 'cancelled');
    if (sameDate.length) {
      return sameDate.map((row) => ({
        start_time: row.start_time,
        end_time: row.end_time,
        duration_hours: 2,
        teacher_id: row.teacher_id,
        teacher_name: row.teacher_name,
      }));
    }
    return (course.day_time_slots || [])
      .filter((slot) => Number(slot.day) === Number(dow))
      .map((slot) => ({ start_time: slot.start_time, end_time: '17:00', duration_hours: 2 }));
  },
  computeEndTime: () => '17:00',
  resolveTeacherName: (id) => (Number(id) === 99 ? '代課老師' : null),
  resolveStudentName: () => '吳艾潼',
  ...overrides,
});

const classSessionBackedScheduled = merge({
  sessionDatesByCourseId: {
    382: [
      { id: 7001, student_class_id: 382, session_date: '2026-05-10', start_time: '15:00', end_time: '17:00', status: 'scheduled', teacher_id: 17, teacher_name: '原老師' },
    ],
  },
  exceptions: [
    { id: 610, status: 'rescheduled', schedule_date: '2026-05-08', student_course_id: 382, student_id: 1205, start_time: '15:00', teacher_id: 17 },
    { id: 611, status: 'scheduled', schedule_date: '2026-05-10', student_course_id: 382, student_id: 1205, start_time: '15:00', end_time: '17:00', teacher_id: 99, original_schedule_id: 610 },
  ],
});

assert.equal(
  classSessionBackedScheduled.length,
  1,
  'SC#382 same ClassSession + scheduled exception should render once, not duplicate or disappear',
);
assert.equal(classSessionBackedScheduled[0].class_session_id, 7001);
assert.equal(classSessionBackedScheduled[0].teacher_id, 99, 'scheduled exception overlays effective teacher on the ClassSession occurrence');

const multiSlotSameDay = merge({
  courses: [{
    ...baseCourse,
    day_time_slots: [
      { day: 7, start_time: '15:00', duration_hours: 2 },
      { day: 7, start_time: '17:00', duration_hours: 2 },
    ],
  }],
  sessionDatesByCourseId: {
    382: [
      { id: 7001, student_class_id: 382, session_date: '2026-05-10', start_time: '15:00', end_time: '17:00', status: 'scheduled', teacher_id: 17, teacher_name: '原老師' },
      { id: 7002, student_class_id: 382, session_date: '2026-05-10', start_time: '17:00', end_time: '19:00', status: 'scheduled', teacher_id: 17, teacher_name: '原老師' },
    ],
  },
  exceptions: [
    { id: 611, status: 'scheduled', schedule_date: '2026-05-10', student_course_id: 382, student_id: 1205, start_time: '17:00', end_time: '19:00', teacher_id: 99, original_schedule_id: 610 },
  ],
});

assert.deepEqual(
  multiSlotSameDay.map((row) => row.start_time).sort(),
  ['15:00', '17:00'],
  'a scheduled exception for one slot must not erase another slot on the same date',
);

const leaveWithScheduled = merge({
  sessionDatesByCourseId: {
    382: [
      { id: 7003, student_class_id: 382, session_date: '2026-05-10', start_time: '15:00', end_time: '17:00', status: 'leave', teacher_id: 17, teacher_name: '原老師' },
    ],
  },
  exceptions: [
    { id: 620, status: 'scheduled', schedule_date: '2026-05-10', student_course_id: 382, student_id: 1205, start_time: '15:00', end_time: '17:00', teacher_id: 99, original_schedule_id: 619 },
    { id: 621, status: 'leave', schedule_date: '2026-05-10', student_course_id: 382, student_id: 1205, start_time: '15:00', teacher_id: 17 },
  ],
});

assert.equal(leaveWithScheduled.length, 1, 'leave card should remain visible when a scheduled exception also exists');
assert.equal(leaveWithScheduled[0].class_session_id, 7003);

/** Week 2026-05-11 … 05-17: Sun = 05-17. SessionCount (= sessions_purchased) is total purchased, not a per-week cap. */
const weekWithSundaySession = {
  1: '2026-05-11',
  2: '2026-05-12',
  3: '2026-05-13',
  4: '2026-05-14',
  5: '2026-05-15',
  6: '2026-05-16',
  7: '2026-05-17',
};

const sundayBugCourse = {
  id: 501,
  student_id: 2001,
  student_name: '曾允栩',
  teacher_id: 30,
  teacher_name: '測師',
  subject: 'English',
  class_type: 'one_on_one',
  days_of_week: [1],
  day_time_slots: [{ day: 1, start_time: '09:00', duration_hours: 2 }],
  duration_hours: 2,
  sessions_purchased: 4,
};

const sundayRegression = merge({
  courses: [sundayBugCourse],
  allCourses: [sundayBugCourse],
  weekDatesByDow: weekWithSundaySession,
  sessionDatesByCourseId: {
    501: [
      { id: 8001, session_date: '2026-05-11', start_time: '09:00', end_time: '11:00', status: 'scheduled', teacher_id: 30 },
      { id: 8002, session_date: '2026-05-12', start_time: '09:00', end_time: '11:00', status: 'scheduled', teacher_id: 30 },
      { id: 8003, session_date: '2026-05-13', start_time: '09:00', end_time: '11:00', status: 'scheduled', teacher_id: 30 },
      { id: 8004, session_date: '2026-05-14', start_time: '09:00', end_time: '11:00', status: 'scheduled', teacher_id: 30 },
      { id: 8005, session_date: '2026-05-17', start_time: '10:00', end_time: '12:00', status: 'scheduled', teacher_id: 30 },
    ],
  },
});

const sunRow = sundayRegression.find(
  (r) => Number(r.student_course_id ?? 0) === 501 && String(r?.start_time || '').startsWith('10:'),
);
assert.ok(sunRow, 'Sunday 05-17 ClassSession must render (SessionCount must not truncate the dow loop early)');
assert.equal(sunRow.class_session_id, 8005);

// `hasReschedule` used to skip the whole calendar day whenever a `schedules.status=rescheduled` row existed,
// erasing occurrences that still had live ClassSessions (stale marker / retries / dirty edge case).
const rescheduleGhostCourse = {
  id: 502,
  student_id: 2002,
  student_name: '曾庭栩',
  teacher_id: 31,
  teacher_name: '測師B',
  subject: 'Math',
  class_type: 'one_on_one',
  days_of_week: [7],
  day_time_slots: [{ day: 7, start_time: '10:00', duration_hours: 2 }],
  duration_hours: 2,
  sessions_purchased: 20,
};
const rescheduleGhostMerge = merge({
  courses: [rescheduleGhostCourse],
  allCourses: [rescheduleGhostCourse],
  weekDatesByDow: weekWithSundaySession,
  sessionDatesByCourseId: {
    502: [
      {
        id: 8101,
        session_date: '2026-05-17',
        start_time: '10:00',
        end_time: '12:00',
        status: 'scheduled',
        teacher_id: 31,
      },
    ],
  },
  exceptions: [
    {
      id: 9001,
      status: 'rescheduled',
      schedule_date: '2026-05-17',
      student_course_id: 502,
      student_id: 2002,
      start_time: '10:00',
      teacher_id: 31,
    },
  ],
});
const ghostRow = rescheduleGhostMerge.find((r) => Number(r.student_course_id ?? 0) === 502);
assert.ok(ghostRow, 'still render scheduled ClassSession when reschedule marker ghosts same calendar date');
assert.equal(ghostRow.class_session_id, 8101);
