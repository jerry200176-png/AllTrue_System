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

const substituteVisibilityCourse = {
  id: 503,
  student_id: 2003,
  student_name: '代課可見性測試生',
  teacher_id: 41,
  teacher_name: '原老師C',
  subject: 'Math',
  class_type: 'one_on_one',
  days_of_week: [7],
  day_time_slots: [{ day: 7, start_time: '14:00', duration_hours: 2 }],
  duration_hours: 2,
  sessions_purchased: 20,
};
const substituteVisibilitySessions = {
  503: [
    {
      id: 8201,
      session_date: '2026-05-17',
      start_time: '14:00',
      end_time: '16:00',
      status: 'scheduled',
      teacher_id: 99,
      teacher_name: '代課老師',
      substitute_teacher_id: 99,
    },
  ],
};
const substituteVisibilityExceptions = [
  {
    id: 9101,
    status: 'scheduled',
    schedule_date: '2026-05-17',
    student_course_id: 503,
    student_id: 2003,
    start_time: '14:00',
    end_time: '16:00',
    teacher_id: 99,
    original_schedule_id: 9100,
  },
];

const originalTeacherScopedRows = merge({
  courses: [substituteVisibilityCourse],
  allCourses: [substituteVisibilityCourse],
  weekDatesByDow: weekWithSundaySession,
  sessionDatesByCourseId: {},
  exceptions: substituteVisibilityExceptions,
  isTeacher: true,
  currentTeacherId: '41',
  teacherScopeId: '41',
});
assert.equal(
  originalTeacherScopedRows.length,
  0,
  'original teacher week view must drop a substituted occurrence once the substitute exception is loaded',
);

const substituteTeacherScopedRows = merge({
  courses: [substituteVisibilityCourse],
  allCourses: [substituteVisibilityCourse],
  weekDatesByDow: weekWithSundaySession,
  sessionDatesByCourseId: substituteVisibilitySessions,
  exceptions: substituteVisibilityExceptions,
  isTeacher: true,
  currentTeacherId: '99',
  teacherScopeId: '99',
});
assert.equal(substituteTeacherScopedRows.length, 1, 'substitute teacher week view should include the substituted occurrence');
assert.equal(substituteTeacherScopedRows[0].class_session_id, 8201);
assert.equal(substituteTeacherScopedRows[0].teacher_id, 99);

const legacyMuzhaMonthlyCourse = {
  id: 94,
  student_id: 160,
  student_name: '洪家溱',
  teacher_id: 29,
  teacher_name: '李維',
  subject: 'Math',
  class_type: 'one_on_one',
  days_of_week: [6],
  day_time_slots: [{ day: 6, start_time: '10:00', duration_hours: 2 }],
  duration_hours: 2,
  sessions_purchased: 3,
};
const currentMuzhaCourse = {
  ...legacyMuzhaMonthlyCourse,
  id: 1256,
  sessions_purchased: 4,
};
const muzhaDuplicateStudentSlot = merge({
  courses: [legacyMuzhaMonthlyCourse, currentMuzhaCourse],
  allCourses: [legacyMuzhaMonthlyCourse, currentMuzhaCourse],
  weekDatesByDow: {
    1: '2026-05-11',
    2: '2026-05-12',
    3: '2026-05-13',
    4: '2026-05-14',
    5: '2026-05-15',
    6: '2026-05-16',
    7: '2026-05-17',
  },
  sessionDatesByCourseId: {
    1256: [
      { id: 11262, session_date: '2026-05-16', start_time: '10:00', end_time: '12:00', status: 'leave', teacher_id: 29, teacher_name: '李維' },
    ],
  },
});
assert.equal(muzhaDuplicateStudentSlot.length, 1, 'same student/date/start should render once even when legacy active contracts exist');
assert.equal(muzhaDuplicateStudentSlot[0].student_course_id, 1256);
assert.equal(muzhaDuplicateStudentSlot[0].class_session_id, 11262);

