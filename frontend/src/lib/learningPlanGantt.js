// #4970 step 4b: date/column math for the per-student learning-plan Gantt.
// Every date is a date-only 'YYYY-MM-DD' string. Days are counted as whole UTC day numbers, so there is no
// timezone or DST drift (never `new Date('YYYY-MM-DD')` + local getters).
const YMD = /^(\d{4})-(\d{2})-(\d{2})/;
const DAY_MS = 86400000;

/** Whole day number of a date string (UTC-based, DST free); NaN when not a date. */
export function dayNumber(ymd) {
  const m = YMD.exec(String(ymd ?? ''));
  return m ? Math.floor(Date.UTC(+m[1], +m[2] - 1, +m[3]) / DAY_MS) : NaN;
}

export function addDays(ymd, n) {
  // eslint-disable-next-line no-restricted-syntax -- formats a UTC day number back to a calendar date, not "now"
  return new Date((dayNumber(ymd) + n) * DAY_MS).toISOString().slice(0, 10);
}

/** '2026-12-01' -> '12/01' */
export function formatMd(ymd) {
  const m = YMD.exec(String(ymd ?? ''));
  return m ? `${m[2]}/${m[3]}` : '';
}

/**
 * Week columns from plan_start. Column i covers plan_start + 7i .. + 7i + 6; a plan shorter than a week has one
 * column. `months` groups consecutive columns by the month of the day the column starts on.
 */
export function buildTimeline(planStart, planEnd) {
  const first = dayNumber(planStart);
  const last = Math.max(dayNumber(planEnd), first);
  if (Number.isNaN(first)) return { start: planStart, weeks: 0, totalDays: 0, columns: [], months: [] };
  const totalDays = last - first + 1;
  const weeks = Math.ceil(totalDays / 7);
  const columns = [];
  const months = [];
  for (let i = 0; i < weeks; i += 1) {
    const date = addDays(planStart, i * 7);
    columns.push({ index: i, date, label: formatMd(date) });
    const month = Number(date.slice(5, 7));
    const tail = months[months.length - 1];
    if (tail && tail.month === month) tail.span += 1;
    else months.push({ month, label: `${month}月`, startColumn: i + 1, span: 1 });
  }
  return { start: planStart, weeks, totalDays, columns, months };
}

const weekOf = (timeline, ymd) => Math.min(Math.max(Math.floor((dayNumber(ymd) - dayNumber(timeline.start)) / 7), 0), timeline.weeks - 1);

/** CSS grid lines (1-based, end exclusive) of a unit bar; both ends are clamped into the plan range. */
export function barSpan(timeline, unit) {
  const a = weekOf(timeline, unit.planned_start);
  const b = Math.max(weekOf(timeline, unit.planned_end), a);
  return { colStart: a + 1, colEnd: b + 2 };
}

/** 0..100 position of a date across the whole chart, or null when it falls outside the plan range. */
export function markerPercent(timeline, ymd) {
  const off = dayNumber(ymd) - dayNumber(timeline.start);
  if (Number.isNaN(off) || off < 0 || off >= timeline.totalDays) return null;
  return ((off + 0.5) / (timeline.weeks * 7)) * 100;
}

export const STATE_LABELS = { done: '已完成', behind: '落後', in_progress: '進行中', upcoming: '未開始' };

/** done wins over behind: a unit ticked after its planned end is done, not behind. */
export function unitState(unit, today) {
  if (unit.completed_at) return 'done';
  const t = dayNumber(today);
  if (dayNumber(unit.planned_end) < t) return 'behind';
  if (dayNumber(unit.planned_start) <= t) return 'in_progress';
  return 'upcoming';
}

export function dateRangeText(unit) {
  return `${formatMd(unit.planned_start)}–${formatMd(unit.planned_end)}`;
}

/** Screen-reader sentence: 第 3 單元 xx：12/01–12/14，落後 */
export function unitAriaText(unit, index, today) {
  return `第 ${index + 1} 單元 ${unit.title}：${dateRangeText(unit)}，${STATE_LABELS[unitState(unit, today)]}${unit.dates_manual ? '，手動排程' : ''}`;
}

export function summaryText(plan) {
  const exam = plan.next_exam;
  const examText = !exam ? '計畫期間內沒有後續段考'
    : exam.days_left === 0 ? `${exam.label} 就是今天` : `距離${exam.label}還有 ${exam.days_left} 天`;
  return { percent: `完成 ${plan.percent_done ?? 0}%`, behind: `落後 ${plan.units_behind ?? 0} 個單元`, exam: examText };
}

/** The API only returns next_exam, so that is the only exam marker; empty when it is outside the chart. */
export function examMarkers(timeline, plan) {
  const exam = plan.next_exam;
  const percent = exam ? markerPercent(timeline, exam.start_date) : null;
  return percent === null ? [] : [{ label: exam.label, date: exam.start_date, percent }];
}
