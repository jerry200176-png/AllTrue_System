// in-app #418: per-course lesson-date list for the parent portal course cards.
// Input is the API's `attended_lessons` (newest first), the same evidence the
// 已上 counter counts. Numbering counts from the first lesson.
const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];
export const LESSON_PREVIEW_LIMIT = 10;

export function formatLessonDate(ymd) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
  if (!m) return ymd ? String(ymd) : '日期未記錄';
  const [, y, mo, d] = m;
  const weekday = WEEKDAYS[new Date(Date.UTC(Number(y), Number(mo) - 1, Number(d))).getUTCDay()];
  return `${Number(mo)}/${Number(d)}（${weekday}）`;
}

export function courseLessonRows(course) {
  const lessons = Array.isArray(course?.attended_lessons) ? course.attended_lessons : [];
  return lessons.map((lesson, index) => ({
    key: `${lesson.date}|${lesson.time || ''}|${index}`,
    number: lessons.length - index,
    dateLabel: formatLessonDate(lesson.date),
    time: lesson.time || '',
    note: lesson.note || '',
  }));
}

// One view per card: the summary never repeats 「已上 N 堂」 (the card already shows
// the capped / package-pool number), shows the latest LESSON_PREVIEW_LIMIT rows
// until expanded, and flags lessons beyond the purchased count.
export function courseLessonView(course, showAll = false) {
  const rows = courseLessonRows(course);
  const total = rows.length;
  const visible = showAll ? rows : rows.slice(0, LESSON_PREVIEW_LIMIT);
  const purchased = Number(course?.sessions_purchased) || 0;
  const overPurchased = !course?.is_package && purchased > 0 && total > purchased ? total - purchased : 0;
  return {
    total,
    rows: visible,
    hiddenCount: total - visible.length,
    summary: `${course?.is_package ? '本科上課日期' : '查看上課日期'}（${total} 筆）`,
    overPurchasedNote: overPurchased > 0 ? `另有 ${overPurchased} 堂超出購買堂數，請洽分校確認` : '',
  };
}
