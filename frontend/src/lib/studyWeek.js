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
