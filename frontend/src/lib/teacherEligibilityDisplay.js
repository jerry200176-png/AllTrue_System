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
