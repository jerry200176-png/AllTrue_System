/**
 * Calendar view display helpers.
 *
 * Keep date presentation local-time and independent from the occurrence
 * merge/write contracts. These helpers only answer what the current view is
 * showing; they never infer or mutate scheduling truth.
 */

function parseLocalYmd(value) {
  const raw = String(value || '').slice(0, 10);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
  if (!match) return null;
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12);
  return Number.isNaN(date.getTime()) ? null : date;
}

function shortDate(value) {
  const date = parseLocalYmd(value);
  if (!date) return '';
  return `${date.getFullYear()}/${date.getMonth() + 1}/${date.getDate()}`;
}

export function formatCalendarRange(start, end) {
  const startLabel = shortDate(start);
  const endLabel = shortDate(end);
  if (!startLabel && !endLabel) return '尚未選定日期';
  if (!endLabel || startLabel === endLabel) return startLabel || endLabel;
  return `${startLabel}–${endLabel}`;
}

export function calendarViewLabel({ viewMode = 'week', isWeekOverview = false } = {}) {
  if (viewMode === 'teacher') return '老師清單';
  return isWeekOverview ? '週檢視' : '日檢視';
}

export function scheduleDiscrepancyActionLabel(status) {
  if (status === 'pending') return '接手處理';
  if (status === 'acknowledged') return '繼續處理';
  if (status === 'resolved') return '查看處理結果';
  return '查看回報';
}
