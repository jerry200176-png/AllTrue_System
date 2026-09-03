const DASHBOARD_WORK_TARGETS = new Set([
  'attendance', 'calendar', 'course-mgmt', 'learning', 'schedule-discrepancy', 'tuition-collect',
]);

/** Build a short-lived return affordance for task-first dashboard navigation. */
export function createDashboardReturnContext({ fromPage, target } = {}) {
  if (fromPage !== 'director' || !DASHBOARD_WORK_TARGETS.has(target)) return null;
  return { page: 'director', label: '回到主任今日工作' };
}

