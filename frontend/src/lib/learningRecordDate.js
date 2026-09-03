// LearningRecordsPage 的純日期／時間 helper。
//
// 這些函式只處理瀏覽器 local time 與表單時間格式，不決定任何評量、
// 點名、帳務或權限規則；抽出後維持原頁面的輸入／輸出語意。

export const formatLocalDate = (date) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

export const localTodayYmd = () => formatLocalDate(new Date());

export const dayOfWeekFromYmd = (ymd) => {
  if (!ymd) return 1;
  const d = new Date(`${ymd}T12:00:00`);
  const n = d.getDay();
  return n === 0 ? 7 : n; // 1=Mon ... 7=Sun
};

export const addMinutesToTime = (timeStr, minutes) => {
  const [hRaw, mRaw] = String(timeStr || '').split(':');
  const h = Number(hRaw);
  const m = Number(mRaw);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '';
  const d = new Date(2000, 0, 1, h, m, 0, 0);
  d.setMinutes(d.getMinutes() + minutes);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
