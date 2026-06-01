export const CALENDAR_PREFETCH_BUFFER_DAYS = 21;

export function formatCalendarYmd(date) {
  const d = date instanceof Date ? date : new Date(`${date}T12:00:00`);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function addCalendarDays(ymd, days) {
  const d = new Date(`${ymd}T12:00:00`);
  d.setDate(d.getDate() + days);
  return formatCalendarYmd(d);
}

export function resolveCalendarDataFetchBoundsYmd(ymds, {
  displayYear,
  displayMonth,
  bufferDays = CALENDAR_PREFETCH_BUFFER_DAYS,
} = {}) {
  const normalized = (Array.isArray(ymds) ? ymds : [])
    .map((ymd) => String(ymd || '').slice(0, 10))
    .filter(Boolean)
    .sort();

  if (!normalized.length) {
    const mi = Number(displayMonth || 1) - 1;
    const start = new Date(Number(displayYear), mi - 3, 1);
    const end = new Date(Number(displayYear), mi + 2, 0);
    return {
      schedStart: formatCalendarYmd(start),
      schedEnd: formatCalendarYmd(end),
    };
  }

  return {
    schedStart: addCalendarDays(normalized[0], -bufferDays),
    schedEnd: addCalendarDays(normalized[normalized.length - 1], bufferDays),
  };
}

export function shouldUseLegacyCalendarFallback({ apiSucceeded }) {
  return apiSucceeded !== true;
}

/**
 * TD-062 Phase 1：判斷「目標週日期範圍」是否完全落在「上次抓取的視窗」內（且同分校）。
 * 用於換週/換日時決定是否可跳過全量重抓（命中＝由 reactive computed 直接重渲染既有資料）。
 * 保守原則：缺任一輸入、跨分校、或超出視窗任一端，一律回 false（→ 重抓）。
 *
 * @param {{min:string,max:string}|null} range 目標週的最早/最晚日（YYYY-MM-DD）
 * @param {{branchId:number,schedStart:string,schedEnd:string}|null} fetched 上次抓取視窗
 * @param {number|string} branchId 目前分校（teacher 視圖為 0）
 * @returns {boolean} true＝可跳過重抓
 */
export function isRangeWithinFetchedBounds(range, fetched, branchId) {
  if (!range || !fetched) return false;
  if (!range.min || !range.max || !fetched.schedStart || !fetched.schedEnd) return false;
  if (Number(fetched.branchId) !== (Number(branchId) || 0)) return false;
  return range.min >= fetched.schedStart && range.max <= fetched.schedEnd;
}
