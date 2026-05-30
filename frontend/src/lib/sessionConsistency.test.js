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
