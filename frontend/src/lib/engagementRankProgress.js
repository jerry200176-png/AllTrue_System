/**
 * Rank thresholds — fetched from backend API `GET /api/v1/engagement/rank-thresholds`.
 * Fallback hardcoded values used until first API fetch succeeds.
 */

// 長期養成曲線（2026-06-14 重設，與後端 EngagementRankProgression.php 一致）：
// 原曲線升官過快，已拉成數月～一年以上的養成節奏。
const TEACHER_MIN_XP = {
  private_second:           0,
  private_first:            50,
  private_specialist:       130,
  corporal:                 260,
  sergeant:                 450,
  staff_sergeant:           700,
  master_sergeant_third:    1050,
  master_sergeant_second:   1500,
  master_sergeant_first:    2100,
  second_lieutenant:        2850,
  first_lieutenant:         3750,
  captain:                  4850,
  major:                    6200,
  lieutenant_colonel:       7850,
  colonel:                  9850,
  major_general:            12300,
  lieutenant_general:       15300,
  general:                  19000,
  general_first_class:      23500,
};

const STAFF_MIN_XP = {
  private_second:           0,
  private_first:            70,
  private_specialist:       180,
  corporal:                 360,
  sergeant:                 630,
  staff_sergeant:           980,
  master_sergeant_third:    1470,
  master_sergeant_second:   2100,
  master_sergeant_first:    2940,
  second_lieutenant:        3990,
  first_lieutenant:         5250,
  captain:                  6790,
  major:                    8680,
  lieutenant_colonel:       10990,
  colonel:                  13790,
  major_general:            17220,
  lieutenant_general:       21420,
  general:                  26600,
  general_first_class:      32900,
};

let _cachedTeacher = null;
let _cachedStaff = null;
let _fetchPromise = null;

export function setCachedThresholds(teacher, staff) {
  _cachedTeacher = teacher;
  _cachedStaff = staff;
}

export async function fetchRankThresholds(apiBase) {
  if (_cachedTeacher && _cachedStaff) return;
  if (_fetchPromise) return _fetchPromise;
  _fetchPromise = (async () => {
    try {
      const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
      const token = session.access_token || '';
      const res = await fetch(`${apiBase}/engagement/rank-thresholds`, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (res.ok) {
        const data = await res.json();
        if (data.teacher && data.staff) {
          _cachedTeacher = {};
          _cachedStaff = {};
          for (const entry of data.teacher) _cachedTeacher[entry.key] = entry.min_xp;
          for (const entry of data.staff) _cachedStaff[entry.key] = entry.min_xp;
        }
      }
    } catch { /* use fallback */ }
    _fetchPromise = null;
  })();
  return _fetchPromise;
}

function tableForTrack(roleTrack) {
  if (roleTrack === 'staff') return _cachedStaff || STAFF_MIN_XP;
  return _cachedTeacher || TEACHER_MIN_XP;
}

/** @param {number} xp @param {'teacher'|'staff'} roleTrack */
export function rankKeyForXp(xp, roleTrack) {
  const table = tableForTrack(roleTrack);
  let selected = 'private_second';
  for (const [key, min] of Object.entries(table)) {
    if (xp >= min) selected = key;
  }
  return selected;
}

/**
 * Progress within current→next rank tier (for UI bar).
 * @returns {{ pct: number, xpAtCurrent: number, xpAtNext: number|null, isMax: boolean }}
 */
export function rankTierProgress(xp, roleTrack) {
  const safeXp = Math.max(0, Number(xp) || 0);
  const table = tableForTrack(roleTrack);
  const entries = Object.entries(table).sort((a, b) => a[1] - b[1]);
  let idx = 0;
  for (let i = 0; i < entries.length; i += 1) {
    if (safeXp >= entries[i][1]) idx = i;
  }
  const current = entries[idx];
  const next = entries[idx + 1];
  const xpAtCurrent = current[1];
  if (!next) {
    return { pct: 100, xpAtCurrent, xpAtNext: null, isMax: true };
  }
  const xpAtNext = next[1];
  const span = xpAtNext - xpAtCurrent;
  const pct = span > 0
    ? Math.min(100, Math.max(0, ((safeXp - xpAtCurrent) / span) * 100))
    : 100;
  return { pct, xpAtCurrent, xpAtNext, isMax: false };
}

/** XP still needed to reach the next tier (0 if max). */
export function xpRemainingToNext(safeXp, roleTrack) {
  const { xpAtNext, isMax } = rankTierProgress(safeXp, roleTrack);
  if (isMax || xpAtNext === null) return 0;
  return Math.max(0, xpAtNext - safeXp);
}
