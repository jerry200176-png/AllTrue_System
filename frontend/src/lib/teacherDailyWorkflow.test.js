import assert from 'node:assert/strict';
import {
  buildTeacherTasks,
  TEACHER_LEAVE_STATUSES,
} from './teacherDailyWorkflow.js';

const base = {
  pendingAttendance: [],
  pendingLearning: [],
  overdueLearning: [],
  awaitingReplies: [],
};

const makeSession = (id, status = 'scheduled') => ({
  class_session_id: id,
  session_date: '2026-08-02',
  start_time: '10:00',
  end_time: '11:00',
  student_name: `學生${id}`,
  subject_name: '英文',
  status,
});

const makeLearning = (id, status = 'pending') => ({
  id,
  class_session_id: id,
  session_date: '2026-08-02',
  start_time: '09:00',
  end_time: '10:00',
  student_name: `學生${id}`,
  subject_name: '數學',
  form_status: status,
});

assert.deepEqual(TEACHER_LEAVE_STATUSES, ['leave', 'leave_requested', 'leave_adjusted', 'excused']);

const tasks = buildTeacherTasks({
  ...base,
  pendingAttendance: [makeSession(1), makeSession(2, 'leave_requested')],
  pendingLearning: [makeLearning(1), makeLearning(3, 'changes_requested')],
  overdueLearning: [makeLearning(3, 'pending'), makeLearning(4, 'pending')],
  awaitingReplies: [{ id: 99, count: 2 }],
});

assert.equal(tasks.filter((task) => task.type === 'attendance').length, 1, 'attendance should exclude leave_requested');
assert.equal(tasks.filter((task) => task.type === 'learning' && task.id === 'learning-3').length, 1, 'learning should dedupe overdue and pending');
assert.equal(tasks[0].actionLabel, '修改評量', 'changes requested should be first and actionable');
assert.equal(tasks.filter((task) => task.type === 'feedback').length, 1);
assert.equal(tasks.find((task) => task.type === 'attendance').actionLabel, '開始點名');

const empty = buildTeacherTasks(base);
assert.deepEqual(empty, []);
