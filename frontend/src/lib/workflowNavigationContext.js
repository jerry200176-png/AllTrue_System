/** Resolve safe, existing records for task-first navigation. */
export function normalizeNavigationId(value) {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}
function courseIdOf(row) {
  return normalizeNavigationId(row?.student_class_id ?? row?.id);
}

export function resolveTuitionFocusRow(rows = [], { studentId, courseId } = {}) {
  const sid = normalizeNavigationId(studentId);
  const cid = normalizeNavigationId(courseId);
  const list = Array.isArray(rows) ? rows : [];
  if (cid) {
    const exact = list.find((row) => courseIdOf(row) === cid && (!sid || normalizeNavigationId(row?.student_id) === sid));
    if (exact) return exact;
  }
  if (sid) return list.find((row) => normalizeNavigationId(row?.student_id) === sid) || null;
  return null;
}

export function resolveCalendarFocusCourse(courses = [], { studentId, courseId } = {}) {
  const sid = normalizeNavigationId(studentId);
  const cid = normalizeNavigationId(courseId);
  const list = Array.isArray(courses) ? courses : [];
  if (cid) {
    const exact = list.find((course) => normalizeNavigationId(course?.id) === cid && (!sid || normalizeNavigationId(course?.student_id) === sid));
    if (exact) return exact;
  }
  if (sid) return list.find((course) => normalizeNavigationId(course?.student_id) === sid) || null;
  return null;
}
