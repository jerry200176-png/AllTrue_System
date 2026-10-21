// in-app #418: per-course 「已上 N 堂」 date list for the parent portal course cards.
// Input is the API's `attended_lessons` (newest first); numbering counts from the
// first lesson so the newest row carries the same number as the card's 已上 count.
const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

export function formatLessonDate(ymd) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
  if (!m) return String(ymd || '');
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
