// issue 5269 step 2: date and grouping helpers for the teacher's weekly study plan.
// Dates are date-only 'YYYY-MM-DD' strings; day math reuses learningPlanGantt (UTC day numbers, no local-time parsing).
import { addDays, formatMd } from './learningPlanGantt';
import { getWeekRange } from './calendarPrint.js';

const WEEKDAYS = ['一', '二', '三', '四', '五', '六', '日'];

// Same values as StudentStudyTask::KINDS on the server.
export const KIND_OPTIONS = Object.freeze([
  { value: 'preview', label: '預習' },
  { value: 'homework', label: '作業' },
  { value: 'review', label: '複習' },
  { value: 'correction', label: '訂正' },
  { value: 'other', label: '其他' },
]);
export const KIND_LABELS = Object.freeze(Object.fromEntries(KIND_OPTIONS.map((k) => [k.value, k.label])));

// Monday to Sunday of any day: calendarPrint's getWeekRange (UTC parse of a date-only string), not a second implementation.
export const weekStartOf = (ymd) => getWeekRange(ymd).start;
export const weekDays = (start) => Array.from({ length: 7 }, (_, i) => addDays(start, i));
const weekdayIndex = (ymd) => weekDays(weekStartOf(ymd)).indexOf(ymd);
export const dayLabel = (ymd) => `週${WEEKDAYS[weekdayIndex(ymd)]} ${formatMd(ymd)}`;
export const weekRangeText = (start) => `${formatMd(start)} – ${formatMd(addDays(start, 6))}`;

/** { 'YYYY-MM-DD': task[] } with a key for every day of the week; tasks of other days are ignored. */
export function groupByDay(tasks, days) {
  const out = Object.fromEntries(days.map((d) => [d, []]));
  for (const t of tasks) if (out[t.due_date]) out[t.due_date].push(t);
  return out;
}

/** Units of active plans whose date range touches the week, each tagged with its plan title. */
export function unitsInWeek(plans, start) {
  const end = addDays(start, 6);
  return plans.filter((p) => p.status === 'active')
    .flatMap((p) => (p.units || []).filter((u) => u.planned_start <= end && u.planned_end >= start).map((u) => ({ ...u, plan_title: p.title })));
}

// issue 5269 step 3 (progress strip): three measures from data the page already has. Each returns { status, ... }:
// 'ok' carries the numbers; any other status is a reason to show 「—」 (never a 0% that only means "no data").

/** Tasks done / total of the shown week. No tasks = nothing to measure (not 0%). */
export function weekTaskProgress(tasks) {
  const total = tasks.length;
  if (!total) return { status: 'none' };
  const done = tasks.filter((t) => t.done_at).length;
  return { status: 'ok', done, total, percent: Math.round((done * 100) / total) };
}

/**
 * Plan progress from the plan API's own `percent_done` / `units_behind`. Plans without units are skipped (the API answers 0 for
 * them, which would be a fake 0%). One plan shows the API value as it is; several are averaged by unit count.
 */
export function planProgress(plans) {
  const real = plans.filter((p) => p.status === 'active' && (p.units || []).length > 0);
  if (!real.length) return { status: plans.some((p) => p.status === 'active') ? 'no-units' : 'none' };
  const units = real.reduce((n, p) => n + p.units.length, 0);
  const percent = real.length === 1 ? Number(real[0].percent_done) || 0 : Math.round(real.reduce((n, p) => n + (Number(p.percent_done) || 0) * p.units.length, 0) / units);
  const behind = real.reduce((n, p) => n + (Number(p.units_behind) || 0), 0);
  return { status: 'ok', percent, behind, plans: real.length };
}

// Sign-in labels of the learning timeline (same words as the server's AttendanceStatus): came / did not come. Trial, tutoring,
// duty and 停課 are not part of the ordinary class rate and are ignored.
const CAME = ['到班', '遲到', '補課'];
const MISSED = ['缺席', '請假'];

/**
 * Attendance of the month of `today` from the already loaded learning-timeline items (the profile's own source).
 * The timeline keeps only the newest N rows per source: when a capped source may have cut into the month, say so instead of guessing.
 */
export function attendanceThisMonth(items, today, cappedSources = []) {
  if (!Array.isArray(items)) return { status: 'unavailable' };
  const monthStart = `${today.slice(0, 7)}-01`;
  const counted = CAME.concat(MISSED);
  const mine = items.filter((i) => counted.includes(i.attendance) && i.session_status !== 'cancelled' && i.date >= monthStart && i.date <= today);
  // Each capped source keeps only its newest rows: it may have cut into the month only if its OLDEST kept row is already inside the month.
  const oldestOf = (type) => items.filter((i) => i.type === type).reduce((min, i) => (i.date < min ? i.date : min), '9999-12-31');
  if (['evaluation', 'attendance'].some((type) => cappedSources.includes(type) && oldestOf(type) >= monthStart)) return { status: 'partial' };
  if (!mine.length) return { status: 'none' };
  const came = mine.filter((i) => CAME.includes(i.attendance)).length;
  return { status: 'ok', came, total: mine.length, percent: Math.round((came * 100) / mine.length) };
}
