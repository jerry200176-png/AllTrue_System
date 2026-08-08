function pad2(value) {
  return String(value).padStart(2, '0');
}

function parseYmd(value) {
  const [year, month, day] = String(value || '').slice(0, 10).split('-').map(Number);
  if (!year || !month || !day) return null;
  return new Date(year, month - 1, day);
}

function toYmd(date) {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function addDays(ymd, days) {
  const date = parseYmd(ymd);
  if (!date) return '';
  date.setDate(date.getDate() + days);
  return toYmd(date);
}

function maxYmd(...values) {
  const sorted = values.filter(Boolean).sort((a, b) => a.localeCompare(b));
  return sorted[sorted.length - 1] || '';
}

/** Makeup lessons must be scheduled after the missed lesson. */
export function getMakeupCandidateWindow(workflow, now = new Date(), spanDays = 14) {
  const today = toYmd(now);
  const sourceDate = String(workflow?.class_session?.date || '').slice(0, 10);
  const startDate = maxYmd(addDays(today, 1), sourceDate ? addDays(sourceDate, 1) : '');

  return {
    sourceDate,
    startDate,
    endDate: addDays(startDate, Math.max(0, Number(spanDays) - 1)),
};
}
