const CLASS_CAPACITY = {
  one_on_one: 1,
  one_on_two: 2,
  one_on_three: 3,
  tutoring: 4,
  trial: 1,
};

const CLASS_LABEL = {
  one_on_one: '一對一',
  one_on_two: '一對二',
  one_on_three: '一對三',
  tutoring: '輔導',
  trial: '試聽',
};

const NON_OCCUPYING_STATUSES = new Set(['cancelled', 'leave', 'leave_adjusted', 'excused']);

function hm(value) {
  const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
  return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function isoDay(date) {
  const parsed = new Date(`${String(date || '').slice(0, 10)}T12:00:00`);
  if (Number.isNaN(parsed.getTime())) return null;
  const day = parsed.getDay();
  return day === 0 ? 7 : day;
}

function overlaps(start, end, course) {
  const existingStart = hm(course.start_time);
  const existingEnd = hm(course.end_time) ?? (existingStart == null ? null : existingStart + (Number(course.duration_hours) || 2) * 60);
  return existingStart != null && existingEnd != null && start < existingEnd && end > existingStart;
}

function courseIsOnDate(course, date, day) {
  if (course.is_exception || course.schedule_date || course.session_date) {
    const concreteDate = course.schedule_date || course.session_date;
    return String(concreteDate || '').slice(0, 10) === date;
  }
  const slotDays = Array.isArray(course.days_of_week) && course.days_of_week.length
    ? course.days_of_week
    : Array.isArray(course.day_time_slots) && course.day_time_slots.length
      ? course.day_time_slots.map((slot) => slot?.day)
      : null;
  if (slotDays) return slotDays.some((slotDay) => Number(slotDay) === day);
  return Number(course.day_of_week) === day;
}

function detailFor(course) {
  const subject = course.subject || course.course_name || '其他課程';
  const start = String(course.start_time || '').slice(0, 5);
  const end = String(course.end_time || '').slice(0, 5);
  return end ? `${subject}（${start}～${end}）` : subject;
}

function courseIdFor(course) {
  return String(course?.student_course_id ?? course?.id ?? '');
}

function isNonOccupyingOnDate(course, date, sessionDatesByCourseId = {}, exceptions = []) {
  const courseId = courseIdFor(course);
  const rows = sessionDatesByCourseId?.[courseId];
  if (Array.isArray(rows) && rows.length > 0) {
    const sameDate = rows.filter((row) =>
      String(row?.session_date || row?.SessionDate || row?.date || '').slice(0, 10) === date,
    );
    if (sameDate.length > 0) {
      return sameDate.every((row) =>
        NON_OCCUPYING_STATUSES.has(String(row?.status || '').toLowerCase()),
      );
    }
  }

  return (Array.isArray(exceptions) ? exceptions : []).some((exception) =>
    String(exception?.student_course_id ?? '') === courseId
      && String(exception?.schedule_date || '').slice(0, 10) === date
      && NON_OCCUPYING_STATUSES.has(String(exception?.status || '').toLowerCase()),
  );
}

/**
 * Lightweight, read-only preview for the calendar reschedule dialog.
 * The API remains the final authority and performs the atomic guard again.
 */
export function buildReschedulePreview({
  courses = [],
  currentCourseId,
  studentId,
  teacherId,
  targetDate,
  startTime,
  endTime,
  classType = 'one_on_one',
  sessionDatesByCourseId = {},
  exceptions = [],
} = {}) {
  const date = String(targetDate || '').slice(0, 10);
  const day = isoDay(date);
  const start = hm(startTime);
  const end = hm(endTime);
  if (!date || day == null || start == null || end == null || end <= start) {
    return { status: 'incomplete', blocked: false, conflicts: [], message: '' };
  }

  const candidates = (Array.isArray(courses) ? courses : []).filter((course) => {
    if (String(course.id) === String(currentCourseId)) return false;
    if (String(course.student_id) === String(studentId) && studentId != null) return false;
    if (String(course.teacher_id) !== String(teacherId)) return false;
    if (isNonOccupyingOnDate(course, date, sessionDatesByCourseId, exceptions)) return false;
    return courseIsOnDate(course, date, day) && overlaps(start, end, course);
  });

  const uniqueStudents = new Set(candidates.map((course) => String(course.student_id ?? course.id)));
  const existingTypes = candidates.map((course) => String(course.class_type || course.ClassType || 'one_on_one'));
  const capacity = CLASS_CAPACITY[classType] || 1;
  let blocked = false;
  let reason = '';

  if (classType === 'trial') {
    blocked = existingTypes.filter((type) => type === 'trial').length > 0;
    reason = blocked ? '試聽時段已有安排' : '';
  } else if (existingTypes.includes('one_on_one')) {
    blocked = true;
    reason = '已有一對一課程';
  } else if (uniqueStudents.size >= 3) {
    blocked = true;
    reason = '老師同時段已達 3 位學生上限';
  } else if (uniqueStudents.size >= capacity) {
    blocked = true;
    reason = `已達${CLASS_LABEL[classType] || '課程'}上限`;
  }

  return {
    status: 'ready',
    blocked,
    conflicts: candidates.slice(0, 4).map(detailFor),
    message: blocked
      ? `${reason}，請改選日期或時間。`
      : candidates.length
        ? `目前時段已有 ${uniqueStudents.size} 位學生；依${CLASS_LABEL[classType] || '課程'}規則可安排。`
        : '目前載入的排課沒有發現衝堂。送出時系統仍會再做最後檢查。',
  };
}
