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

/**
 * Who the campus may enter payroll inputs for: the teachers it settles plus its teachers another campus settles.
 * A record entered here still reaches the settlement, which reads a teacher's records from every campus.
 */
export function inputRoster(teachers, settledElsewhere) {
  return [...(teachers || []), ...(settledElsewhere || []).map((row) => ({ teacher_id: row.teacher_id, teacher_name: row.teacher_name }))];
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
