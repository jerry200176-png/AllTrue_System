// SmartCalendar 純日期工具（#740 Step 1 — Leaf-First / Pure Move 自 SmartCalendar.vue 剝離）。
// 這些函式 100% 純：輸入 → 輸出，不依賴任何 Vue reactive state，可獨立單元測試。
// ⚠️ 行為等價：函式體一字未改，僅加上 export。請勿在此微調邏輯（會影響週檢視/排課日期計算）。

/** 依本地時區輸出 YYYY-MM-DD，避免 toISOString() 在 UTC+8 造成日期少一天 */
export function formatLocalDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** 從今天起算，下一個指定星期幾的日期 YYYY-MM-DD（dow 1=週一 … 7=週日） */
export function getNextWeekdayYmd(dow) {
  const d = new Date();
  d.setHours(12, 0, 0, 0);
  const current = d.getDay() === 0 ? 7 : d.getDay();
  let diff = (Number(dow) || 1) - current;
  if (diff <= 0) diff += 7;
  d.setDate(d.getDate() + diff);
  return formatLocalDate(d);
}

/** 該月第 N 週的週一日期 YYYY-MM-DD */
export function getMondayOfMonthWeek(year, month, weekNum) {
  const first = new Date(year, month - 1, 1);
  let mon = new Date(first);
  const day = first.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  mon.setDate(first.getDate() + diff);
  mon.setDate(mon.getDate() + (weekNum - 1) * 7);
  return formatLocalDate(mon);
}

/** 將 API/DB 回傳的日期正規為 YYYY-MM-DD（Supabase 可能回傳 ISO 字串） */
export function toYmd(val) {
  if (val == null) return '';
  return String(val).slice(0, 10);
}

/** 日期加減天數，回傳 YYYY-MM-DD */
export function addDays(ymd, days) {
  const d = new Date(ymd + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return formatLocalDate(d);
}

/** 給定 YYYY-MM-DD，回傳該日屬於當月第幾週 (1–5)，與週檢視的 displayWeek 定義一致 */
export function getWeekNumberOfDate(ymd) {
  const d = new Date(ymd + 'T12:00:00');
  const year = d.getFullYear();
  const month = d.getMonth() + 1;
  const dow = d.getDay();
  const toMonday = dow === 0 ? -6 : 1 - dow;
  const mondayOfDate = addDays(ymd, toMonday);
  const firstMonday = getMondayOfMonthWeek(year, month, 1);
  const a = new Date(mondayOfDate + 'T12:00:00').getTime();
  const b = new Date(firstMonday + 'T12:00:00').getTime();
  const diffDays = Math.round((a - b) / (24 * 60 * 60 * 1000));
  const weekNum = Math.floor(diffDays / 7) + 1;
  return Math.max(1, Math.min(5, weekNum));
}
