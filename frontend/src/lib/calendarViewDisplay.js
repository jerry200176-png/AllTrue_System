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

/**
 * 日檢視初始捲動位置（px）：最早一堂課上方 leadHours 小時；當日無課則捲到 defaultHour。
 * 不早於 firstHour（欄位標頭為 sticky，不計入）。
 */
export function dayViewScrollTop(startHours, firstHour = 8, { rowHeight = 56, leadHours = 1, defaultHour = 14 } = {}) {
  const valid = (startHours || []).filter(Number.isFinite);
  const target = (valid.length ? Math.min(...valid) - leadHours : defaultHour);
  return Math.max(0, target - firstHour) * rowHeight;
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
