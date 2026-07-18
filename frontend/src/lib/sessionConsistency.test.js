import assert from 'node:assert/strict';
import {
  buildTeacherClassParams,
  classifyAttendanceSessionRows,
  resolveLearningSessionState,
} from './sessionConsistency.js';

const teacherParams = buildTeacherClassParams({ branchId: 9 });
assert.equal(
  teacherParams.get('branch_id'),
  '9',
  'teacher schedule should request the same branch as the learning-record list',
);

const absentState = resolveLearningSessionState({
  sessionStatus: 'absent',
  learningRecordStatus: 'missing',
  sessionStarted: true,
});
assert.equal(absentState.formStatus, 'absent');
assert.equal(absentState.label, '缺席');
assert.equal(absentState.fillLocked, true);
assert.equal(absentState.recordIdAllowed, false);

const attendance = classifyAttendanceSessionRows([
  {
    id: 11354,
    student_id: 305,
    student_class_id: 996,
    student_name: '王光熙',
    subject_name: '理化',
    teacher_name: '黃芝琳',
    session_date: '2026-05-16',
    start_time: '11:00',
    end_time: '12:00',
    status: 'absent',
    note: '請假自動順延',
  },
  {
    id: 10269,
    student_id: 89,
    student_class_id: 1272,
    student_name: '王品方',
    subject_name: '數學',
    teacher_name: '黃芝琳',
    session_date: '2026-05-16',
    start_time: '15:00',
    end_time: '17:00',
    status: 'scheduled',
  },
]);

assert.equal(attendance.pending.length, 1);
assert.equal(attendance.statusRows.length, 1);
assert.equal(attendance.statusRows[0].status_label, '缺席');
assert.match(attendance.statusRows[0].status_note, /請假自動順延/);

// in-app #194 / GitHub #1099: a pending-review leave (ClassSession.Status='leave_requested',
// parent-portal flow, no sign-in row) must be treated the SAME by 出缺勤管理 and 課表與評量:
// visible as 請假(待審), not pending attendance marking, not fillable, not counted as 未填.
const leaveRequestedState = resolveLearningSessionState({
  sessionStatus: 'leave_requested',
  learningRecordStatus: 'pending',
  sessionStarted: true,
});
assert.equal(leaveRequestedState.formStatus, 'leave_requested');
assert.equal(leaveRequestedState.label, '請假(待審)');
assert.equal(leaveRequestedState.fillLocked, true, 'pending leave must lock eval filling');
assert.equal(leaveRequestedState.recordIdAllowed, false);
assert.equal(leaveRequestedState.fillLockReason, '請假申請審核中，暫不需填寫評量');

const leaveRequestedAttendance = classifyAttendanceSessionRows([
  {
    // Exact #194 shape: 陳品承 週六 15-17 1v2, leave submitted awaiting review
    id: 15635,
    student_id: 2144,
    student_class_id: 1946,
    student_name: '陳品承',
    subject_name: '理化',
    teacher_name: '黃芝琳',
    session_date: '2026-07-04',
    start_time: '15:00',
    end_time: '17:00',
    status: 'leave_requested',
  },
  {
    id: 21460,
    student_id: 300,
    student_class_id: 2496,
    student_name: '翁曼庭',
    subject_name: '理化',
    teacher_name: '黃芝琳',
    session_date: '2026-07-04',
    start_time: '15:00',
    end_time: '17:00',
    status: 'scheduled',
  },
]);
assert.equal(
  leaveRequestedAttendance.pending.length,
  1,
  'leave_requested must not sit in the to-mark pending list',
);
assert.equal(leaveRequestedAttendance.pending[0].student_name, '翁曼庭');
assert.equal(
  leaveRequestedAttendance.statusRows.length,
  1,
  'leave_requested student must remain VISIBLE as a status row (was silently dropped — #194)',
);
assert.equal(leaveRequestedAttendance.statusRows[0].student_name, '陳品承');
assert.equal(leaveRequestedAttendance.statusRows[0].status_label, '請假(待審)');
assert.equal(
  leaveRequestedAttendance.totalCount,
  2,
  'pending-review leave still counts toward today total (not yet approved)',
);

// #555: once a deferred session is actually attended, the stale "請假自動順延"
// provenance note must NOT show alongside the 到班 label (contradictory state).
const attendedDeferred = classifyAttendanceSessionRows([
  {
    id: 22001,
    student_id: 67,
    student_class_id: 1500,
    student_name: '沈柏宇',
    subject_name: '數學',
    teacher_name: '黃芝琴',
    session_date: '2026-05-27',
    start_time: '17:00',
    end_time: '19:00',
    status: 'attended',
    note: '請假自動順延',
  },
]);
assert.equal(attendedDeferred.statusRows.length, 1);
assert.equal(attendedDeferred.statusRows[0].status_label, '到班');
assert.equal(
  attendedDeferred.statusRows[0].status_note,
  '',
  'attended session must not surface the leave-deferral provenance note (#555)',
);

// 2026-07-18 Xindian: 黃芝琳出缺勤「王品方／陳品承各兩堂」— API 回跨 SC 同 student+date+start
// 兩筆 scheduled 時，pending 必須只留一列（對齊 TeacherHome student-slot dedupe；R20/#189）。
const crossScPending = classifyAttendanceSessionRows([
  {
    id: 30001,
    student_id: 89,
    student_class_id: 787,
    course_stop: 1,
    student_name: '王品方',
    subject_name: '數學',
    teacher_name: '黃芝琳',
    session_date: '2026-07-18',
    start_time: '13:00',
    end_time: '15:00',
    status: 'scheduled',
  },
  {
    id: 30002,
    student_id: 89,
    student_class_id: 1272,
    course_stop: 0,
    student_name: '王品方',
    subject_name: '數學',
    teacher_name: '黃芝琳',
    session_date: '2026-07-18',
    start_time: '13:00',
    end_time: '15:00',
    status: 'scheduled',
  },
  {
    id: 30003,
    student_id: 2144,
    student_class_id: 1946,
    course_stop: 0,
    course_session_count: 8,
    student_name: '陳品承',
    subject_name: '理化',
    teacher_name: '黃芝琳',
    session_date: '2026-07-18',
    start_time: '15:00',
    end_time: '17:00',
    status: 'scheduled',
  },
  {
    id: 30004,
    student_id: 2144,
    student_class_id: 2264,
    course_stop: 0,
    course_session_count: 0,
    student_name: '陳品承',
    subject_name: '理化',
    teacher_name: '黃芝琳',
    session_date: '2026-07-18',
    start_time: '15:00',
    end_time: '17:00',
    status: 'scheduled',
  },
]);
assert.equal(crossScPending.pending.length, 2, 'one pending row per student slot');
assert.equal(crossScPending.totalCount, 2);
const pinFang = crossScPending.pending.find((r) => r.student_name === '王品方');
assert.equal(pinFang.student_class_id, 1272, 'prefer Stop=0 course over Stop=1 orphan');
assert.equal(pinFang.class_session_id, 30002);
const pinCheng = crossScPending.pending.find((r) => r.student_name === '陳品承');
assert.equal(pinCheng.student_class_id, 1946, 'prefer real contract over SessionCount=0 ghost shell (#189)');
assert.equal(pinCheng.class_session_id, 30003);
