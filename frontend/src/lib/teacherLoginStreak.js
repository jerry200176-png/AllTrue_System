/**
 * 老師端「連續使用天數」— 僅存裝置 localStorage，預設不顯示（opt-in）。
 * 與 Issue #314 階段 1 對齊；靈感參考 OSS streak 計數（如 use-streak）與習慣追蹤 local-first 模式。
 */

export const STREAK_STORAGE_KEY = 'alltrue_teacher_login_streak_v1';
export const STREAK_DISPLAY_KEY = 'teacher_login_streak_display';

export const TEACHER_STREAK_REFRESH_EVENT = 'alltrue-teacher-streak-refresh';

function pad2(n) {
  return String(n).padStart(2, '0');
}

/** 本機日曆日 YYYY-MM-DD */
export function localTodayYmd(d = new Date()) {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

function parseYmd(ymd) {
  const parts = String(ymd).split('-').map(Number);
  const y = parts[0];
  const m = parts[1];
  const day = parts[2];
  if (!y || !m || !day) return new Date(NaN);
  return new Date(y, m - 1, day);
}

function daysBetweenYmd(prevYmd, nextYmd) {
  const a = parseYmd(prevYmd).getTime();
  const b = parseYmd(nextYmd).getTime();
  if (Number.isNaN(a) || Number.isNaN(b)) return NaN;
  return Math.round((b - a) / (24 * 60 * 60 * 1000));
}

export function parseStreakState(jsonStr) {
  if (!jsonStr) return null;
  try {
    const o = JSON.parse(jsonStr);
    return {
      lastVisitYmd: String(o.lastVisitYmd || ''),
      current: Math.max(0, Number(o.current) || 0),
      longest: Math.max(0, Number(o.longest) || 0),
    };
  } catch {
    return null;
  }
}

/** 純函式：供測試與寫入前計算 */
export function computeNextStreakState(prev, todayYmd) {
  if (!prev || !prev.lastVisitYmd) {
    return { lastVisitYmd: todayYmd, current: 1, longest: 1 };
  }
  if (prev.lastVisitYmd === todayYmd) {
    return { lastVisitYmd: todayYmd, current: prev.current, longest: prev.longest };
  }
  const gap = daysBetweenYmd(prev.lastVisitYmd, todayYmd);
  const current = gap === 1 ? prev.current + 1 : 1;
  const longest = Math.max(prev.longest, current);
  return { lastVisitYmd: todayYmd, current, longest };
}

export function recordTeacherVisitToday(now = new Date()) {
  const todayYmd = localTodayYmd(now);
  if (typeof localStorage === 'undefined') {
    return computeNextStreakState(null, todayYmd);
  }
  const raw = localStorage.getItem(STREAK_STORAGE_KEY);
  const prev = parseStreakState(raw);
  const next = computeNextStreakState(prev, todayYmd);
  try {
    localStorage.setItem(STREAK_STORAGE_KEY, JSON.stringify(next));
  } catch {
    /* quota / private mode */
  }
  return next;
}

export function readTeacherStreak() {
  if (typeof localStorage === 'undefined') return null;
  return parseStreakState(localStorage.getItem(STREAK_STORAGE_KEY));
}

export function isTeacherStreakDisplayEnabled() {
  try {
    return localStorage.getItem(STREAK_DISPLAY_KEY) === '1';
  } catch {
    return false;
  }
}

export function setTeacherStreakDisplayEnabled(on) {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(STREAK_DISPLAY_KEY, on ? '1' : '0');
  } catch {
    /* ignore */
  }
}

export function notifyTeacherStreakRefresh() {
  if (typeof window === 'undefined') return;
  try {
    window.dispatchEvent(new CustomEvent(TEACHER_STREAK_REFRESH_EVENT));
  } catch {
    /* ignore */
  }
}
