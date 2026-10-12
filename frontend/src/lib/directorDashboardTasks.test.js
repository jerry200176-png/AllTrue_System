import assert from 'node:assert/strict';
import {
  buildDirectorDashboardTasks,
  isLiveScheduleRow,
  sortDirectorDashboardTasks,
} from './directorDashboardTasks.js';

const tasks = buildDirectorDashboardTasks({
  pendingAttendanceCount: 2,
  exceptionWorkflowCount: 1,
  pendingEvaluationsCount: 3,
  unreadFeedbackCount: 0,
  adoptionTasks: [
    { id: 'a', title: '低優先工作', status: 'pending', owner: '行政' },
    { id: 'b', title: '逾期工作', status: 'overdue', owner: '主任' },
  ],
});

assert.deepEqual(tasks.map((item) => item.type), ['adoption', 'attendance', 'parent-leave', 'evaluations', 'adoption']);
assert.equal(tasks.find((item) => item.type === 'attendance').actionLabel, '前往點名');
assert.equal(tasks.find((item) => item.type === 'parent-leave').target.section, 'exception-workflows');
assert.equal(tasks.find((item) => item.type === 'adoption').severity, 'critical');

const sorted = sortDirectorDashboardTasks([
  { id: 'late', severity: 'warning', dueAt: '明天', owner: '主任' },
  { id: 'urgent', severity: 'critical', dueAt: '今日', owner: '主任' },
]);
assert.deepEqual(sorted.map((item) => item.id), ['urgent', 'late']);

assert.equal(buildDirectorDashboardTasks({}).length, 0);

// TD-076: a superseded schedules row is not live; scheduled/rescheduled keep their behavior.
assert.equal(isLiveScheduleRow({ status: 'scheduled' }), true);
assert.equal(isLiveScheduleRow({ status: 'rescheduled' }), true);
assert.equal(isLiveScheduleRow({ status: 'superseded' }), false);
assert.equal(isLiveScheduleRow({ status: 'Leave' }), false);
assert.equal(isLiveScheduleRow({ status: 'cancelled' }), false);

// Sidebar IA: schedule reports, duplicate review and the inbox live in the to-do list, only while pending.
const todo = buildDirectorDashboardTasks({ scheduleDiscrepancyCount: 2, duplicateReviewCount: 3, inboxCount: 5 });
assert.deepEqual(todo.map((item) => item.type).sort(), ['duplicate-review', 'inbox', 'schedule-discrepancy']);
assert.equal(todo.find((item) => item.type === 'schedule-discrepancy').target.page, 'schedule-discrepancy');
assert.equal(todo.find((item) => item.type === 'duplicate-review').target.page, 'duplicate-review');
assert.equal(todo.find((item) => item.type === 'duplicate-review').count, 3);
assert.equal(todo.find((item) => item.type === 'inbox').target.page, 'notifications');
assert.equal(todo.find((item) => item.type === 'inbox').count, 5);
assert.equal(buildDirectorDashboardTasks({ scheduleDiscrepancyCount: 0, duplicateReviewCount: 0, inboxCount: 0 }).length, 0);
