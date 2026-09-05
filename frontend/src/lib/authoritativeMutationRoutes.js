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
export function buildCourseMgmtOpsNav(courseOrRow, { teacherId = null } = {}) {
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
  };
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
