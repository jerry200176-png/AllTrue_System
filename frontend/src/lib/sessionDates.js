/**
 * computeSessionDatesForCourse
 *
 * Given a course object and the list of schedule exceptions (leave / rescheduled),
 * returns a Set<string> of YYYY-MM-DD dates representing exactly N session dates
 * (where N = sessions_purchased or SessionCount).
 *
 * Used by SmartCalendar to reliably show only the N purchased sessions.
 */

function addDays(dateStr, n) {
  const d = new Date(dateStr + 'T12:00:00');
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
}

function dayOfWeek(dateStr) {
  // Returns 1=Mon … 7=Sun
  const d = new Date(dateStr + 'T12:00:00');
  const dow = d.getDay();
  return dow === 0 ? 7 : dow;
}

function getNextWeekdayYmd(targetDow, fromDateStr) {
  const from = fromDateStr
    ? new Date(fromDateStr + 'T12:00:00')
    : new Date();
  let d = new Date(from);
  for (let i = 0; i < 14; i++) {
    const dow = d.getDay() === 0 ? 7 : d.getDay();
    if (dow === targetDow) return d.toISOString().slice(0, 10);
    d.setDate(d.getDate() + 1);
  }
  return from.toISOString().slice(0, 10);
}

/**
 * @param {object} course  - course object from StudentClassController
 * @param {Array}  exceptions - array of schedule exception objects
 * @returns {Set<string>}
 */
export function computeSessionDatesForCourse(course, exceptions = []) {
  const purchased = Math.max(0, Number(
    course.sessions_purchased ?? course.SessionCount ?? 0
  ) || 0);

  // Monthly-billing courses: no fixed session limit, return empty set
  if (!purchased || (course.payment_type && course.payment_type !== 'session')) {
    return new Set();
  }

  // Days of week this course runs on (1=Mon…7=Sun)
  let days = [];
  if (Array.isArray(course.days_of_week) && course.days_of_week.length) {
    days = course.days_of_week.map(Number).filter(d => d >= 1 && d <= 7);
  }
  if (!days.length && Number(course.day_of_week) >= 1) {
    days = [Number(course.day_of_week)];
  }
  if (!days.length) return new Set();

  // Start date
  let firstYmd = course.first_class_date
    ? String(course.first_class_date).slice(0, 10)
    : null;
  if (!firstYmd || firstYmd === 'null' || firstYmd === 'undefined') {
    firstYmd = getNextWeekdayYmd(days[0]);
  }

  // Build leave / rescheduled exception sets for this course
  const courseId = String(course.id ?? course.ID ?? '');
  const leaveDates = new Set();
  const rescheduledFrom = new Set();
  const rescheduledTo = new Set();

  exceptions.forEach((ex) => {
    if (String(ex.student_course_id) !== courseId) return;
    const exDate = ex.schedule_date ? String(ex.schedule_date).slice(0, 10) : null;
    if (!exDate) return;
    if (ex.status === 'leave') leaveDates.add(exDate);
    if (ex.status === 'rescheduled') rescheduledFrom.add(exDate);
    if (ex.type === 'normal' && ex.status === 'scheduled' && ex.original_schedule_id) {
      rescheduledTo.add(exDate);
    }
    if (ex.type === 'extra' && ex.status === 'scheduled') rescheduledTo.add(exDate);
  });

  const dateSet = new Set();
  let current = firstYmd;
  const maxIter = purchased * 52 + 100; // safety cap
  let iter = 0;

  while (dateSet.size < purchased && iter < maxIter) {
    iter++;
    const curDow = dayOfWeek(current);

    if (days.includes(curDow)) {
      const ymd = current;
      // This is a scheduled day — check exceptions
      if (rescheduledFrom.has(ymd)) {
        // Original slot was rescheduled away — skip it, but count the rescheduled-to date
        // (it will be added when we reach that date in rescheduledTo)
      } else if (!leaveDates.has(ymd)) {
        dateSet.add(ymd);
      }
      // Rescheduled-to date (add-on): don't count against the N sessions limit
    }

    // Also include explicitly rescheduled-to dates that are not in the regular pattern
    // but we handle those naturally by walking forward

    current = addDays(current, 1);
  }

  // Add any explicitly rescheduled-to dates (extra slots outside normal pattern)
  rescheduledTo.forEach((ymd) => {
    if (ymd >= firstYmd) dateSet.add(ymd);
  });

  return dateSet;
}

/**
 * 預覽過去上課日：依開課日、排課星期幾、堂數，算出前 N 堂的日期列表。
 * 用於補登時讓使用者對照「系統上線前理論上有課的日期」。
 * @param {string} firstClassDate - YYYY-MM-DD 開課日
 * @param {number[]} daysOfWeek - 排課星期幾 [1-7] 1=週一…7=週日
 * @param {number} count - 要列出的堂數（例如已上堂數）
 * @returns {string[]} 日期字串 YYYY-MM-DD 陣列
 */
export function previewSessionDates(firstClassDate, daysOfWeek, count) {
  const n = Math.max(0, Number(count) || 0);
  if (n === 0) return [];

  let days = [];
  if (Array.isArray(daysOfWeek) && daysOfWeek.length) {
    days = daysOfWeek.map(Number).filter(d => d >= 1 && d <= 7);
  }
  if (!days.length) return [];

  const firstYmd = firstClassDate ? String(firstClassDate).slice(0, 10) : null;
  if (!firstYmd || firstYmd === 'null' || firstYmd === 'undefined') return [];

  const out = [];
  let current = firstYmd;
  const maxIter = n * 52 + 100;
  let iter = 0;

  while (out.length < n && iter < maxIter) {
    iter++;
    const curDow = dayOfWeek(current);
    if (days.includes(curDow)) {
      out.push(current);
    }
    current = addDays(current, 1);
  }

  return out;
}
