function localTodayYmd() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

function addDays(ymd, days) {
  const date = new Date(`${ymd}T12:00:00`);
  date.setDate(date.getDate() + days);
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

function dayOfWeekFromDate(ymd) {
  const day = new Date(`${ymd}T12:00:00`).getDay();
  return day === 0 ? 7 : day;
}

/**
 * Pick a safe initial date for the "add next session" form.
 *
 * The result is deterministic when todayYmd/currentTime are supplied, which
 * keeps date defaults testable without coupling tests to the machine clock.
 */
export function nextManualSessionDate(course, { todayYmd = localTodayYmd(), currentTime } = {}) {
  const configuredDays = Array.isArray(course?.days_of_week) && course.days_of_week.length
    ? course.days_of_week
    : (course?.day_of_week ? [course.day_of_week] : []);
  const days = new Set(configuredDays.map(Number).filter((day) => day >= 1 && day <= 7));
  const now = currentTime || `${String(new Date().getHours()).padStart(2, '0')}:${String(new Date().getMinutes()).padStart(2, '0')}`;
  const startTime = String(course?.start_time || '').slice(0, 5);

  for (let offset = 0; offset <= 7; offset += 1) {
    const date = addDays(todayYmd, offset);
    const isToday = offset === 0;
    const isValidWeekday = days.size === 0 || days.has(dayOfWeekFromDate(date));
    const isStillUpcoming = !isToday || !startTime || startTime > now;
    if (isValidWeekday && isStillUpcoming) return date;
  }

  return addDays(todayYmd, 1);
}
