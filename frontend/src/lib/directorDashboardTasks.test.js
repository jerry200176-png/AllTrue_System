import assert from 'node:assert/strict';
import {
  buildDirectorDashboardTasks,
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
