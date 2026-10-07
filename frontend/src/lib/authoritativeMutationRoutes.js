import { normalizeNavigationId, courseIdOf } from './workflowNavigationContext.js';

/** Authoritative billing / invoice / receipt mutations live on tuition-collect. */
export function buildTuitionCollectNav(courseOrRow, { intent = 'unpaid', tab = '' } = {}) {
  const studentId = normalizeNavigationId(
    courseOrRow?.student_id ?? courseOrRow?.StudentID ?? courseOrRow?.studentId,
  );
  const courseId = courseIdOf(courseOrRow);
  return {
    target: 'tuition-collect',
    studentId,
    courseId,
    intent: tab || intent || 'unpaid',
  };
}

/** Opens the student billing file (學生帳務檔) for this course inside 帳務中心. */
export function buildTuitionLedgerNav(courseOrRow) {
  return buildTuitionCollectNav(courseOrRow, { intent: 'ledger' });
}

/** Contract create / renew / purchase / settle live on students. */
export function buildStudentsCommercialNav(courseOrRow, { intent = 'edit' } = {}) {
  const studentId = normalizeNavigationId(
    courseOrRow?.student_id ?? courseOrRow?.StudentID ?? courseOrRow?.studentId,
  );
  const courseId = courseIdOf(courseOrRow);
  return {
    target: 'students',
    studentId,
    courseId,
    intent: intent || 'edit',
  };
}

/** Session scheduling ops live on course-mgmt. */
export function buildCourseMgmtOpsNav(courseOrRow, { teacherId = null, intent = '' } = {}) {
  const studentId = normalizeNavigationId(
    courseOrRow?.student_id ?? courseOrRow?.StudentID ?? courseOrRow?.studentId,
  );
  const courseId = courseIdOf(courseOrRow);
  const tid = normalizeNavigationId(teacherId ?? courseOrRow?.teacher_id);
  return {
    target: 'course-mgmt',
    studentId,
    courseId,
    teacherId: tid,
    ...(intent ? { intent } : {}),
  };
}

/** in-app #382: open 新增下一堂 for a manual course that has no lesson yet. */
export function buildManualSessionNav(studentId, courseId) {
  return buildCourseMgmtOpsNav({ id: courseId, student_id: studentId }, { intent: 'manual-session' });
}

/** LINE unbind lives on binding-management. */
export function buildBindingManagementNav({ studentId = null, studentName = '' } = {}) {
  return {
    target: 'binding-management',
    studentId: normalizeNavigationId(studentId),
    studentName: typeof studentName === 'string' ? studentName.trim() : '',
  };
}

/** Attendance mutations live on attendance. */
export function buildAttendanceNav({
  studentId = null,
  courseId = null,
  sessionId = null,
  date = '',
} = {}) {
  return {
    target: 'attendance',
    studentId: normalizeNavigationId(studentId),
    courseId: normalizeNavigationId(courseId),
    sessionId: normalizeNavigationId(sessionId),
    date: typeof date === 'string' ? date.slice(0, 10) : '',
  };
}

export function tuitionIntentForPaymentStatus(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'pending_report') return 'pending';
  if (normalized === 'paid') return 'receipts';
  return 'unpaid';
}
