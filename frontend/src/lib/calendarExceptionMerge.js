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

// #3914: a leave marker with a start time names one lesson (a date can hold two); one without a time covers the course/date.
function leaveCoversStart(exception, startTime) {
  const at = normalizeExceptionStartTime(exception?.start_time);
  const want = normalizeExceptionStartTime(startTime);
  return !at || !want || at === want;
}

export function hasLeaveExceptionForCourseDate(exceptions = [], targetDate, courseId, startTime = null) {
  return (exceptions || []).some((exception) =>
    String(exception?.status || '').toLowerCase() === 'leave'
      && sameCourseDate(exception, targetDate, courseId)
      && leaveCoversStart(exception, startTime)
  );
}

export function scheduledExceptionStartSetForCourseDate(exceptions = [], targetDate, courseId) {
  return new Set(
    (exceptions || [])
      .filter((exception) =>
        String(exception?.status || '').toLowerCase() === 'scheduled'
          && sameCourseDate(exception, targetDate, courseId)
      )
      .map((exception) => normalizeExceptionStartTime(exception.start_time))
      .filter((start) => start && !hasLeaveExceptionForCourseDate(exceptions, targetDate, courseId, start))
  );
}

export function shouldRenderScheduledException(exception, exceptions = [], targetDate) {
  if (String(exception?.status || '').toLowerCase() !== 'scheduled') return false;
  const courseId = exception?.student_course_id;
  if (courseId == null) return true;
  if (hasLeaveExceptionForCourseDate(exceptions, targetDate, courseId, exception?.start_time)) return false;
  // A 'scheduled' marker represents one specific (course, date, start_time) slot. If
  // that exact slot was itself later rescheduled again — a same-slot 'rescheduled'
  // marker created after this one — this entry is stale/superseded and must not
  // render, even when the later reschedule's own destination lands on a *different*
  // date entirely (a same-date-only "keep the latest" dedupe misses that case; the
  // backend's own dedupe-delete, keyed on the rescheduled marker's exact start_time,
  // also doesn't always catch it — see two real production incidents, #1685 and the
  // follow-up in-app #225: same-time re-submit, and reschedule-to-another-date).
  const exStart = normalizeExceptionStartTime(exception?.start_time);
  const supersededBy = (exceptions || []).find((ex) =>
    String(ex?.status || '').toLowerCase() === 'rescheduled'
      && sameCourseDate(ex, targetDate, courseId)
      && normalizeExceptionStartTime(ex?.start_time) === exStart
      && Number(ex?.id) > Number(exception?.id)
  );
  return !supersededBy;
}