// A student may take a different subject in the same slot after the original
// lesson is excused. The live replacement subject must remain visible; the
// excused original must not win the student/date/start dedupe key.
const biologyLeaveCourse = {
  ...baseCourse,
  id: 1261,
  subject: 'Biology',
  days_of_week: [6],
  day_time_slots: [{ day: 6, start_time: '13:00', duration_hours: 2 }],
};
const socialReplacementCourse = {
  ...baseCourse,
  id: 1262,
  subject: 'Social',
  days_of_week: [6],
  day_time_slots: [{ day: 6, start_time: '13:00', duration_hours: 2 }],
};
const biologyLeaveSocialReplacement = merge({
  courses: [biologyLeaveCourse, socialReplacementCourse],
  allCourses: [biologyLeaveCourse, socialReplacementCourse],
  weekDatesByDow: {
    1: '2026-05-11',
    2: '2026-05-12',
    3: '2026-05-13',
    4: '2026-05-14',
    5: '2026-05-15',
    6: '2026-05-16',
    7: '2026-05-17',
  },
  sessionDatesByCourseId: {
    1261: [
      { id: 126101, session_date: '2026-05-16', start_time: '13:00', end_time: '15:00', status: 'leave', teacher_id: 17 },
    ],
    1262: [
      { id: 126201, session_date: '2026-05-16', start_time: '13:00', end_time: '15:00', status: 'scheduled', teacher_id: 17 },
    ],
  },
});
assert.equal(biologyLeaveSocialReplacement.length, 1, 'same student/date/start still renders one replacement lesson');
assert.equal(biologyLeaveSocialReplacement[0].subject, 'Social', 'live replacement subject must beat excused old subject');
assert.equal(biologyLeaveSocialReplacement[0].class_session_id, 126201, 'live replacement session must remain authoritative');

// A template/legacy replacement without a ClassSession must not hide the
// materialized leave session. The materialized row is the source of truth.
const biologyLeaveWithLegacySocial = merge({
  courses: [biologyLeaveCourse, socialReplacementCourse],
  allCourses: [biologyLeaveCourse, socialReplacementCourse],
  weekDatesByDow: {
    1: '2026-05-11',
    2: '2026-05-12',
    3: '2026-05-13',
    4: '2026-05-14',
    5: '2026-05-15',
    6: '2026-05-16',
    7: '2026-05-17',
  },
  sessionDatesByCourseId: {
    1261: [
      { id: 126101, session_date: '2026-05-16', start_time: '13:00', end_time: '15:00', status: 'leave', teacher_id: 17 },
    ],
  },
});
assert.equal(biologyLeaveWithLegacySocial.length, 1, 'materialized leave and legacy template should still render one slot');
assert.equal(biologyLeaveWithLegacySocial[0].subject, 'Biology', 'materialized leave must beat a legacy template replacement');
assert.equal(biologyLeaveWithLegacySocial[0].class_session_id, 126101, 'materialized leave must remain authoritative');

const historicalStoppedCourse = {
  ...baseCourse,
  id: 777,
  stop: 1,
  status: 'inactive',
  day_of_week: 5,
  days_of_week: [5],
  day_time_slots: [{ day: 5, start_time: '13:00', duration_hours: 2 }],
};
const historicalStoppedMerge = merge({
  courses: [historicalStoppedCourse],
  allCourses: [historicalStoppedCourse],
  sessionDatesByCourseId: {
    777: [
      { id: 9901, session_date: '2026-05-08', start_time: '13:00', end_time: '15:00', status: 'attended', teacher_id: 17, teacher_name: '原老師' },
    ],
  },
});
assert.equal(historicalStoppedMerge.length, 1, 'historical stopped course should still render past ClassSession rows in week view');
assert.equal(historicalStoppedMerge[0].class_session_id, 9901);

const historicalWithoutSessions = merge({
  courses: [historicalStoppedCourse],
  allCourses: [historicalStoppedCourse],
  sessionDatesByCourseId: {},
});
assert.equal(historicalWithoutSessions.length, 0, 'historical stopped course without ClassSession rows should not render recurring ghosts');

