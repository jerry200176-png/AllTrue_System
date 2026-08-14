export const EVENT_KIND_HINTS = {
  holiday: '這是登記「全分校這一天放假」，用來算正職老師的假日16小時。不是幫某個老師請假。',
  official_closure: '這是登記「全分校這一天因官方活動或統一公休不上課」。',
  leave: '這才是幫某一位老師補登系統沒抓到的請假或補課時數。',
};

export function eventKindHint(eventType) {
  return EVENT_KIND_HINTS[eventType] || EVENT_KIND_HINTS.holiday;
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
