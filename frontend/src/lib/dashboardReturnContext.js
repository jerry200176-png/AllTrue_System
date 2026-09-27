const DASHBOARD_WORK_TARGETS = new Set([
  'attendance', 'calendar', 'course-mgmt', 'learning', 'schedule-discrepancy', 'tuition-collect',
]);

/** Build a short-lived return affordance for task-first dashboard navigation. */
export function createDashboardReturnContext({ fromPage, target, studentId, courseId } = {}) {
  if (fromPage === 'course-mgmt' && target === 'students') {
    const sid = Number(studentId);
    const cid = Number(courseId);
    // A return affordance is only useful when it can identify the originating
    // course; malformed payloads safely fall back to ordinary Student Management.
    if (!Number.isSafeInteger(sid) || sid <= 0 || !Number.isSafeInteger(cid) || cid <= 0) return null;
    return { page: 'course-mgmt', label: '回到課程管理', studentId: sid, courseId: cid };
  }
  if (fromPage !== 'director' || !DASHBOARD_WORK_TARGETS.has(target)) return null;
  return { page: 'director', label: '回到主任今日工作' };
}
