export const EVENT_KIND_HINTS = {
  holiday: '少用。全校放假請在課程管理用「連假批次請假」，薪資會自動讀取，不必在這裡再登一次。',
  official_closure: '少用。官方活動或統一公休也請先在課程管理處理堂次；這裡只補薪資例外資料。',
  leave: '課表與點名的請假會自動讀取。這裡只在系統沒抓到時才補。',
};

export function eventKindHint(eventType) {
  return EVENT_KIND_HINTS[eventType] || EVENT_KIND_HINTS.leave;
}

export function eventKindLabel(eventType) {
  return ({ holiday: '假日', official_closure: '官方活動／統一公休', leave: '請假／補課抵扣' }[eventType] || '薪資事件');
}

export function eventSubtitle(row, teacherName) {
  const date = row?.event_date || '未填日期';
  if (row?.event_type === 'leave') {
    return `${date}｜${teacherName}`;
  }
  return `${date}｜全分校`;
}

export function formatMoney(value) {
  if (value == null || value === '') return '—';
  const n = Number(value);
  return `$${n.toLocaleString('zh-TW', { maximumFractionDigits: 0 })}`;
}

/** A locked month next to today's rules: now minus locked. */
export function formatDifference(value) {
  if (value == null || value === '') return '無法比較';
  const n = Number(value);
  if (n === 0) return '相同';
  return `${n > 0 ? '多' : '少'} ${formatMoney(Math.abs(n))}`;
}

const WEEKDAY_NAMES = ['一', '二', '三', '四', '五', '六', '日'];

/** Whole numbers stay whole, anything else keeps up to two decimals. */
function plainNumber(value) {
  return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 2 });
}

/**
 * One week of the 每週16段 check, in words a director can act on: the verdict with how far short it is, then what
 * each day held and how many lessons were left out, so a short week explains itself.
 */
export function weekExplanation(week) {
  const total = Number(week?.total_segments ?? 0);
  const threshold = Number(week?.segment_threshold ?? 16);
  let verdict = `未達（差 ${plainNumber(Math.max(0, threshold - total))} 段）`;
  if (week?.closure_dates?.length) verdict = '停課週不列入';
  else if (week?.finished === false) verdict = '還沒結束，先不判定';
  else if (week?.meets_16_segments) verdict = '達標';

  // The seven dates of the week, by calendar arithmetic on the Y-m-d string itself (no clock, no time zone).
  const days = [];
  const [year, month, day] = String(week?.week_start || '').split('-').map(Number);
  for (let i = 0; i < 7 && year && month && day; i += 1) {
    const at = new Date(Date.UTC(year, month - 1, day + i));
    const date = `${at.getUTCFullYear()}-${String(at.getUTCMonth() + 1).padStart(2, '0')}-${String(at.getUTCDate()).padStart(2, '0')}`;
    days.push(`${WEEKDAY_NAMES[i]} ${plainNumber(week?.daily_hours?.[date] ?? 0)}`);
  }
  const left = [];
  if (Number(week?.uncounted?.cancelled) > 0) left.push(`${week.uncounted.cancelled} 堂取消`);
  if (Number(week?.uncounted?.moved) > 0) left.push(`${week.uncounted.moved} 堂改期（算在改到的那一天）`);
  // A day most of the company was off, but not everyone: say so when that is why the week was not waived. A week
  // that was waived anyway (it also has a fully closed day) needs no such explanation.
  const near = (week?.closure_dates?.length ? [] : week?.near_closures || []).map((day) => `${day.date.slice(5).replace('-', '/')} 全公司 ${day.total} 堂課有 ${day.total - day.open} 堂停課，還有 ${day.open} 堂照常上課，所以這一週沒有當成停課週`);
  return {
    verdict: `${plainNumber(total)} 段・${verdict}`,
    detail: `每天排課時數：${days.join('、')}${left.length ? `。另有 ${left.join('、')}，不算在內` : ''}${near.length ? `。${near.join('；')}` : ''}`,
  };
}

/**
 * Who the campus may enter payroll inputs for: the teachers it settles plus its teachers another campus settles.
 * A record entered here still reaches the settlement, which reads a teacher's records from every campus.
 */
