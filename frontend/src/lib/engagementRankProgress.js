/**
 * Rank thresholds — fetched from backend API `GET /api/v1/engagement/rank-thresholds`.
 * Fallback hardcoded values used until first API fetch succeeds.
 */

const TEACHER_MIN_XP = {
  private_second:           0,
  private_first:            25,
  private_specialist:       55,
  corporal:                 95,
  sergeant:                 145,
  staff_sergeant:           205,
  master_sergeant_third:    275,
  master_sergeant_second:   355,
  master_sergeant_first:    445,
  second_lieutenant:        545,
  first_lieutenant:         655,
  captain:                  775,
  major:                    910,
  lieutenant_colonel:       1060,
  colonel:                  1230,
  major_general:            1425,
  lieutenant_general:       1650,
  general:                  1910,
  general_first_class:      2210,
};

const STAFF_MIN_XP = {
  private_second:           0,
  private_first:            40,
  private_specialist:       90,
  corporal:                 150,
  sergeant:                 225,
  staff_sergeant:           320,
  master_sergeant_third:    430,
  master_sergeant_second:   555,
  master_sergeant_first:    700,
  second_lieutenant:        860,
  first_lieutenant:         1040,
  captain:                  1240,
  major:                    1460,
  lieutenant_colonel:       1705,
  colonel:                  1980,
  major_general:            2290,
  lieutenant_general:       2640,
  general:                  3035,
  general_first_class:      3480,
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
