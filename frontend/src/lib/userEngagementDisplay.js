/**
 * 軍階／XP 摘要顯示 — 預設開啟（opt-out），僅存本機。
 * 與連續登入 streak（預設關、opt-in）對照。
 */

export const ENGAGEMENT_RANK_DISPLAY_KEY = 'user_engagement_rank_display_v1';

export const USER_ENGAGEMENT_DISPLAY_REFRESH_EVENT = 'alltrue-user-engagement-display-refresh';

export function isUserEngagementRankDisplayEnabled() {
  try {
    const v = localStorage.getItem(ENGAGEMENT_RANK_DISPLAY_KEY);
    if (v === null || v === undefined) return true;
    return v !== '0';
  } catch {
    return true;
  }
}

export function setUserEngagementRankDisplayEnabled(on) {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(ENGAGEMENT_RANK_DISPLAY_KEY, on ? '1' : '0');
  } catch {
    /* quota / private mode */
  }
}

export function notifyUserEngagementDisplayRefresh() {
  if (typeof window === 'undefined') return;
  try {
    window.dispatchEvent(new CustomEvent(USER_ENGAGEMENT_DISPLAY_REFRESH_EVENT));
  } catch {
    /* ignore */
  }
}