export function inputRoster(teachers, settledElsewhere) {
  return [...(teachers || []), ...(settledElsewhere || []).map((row) => ({ teacher_id: row.teacher_id, teacher_name: row.teacher_name }))];
}

/**
 * The rows of the 需要你處理 list: one per item that still blocks a teacher's settlement, or one general row for a
 * teacher marked for review with no item named.
 */
export function attentionItems(teachers) {
  const rows = [];
  for (const teacher of teachers || []) {
    const items = teacher.pending_items?.length ? teacher.pending_items : (teacher.settlement?.pending_items || []);
    for (const item of items) {
      rows.push({ key: `${teacher.teacher_id}-${item.code}`, teacherName: teacher.teacher_name, label: item.label, reason: item.reason || '資料尚待確認。' });
    }
    if (!items.length && teacher.review_required) {
      rows.push({ key: `${teacher.teacher_id}-review`, teacherName: teacher.teacher_name, label: '待人工確認', reason: '有項目還沒確認，請看下方的資料補登與審核。' });
    }
  }
  return rows;
}

/** What to write under a locked total once the comparison has been asked for; '' before that. */
export function recalculationText(teacher) {
  if (!teacher || !('recalculated' in teacher)) return '';
  const now = teacher.recalculated;
  if (!now) return '現在的算法：無法比較（已不在這個分校）';
  if (now.difference == null) return '現在的算法：還有待確認項目，暫不比較';
  return `現在的算法 ${formatMoney(now.calculated_payout)}（${formatDifference(now.difference)}）`;
}

/**
 * One line for the whole month: how many locked rows differ under today's rules and the net amount. A row that could
 * not be compared (the teacher is no longer listed, or today's result still has items to confirm) is counted and
 * named, so the line never claims more than was compared.
 */
export function recalculationSummary(teachers) {
  const asked = (teachers || []).filter((teacher) => 'recalculated' in teacher);
  if (!asked.length) return '';
  const compared = asked.filter((teacher) => teacher.recalculated?.difference != null);
  const changed = compared.filter((teacher) => Number(teacher.recalculated.difference) !== 0);
  const skipped = asked.length - compared.length;
  const rest = skipped ? `另有 ${skipped} 位無法比較（已不在這個分校，或現在還有待確認項目）。` : '';
  if (!compared.length) return `照現在的算法重算：${rest}`;
  if (!changed.length) return `照現在的算法重算，比較得到的 ${compared.length} 位老師金額都和鎖定時相同。${rest}`;
  const net = changed.reduce((sum, teacher) => sum + Number(teacher.recalculated.difference), 0);
  return `照現在的算法重算，${changed.length} 位老師金額不同，合計${formatDifference(net)}。${rest}鎖定的數字沒有變。`;
}

export function formatSubjects(value) {
  if (value == null || value === '') return '—';
  return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 4 });
}

/** 正課／輔導試聽 arrive as raw units; ÷8 puts them on the 核薪總科目數 scale so the three columns add up. */
export function formatSubjectUnits(value) {
  return formatSubjects(value == null || value === '' ? value : Number(value) / 8);
}

/** The parts of the 教師倍率 in words: 「基本 100%、平日下午 +2.94%」; parts worth 0 are left out. */
export function multiplierBreakdown(settlement) {
  return (settlement?.multiplier_parts || []).filter((part) => Number(part.pct) !== 0)
    .map((part, index) => `${part.label} ${index > 0 && Number(part.pct) > 0 ? '+' : ''}${Number(part.pct)}%`).join('、');
}

/** How the 一對三獎金 comes about: 「一對三總計 3 × 100」. */
export function oneToThreeFormula(settlement) {
  return `一對三總計 ${formatSubjects(settlement?.one_to_three_count)} × 100`;
}

/** The 教師倍率 as a figure; 「—」 while a part of it still awaits confirmation (the settlement sends null then). */
export function multiplierValue(settlement) {
  return settlement?.multiplier_pct == null ? '—' : `${Number(settlement.multiplier_pct)}%`;
}

/** Said next to an unconfirmed 教師倍率: the trial rate the draft amount was worked out with. */
export function multiplierPendingNote(settlement) {
  if (settlement?.multiplier_pct != null || settlement?.known_multiplier_pct == null) return '';
  return `還有項目沒確認，先用 ${Number(settlement.known_multiplier_pct)}% 試算`;
}

