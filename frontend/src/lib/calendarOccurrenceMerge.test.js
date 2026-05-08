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
