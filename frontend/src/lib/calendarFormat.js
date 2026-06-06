// SmartCalendar 純格式化工具（#740 Step 2 — Leaf-First / Pure Move 自 SmartCalendar.vue 剝離）。
// 100% 純函式：輸入 → 輸出，不依賴任何 Vue reactive state。
// ⚠️ 行為等價：函式體一字未改，僅加上 export。請勿微調邏輯（影響排課時間/標籤顯示）。

export const classTypeLabel = (type) => ({ one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導', trial: '試聽' }[type] || type);

export const dayLabel = (d) => ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'][d] || '';

/** 從 YYYY-MM-DD 得到星期幾，1=週一 … 7=週日 */
export const dayOfWeekFromDate = (ymd) => {
  if (!ymd) return 1;
  const d = new Date(ymd + 'T12:00:00');
  const n = d.getDay();
  return n === 0 ? 7 : n;
};

export const getWeekLabel = (weeks) => {
  if (!weeks || weeks.length === 0 || weeks.length === 5) return '';
  return `第${weeks.join(',')}週`;
};

// Parse time string "HH:MM" to hour number
export const parseHour = (t) => {
  if (!t) return 0;
  return parseInt(t.split(':')[0], 10);
};

// 排課以 30 分鐘為單位：將時間正規化為整點或半點 (08:14 → 08:00 或 08:30)
export const TIME_STEP_MINUTES = 30;
export const normalizeTimeTo30 = (timeStr) => {
  if (!timeStr) return '08:00';
  const [h, m] = timeStr.split(':').map(Number);
  const totalM = (h || 0) * 60 + (m || 0);
  const rounded = Math.round(totalM / TIME_STEP_MINUTES) * TIME_STEP_MINUTES;
  const hours = Math.min(23, Math.floor(rounded / 60));
  const mins = rounded % 60;
  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
};

// 由開始時間 + 時長計算結束時間
export const computeEndTime = (startTime, durationHours) => {
  if (!startTime) return '--:--';
  const [h, m] = startTime.split(':').map(Number);
  const startM = (h || 0) * 60 + (m || 0);
  const durM = Math.round((durationHours || 2) * 60);
  const endM = startM + durM;
  const endH = Math.min(23, Math.floor(endM / 60));
  const endMin = endM % 60;
  return `${String(endH).padStart(2, '0')}:${String(endMin).padStart(2, '0')}`;
};
