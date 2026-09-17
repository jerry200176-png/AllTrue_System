/**
 * Phase 0+1a helpers for the Course Management–hosted session calendar.
 * Read model only + create intent routing — no cancel / time / teacher edit.
 */

import perfFlags from '../../lib/perfFlags.js';

const WEEKDAY_LABELS = ['日', '一', '二', '三', '四', '五', '六'];

/** @param {{ COURSE_SESSION_CALENDAR_V1?: boolean }} [flags] */
export function isCourseSessionCalendarEnabled(flags = perfFlags) {
  return flags?.COURSE_SESSION_CALENDAR_V1 === true;
}

/**
 * @param {string|Date|null|undefined} value
 * @returns {string} YYYY-MM-DD
 */
export function toYmd(value) {
  if (!value) return '';
  if (typeof value === 'string') return String(value).slice(0, 10);
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    const y = value.getFullYear();
    const m = String(value.getMonth() + 1).padStart(2, '0');
    const d = String(value.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }
  return '';
}

/**
 * @param {Array<{ date?: string, isProjected?: boolean, kind?: string, id?: number|null, startTime?: string, status?: string }>} sessions
 * @returns {Map<string, Array<object>>}
 */
export function groupSessionsByDate(sessions = []) {
  const byDate = new Map();
  for (const row of sessions || []) {
    const date = toYmd(row?.date);
    if (!date) continue;
    if (!byDate.has(date)) byDate.set(date, []);
    byDate.get(date).push(row);
  }
  return byDate;
}

/**
 * Build a 6×7 month grid for the calendar surface.
 *
 * @param {{ year: number, month: number, sessions?: Array<object>, todayYmd?: string }} opts
 * month is 1-based.
 */
export function buildCourseSessionCalendarCells({
  year,
  month,
  sessions = [],
  todayYmd = toYmd(new Date()),
} = {}) {
  const y = Number(year);
  const m = Number(month);
  if (!Number.isFinite(y) || !Number.isFinite(m) || m < 1 || m > 12) return [];

  const byDate = groupSessionsByDate(sessions);
  const first = new Date(y, m - 1, 1);
  const startPad = first.getDay(); // 0=Sun
  const daysInMonth = new Date(y, m, 0).getDate();
  const totalCells = Math.ceil((startPad + daysInMonth) / 7) * 7;
  const cells = [];

  for (let i = 0; i < totalCells; i += 1) {
    const dayNum = i - startPad + 1;
    const inMonth = dayNum >= 1 && dayNum <= daysInMonth;
    const dateObj = new Date(y, m - 1, dayNum);
    const date = toYmd(dateObj);
    const daySessions = inMonth ? (byDate.get(date) || []) : [];
    const hasMaterialized = daySessions.some((s) => !s.isProjected && s.kind !== 'projected');
    const hasProjected = daySessions.some((s) => s.isProjected || s.kind === 'projected');
    const isFutureOrToday = Boolean(date && todayYmd && date >= todayYmd);
    const isEmpty = daySessions.length === 0;

    cells.push({
      key: `${date || 'pad'}-${i}`,
      date: inMonth ? date : '',
      day: inMonth ? dayNum : dateObj.getDate(),
      inMonth,
      sessions: daySessions,
      hasMaterialized,
      hasProjected,
      isEmpty,
      isFutureOrToday,
      /** Phase 1a: only empty future/today cells may request CREATE */
      canCreate: inMonth && isEmpty && isFutureOrToday,
    });
  }

  return cells;
}

export function courseSessionCalendarMonthLabel(year, month) {
  return `${year}年${month}月`;
}

export function courseSessionCalendarWeekdayLabels() {
  return WEEKDAY_LABELS;
}

/**
 * Route create intent to the existing Course Management writers.
 * @returns {'manual-sessions'|'add-session'|'none'}
 */
export function resolveCourseSessionCreateWriter(course) {
  if (!course) return 'none';
  if (String(course.status || '') === 'inactive') return 'none';
  // Row primary CTA (＋新增下一堂 / 排課 / 排月結) always uses manual-sessions.
  return 'manual-sessions';
}

/**
 * Optional secondary writer for count-mode makeup (補課/補登).
 * @returns {boolean}
 */
export function canOfferQuickAddFromCalendar(course) {
  if (!course) return false;
  if ((course.payment_type || 'session') !== 'session') return false;
  if (String(course.scheduling_policy || 'auto_recurrence') === 'manual_occurrence') return false;
  if (String(course.status || '') === 'inactive') return false;
  return true;
}

/**
 * Payloads for Phase 1a create — only fields already required by existing writers.
 * Never includes teacher edits or cancel status.
 */
export function buildManualSessionCreatePayload({ session_date, start_time }) {
  return {
    session_date: toYmd(session_date),
    start_time: String(start_time || '').slice(0, 5),
  };
}

export function buildAddSessionCreatePayload({
  session_date,
  start_time,
  duration_minutes,
  note = null,
  auto_approve = true,
}) {
  return {
    session_date: toYmd(session_date),
    start_time: String(start_time || '').slice(0, 5),
    duration_minutes: Number(duration_minutes) || 0,
    note: note || null,
    auto_approve: !!auto_approve,
  };
}
