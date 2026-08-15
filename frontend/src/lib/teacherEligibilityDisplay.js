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