// #180 regression: a course-mgmt-adjusted ClassSession lands on a date AFTER the course's last
// contracted session date and has no `schedules` row. The template loop skips that date via the
// `targetDate > lastDate` guard, so the materialized session would silently vanish from the
// calendar (course management shows 已上課, calendar shows nothing). It MUST still render.
const adjustedBeyondLastDate = merge({
  courses: [{ ...baseCourse, id: 2146, days_of_week: [7] }],
  allCourses: [{ ...baseCourse, id: 2146, days_of_week: [7] }],
  sessionDatesByCourseId: {
    2146: [
      { id: 17346, student_class_id: 2146, session_date: '2026-05-07', start_time: '13:00', end_time: '15:00', status: 'attended', teacher_id: 17, teacher_name: '原老師' },
    ],
  },
  courseLastSessionDate: { 2146: '2026-05-06' },
  exceptions: [],
});
assert.ok(
  adjustedBeyondLastDate.some((o) => o.class_session_id === 17346),
  '#180: adjusted ClassSession (no schedules row, date > courseLastSessionDate) must still render on the calendar',
);
assert.equal(
  adjustedBeyondLastDate.filter((o) => o.class_session_id === 17346).length,
  1,
  '#180: the recovered session must render exactly once (no duplicate)',
);

// #1035: materialized pass must use allCourses — when `courses` is empty (week filter
// removed the contract) but sessionDatesByCourseId has a live row, calendar still renders.
const materializedWhenCoursesFiltered = merge({
  courses: [],
  allCourses: [{ ...baseCourse, id: 820, student_id: 493, student_name: '吳湘翎', days_of_week: [6] }],
  sessionDatesByCourseId: {
    820: [
      { id: 7309, student_class_id: 820, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'attended', teacher_id: 70, teacher_name: 'Coco' },
    ],
  },
  weekDatesByDow: { 6: '2026-06-28' },
  courseLastSessionDate: { 820: '2026-06-28' },
  exceptions: [],
});
assert.ok(
  materializedWhenCoursesFiltered.some((o) => o.class_session_id === 7309),
  '#1035: materialized ClassSession renders when courses list is empty but allCourses + sessions exist',
);

// #187/#188 + 2026-06-28 新莊 (Xinzhuang) Sunday audit: multiple DISTINCT students materialized
// in the SAME date+time slot must ALL render. dedupeByStudentSlot keys on the student, so a shared
// slot (e.g. a Sunday 10:00 block holding several 1-on-1 contracts — verified live: 6 students at
// 10:00) must never collapse to a single occurrence. This guards the "director calendar only shows
// 3-4 students" symptom where a low-volume day's sessions appeared to vanish.
const sharedSlotMultiStudent = merge({
  courses: [],
  allCourses: [
    { ...baseCourse, id: 901, student_id: 5001, student_name: '甲生', days_of_week: [7] },
    { ...baseCourse, id: 902, student_id: 5002, student_name: '乙生', days_of_week: [7] },
    { ...baseCourse, id: 903, student_id: 5003, student_name: '丙生', days_of_week: [7] },
  ],
  sessionDatesByCourseId: {
    901: [{ id: 8101, student_class_id: 901, student_id: 5001, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'attended', teacher_id: 70 }],
    902: [{ id: 8102, student_class_id: 902, student_id: 5002, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'attended', teacher_id: 70 }],
    903: [{ id: 8103, student_class_id: 903, student_id: 5003, session_date: '2026-06-28', start_time: '10:00', end_time: '12:00', status: 'attended', teacher_id: 70 }],
  },
  weekDatesByDow: { 7: '2026-06-28' },
  courseLastSessionDate: { 901: '2026-06-28', 902: '2026-06-28', 903: '2026-06-28' },
  exceptions: [],
});
assert.equal(
  sharedSlotMultiStudent.filter((o) => [8101, 8102, 8103].includes(o.class_session_id)).length,
  3,
  '#187/#188: distinct students sharing one date+time slot must all render (no collapse to slot count)',
);

