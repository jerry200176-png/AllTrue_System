/**
 * Rank thresholds — MUST stay aligned with backend `App\Support\EngagementRankProgression`.
 *
 * 中華民國正式軍階（共 19 可解鎖階）：
 *   士兵：二等兵 一等兵 上等兵
 *   士官：下士 中士 上士 三等士官長 二等士官長 一等士官長
 *   軍官：少尉 中尉 上尉 少校 中校 上校
 *   將官：少將 中將 上將 一級上將
 *   super_admin 固定：五星上將（不在此表）
 *
 * Follow-up: expose next-tier XP via API to avoid drift (see docs/TECH_DEBT.md).
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

function tableForTrack(roleTrack) {
  return roleTrack === 'staff' ? STAFF_MIN_XP : TEACHER_MIN_XP;
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
