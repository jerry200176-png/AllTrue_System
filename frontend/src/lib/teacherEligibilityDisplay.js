export const EVENT_KIND_HINTS = {
  holiday: '少用。全校放假請先在課程管理用「連假批次請假」。這裡只在薪資假日曆缺資料時才補登。',
  official_closure: '少用。官方活動或統一公休也請先在課程管理處理堂次；這裡只補薪資例外資料。',
  leave: '幫某一位老師補登系統沒抓到的請假或補課時數。',
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
  const n = Number(value ?? 0);
  return `$${n.toLocaleString('zh-TW', { maximumFractionDigits: 0 })}`;
}

export function formatSubjects(value) {
  if (value == null || value === '') return '—';
  return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 4 });
}

export function formatPct(value) {
  const n = Number(value ?? 0);
  const sign = n > 0 ? '+' : '';
  return `${sign}${n}%`;
}

export function weightedBonusFormula(settlement) {
  const subject = Number(settlement?.subject_count_bonus ?? 0);
  const oneToThree = Number(settlement?.one_to_three_bonus ?? 0);
  const multiplier = Number(settlement?.multiplier_pct ?? 100);
  const weighted = Number(settlement?.weighted_bonus_amount ?? 0);
  return `(${formatMoney(subject)} + ${formatMoney(oneToThree)}) × ${multiplier}% = ${formatMoney(weighted)}`;
}

export function monthWindow(yearMonth) {
  const [year, month] = String(yearMonth || '').split('-').map(Number);
  if (!year || !month) return { start: '', end: '' };
  const lastDay = new Date(year, month, 0).getDate();
  const pad = (n) => String(n).padStart(2, '0');
  return { start: `${year}-${pad(month)}-01`, end: `${year}-${pad(month)}-${pad(lastDay)}` };
}
