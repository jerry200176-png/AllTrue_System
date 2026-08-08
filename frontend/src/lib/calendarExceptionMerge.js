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
  if (hasLeaveExceptionForCourseDate(exceptions, targetDate, courseId)) return false;
  // A course/date can end up with more than one 'scheduled' marker when a slot is
  // rescheduled again in quick succession — the backend's dedupe delete (keyed on
  // exact old start_time) doesn't always catch a marker whose own start_time was
  // never the "vacated" slot (e.g. a same-time re-submit chain). Rather than trust
  // the backend to always leave exactly one row, treat the highest-id 'scheduled'
  // entry for this course/date as authoritative and drop earlier ones, so a stale
  // superseded marker never renders as a second calendar box next to the real one.
  const latestId = (exceptions || [])
    .filter((ex) => String(ex?.status || '').toLowerCase() === 'scheduled' && sameCourseDate(ex, targetDate, courseId))
    .reduce((max, ex) => Math.max(max, Number(ex?.id) || 0), 0);
  return !latestId || Number(exception?.id) >= latestId;
}
