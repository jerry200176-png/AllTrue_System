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