/**
 * The 加扣款 lines. The settlement puts the 16 段課 bonus first among them and the page gives that bonus its own
 * line, so that one entry is left out: the first with the bonus's label and amount. A cash adjustment someone
 * happened to name the same stays.
 */
export function cashAdjustments(settlement) {
  const items = settlement?.adjustments || [];
  const bonus = Number(settlement?.weekly_segment_bonus_amount || 0);
  const generated = bonus ? items.findIndex((item) => item.label === '16段課' && Number(item.amount) === bonus) : -1;
  return generated < 0 ? items : items.filter((_, index) => index !== generated);
}

/** Nothing on the calendar and nothing to pay or settle: the teacher needs no look this month. */
export function hasNoLessons(teacher) {
  // A locked month compared with today's rules: a teacher whose result differs, or cannot be told, stays in view.
  if (teacher && 'recalculated' in teacher) {
    const again = teacher.recalculated;
    if (!again || again.calculation_status !== 'calculated' || Number(again.difference || 0) !== 0) return false;
  }
  const segments = Number(teacher?.components?.weekly_16_segments?.metrics?.total_segments || 0);
  return segments === 0 && Number(teacher?.settlement?.total_payout || 0) === 0 && !teacher?.review_required && !teacher?.settlement?.payout_is_draft;
}

/** One line under a teacher's name: what the amount rests on, and the first thing still open. */
export function teacherSummaryLine(teacher) {
  const parts = [`科目數 ${formatSubjects(teacher?.settlement?.payroll_subject_count)} 科`];
  const metrics = teacher?.components?.weekly_16_segments?.metrics || {};
  const weeks = Number(metrics.week_count || 0);
  const full = Number(metrics.qualifying_weeks || 0);
  if (weeks && Number(metrics.unfinished_weeks || 0)) parts.push(`還有 ${metrics.unfinished_weeks} 週沒結束`);
  else if (weeks && full >= weeks) parts.push('每週都滿 16 段');
  else if (weeks) parts.push(`${weeks} 週裡有 ${weeks - full} 週沒滿 16 段`);
  if (teacher?.settlement?.new_hire_guarantee_applied) parts.push('新進保底');
  const pending = (teacher?.pending_items?.length ? teacher.pending_items : teacher?.settlement?.pending_items) || [];
  if (pending.length) parts.push(`${pending[0].label}還沒處理完`);
  return parts.join('・');
}

export function formatPct(value) {
  if (value == null || value === '') return '—';
  const n = Number(value);
  const sign = n > 0 ? '+' : '';
  return `${sign}${n}%`;
}

export function weightedBonusFormula(settlement) {
  if (settlement?.subject_count_bonus == null
    || settlement?.one_to_three_bonus == null
    || settlement?.multiplier_pct == null
    || settlement?.weighted_bonus_amount == null) return '—（資料尚待確認）';
  const subject = Number(settlement.subject_count_bonus);
  const oneToThree = Number(settlement.one_to_three_bonus);
  const multiplier = Number(settlement.multiplier_pct);
  const weighted = Number(settlement.weighted_bonus_amount);
  // 新進保底: show what the formula gave and that the guarantee replaced it.
  if (settlement.new_hire_guarantee_applied) {
    return `(${formatMoney(subject)} + ${formatMoney(oneToThree)}) × ${multiplier}% = ${formatMoney(Number(settlement.weighted_bonus_before_guarantee))}，新進保底 ${formatMoney(weighted)}`;
  }
  return `(${formatMoney(subject)} + ${formatMoney(oneToThree)}) × ${multiplier}% = ${formatMoney(weighted)}`;
}

export function monthWindow(yearMonth) {
  const [year, month] = String(yearMonth || '').split('-').map(Number);
  if (!year || !month) return { start: '', end: '' };
  const lastDay = new Date(year, month, 0).getDate();
  const pad = (n) => String(n).padStart(2, '0');
  return { start: `${year}-${pad(month)}-01`, end: `${year}-${pad(month)}-${pad(lastDay)}` };
}
