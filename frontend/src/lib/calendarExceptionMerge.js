export function toExceptionYmd(value) {
  return value ? String(value).slice(0, 10) : '';
}

export function normalizeExceptionStartTime(value) {
  return value ? String(value).slice(0, 5) : '';
}

function sameCourseDate(exception, targetDate, courseId) {
  if (exception?.student_course_id == null || courseId == null) return false;
  return String(exception.student_course_id) === String(courseId)
    && toExceptionYmd(exception.schedule_date) === toExceptionYmd(targetDate);
}

export function hasLeaveExceptionForCourseDate(exceptions = [], targetDate, courseId) {
  return (exceptions || []).some((exception) =>
    String(exception?.status || '').toLowerCase() === 'leave'
      && sameCourseDate(exception, targetDate, courseId)
  );
}

export function scheduledExceptionStartSetForCourseDate(exceptions = [], targetDate, courseId) {
  if (hasLeaveExceptionForCourseDate(exceptions, targetDate, courseId)) {
    return new Set();
  }

  return new Set(
    (exceptions || [])
      .filter((exception) =>
        String(exception?.status || '').toLowerCase() === 'scheduled'
          && sameCourseDate(exception, targetDate, courseId)
      )
      .map((exception) => normalizeExceptionStartTime(exception.start_time))
      .filter(Boolean)
  );
}

export function shouldRenderScheduledException(exception, exceptions = [], targetDate) {
  if (String(exception?.status || '').toLowerCase() !== 'scheduled') return false;
  const courseId = exception?.student_course_id;
  if (courseId == null) return true;
  return !hasLeaveExceptionForCourseDate(exceptions, targetDate, courseId);
}