// 木柵吳艾潼 SC#2688, 2026-08-08 (production incident, 2026-08-08): a slot rescheduled
// twice in quick succession left TWO `schedules` rows with status='scheduled' for the
// same course+date (id 7584 stale/superseded, id 7589 the real current one) — the
// backend's exact-start_time dedupe delete didn't catch 7584 because the second
// reschedule re-submitted to the *same* start_time as the first (14:30), not a
// different one. Both passed shouldRenderScheduledException (both status='scheduled',
// no leave that day), producing a ghost box in the calendar alongside the real one.
const muzhaWuAitongCourse = {
  ...baseCourse,
  id: 2688,
  student_id: 178,
  student_name: '吳艾潼',
  days_of_week: [7],
  day_time_slots: [{ day: 7, start_time: '10:00', duration_hours: 2 }],
};
const muzhaStaleRescheduleWeek = { 6: '2026-08-08', 7: '2026-08-09' };
const muzhaStaleRescheduleMerge = merge({
  courses: [muzhaWuAitongCourse],
  allCourses: [muzhaWuAitongCourse],
  weekDatesByDow: muzhaStaleRescheduleWeek,
  sessionDatesByCourseId: {
    2688: [
      { id: 24169, session_date: '2026-08-08', start_time: '14:30', end_time: '16:30', status: 'scheduled', teacher_id: 17 },
    ],
  },
  courseLastSessionDate: { 2688: '2026-08-09' },
  exceptions: [
    { id: 7583, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, student_id: 178, start_time: '15:00', teacher_id: 17 },
    { id: 7584, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, student_id: 178, start_time: '14:30', end_time: '16:30', teacher_id: 17, original_schedule_id: 7583 },
    { id: 7588, status: 'rescheduled', schedule_date: '2026-08-08', student_course_id: 2688, student_id: 178, start_time: '14:30', teacher_id: 17 },
    { id: 7589, status: 'scheduled', schedule_date: '2026-08-08', student_course_id: 2688, student_id: 178, start_time: '14:30', end_time: '16:30', teacher_id: 17, original_schedule_id: 7588 },
  ],
});
const muzhaAug8Rows = muzhaStaleRescheduleMerge.filter((o) => defaultToYmdForTest(o) === '2026-08-08' && Number(o.student_course_id ?? o.id) === 2688);
function defaultToYmdForTest(row) {
  return String(row?.session_date || row?.schedule_date || '').slice(0, 10) || (row?.day_of_week === 6 ? '2026-08-08' : '');
}
assert.equal(
  muzhaAug8Rows.length,
  1,
  '木柵吳艾潼 SC#2688 2026-08-08: stale superseded scheduled marker (7584) must not render a second ghost box next to the real occurrence (7589/24169)',
);
assert.equal(muzhaAug8Rows[0].class_session_id, 24169);

// In-app #226/#227 regression shape: these schedule and course IDs are synthetic because the
// production row IDs were not available without DB access, unlike the real #1685/#1686 fixtures.
// The scheduled reschedule target has no matching materialized ClassSession, so it must not become
// a calendar-only occurrence that course management cannot corroborate.
const syntheticOrphanCourse = {
  ...baseCourse,
  id: 22601,
  student_id: 226001,
  student_name: 'synthetic #226/#227 student',
  days_of_week: [6],
  day_time_slots: [{ day: 6, start_time: '11:00', duration_hours: 2 }],
};
const syntheticOrphanMerge = merge({
  courses: [syntheticOrphanCourse],
  allCourses: [syntheticOrphanCourse],
  weekDatesByDow: { 6: '2026-08-08' },
  sessionDatesByCourseId: { 22601: [] },
  courseLastSessionDate: { 22601: '2026-08-08' },
  exceptions: [
    {
      id: 2261001,
      status: 'rescheduled',
      schedule_date: '2026-08-08',
      student_course_id: 22601,
      student_id: 226001,
      start_time: '10:00',
    },
    {
      id: 2261002,
      status: 'scheduled',
      schedule_date: '2026-08-08',
      student_course_id: 22601,
      student_id: 226001,
      start_time: '11:00',
      end_time: '13:00',
      teacher_id: 17,
      original_schedule_id: 2261001,
    },
  ],
});
assert.equal(
  syntheticOrphanMerge.filter((row) => Number(row.student_course_id) === 22601).length,
  0,
  'synthetic #226/#227: orphan scheduled reschedule target without a matching ClassSession must not render as a calendar ghost',
);
