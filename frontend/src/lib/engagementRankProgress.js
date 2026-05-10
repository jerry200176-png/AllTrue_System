/**
 * Rank thresholds — MUST stay aligned with backend `App\Support\EngagementRankProgression`.
 * Follow-up: expose next-tier XP via API to avoid drift (see docs/TECH_DEBT.md).
 */

const TEACHER_MIN_XP = {
  private_second: 0,
  private_first: 25,
  private_specialist: 55,
  corporal: 95,
  sergeant: 145,
  staff_sergeant: 205,
  second_lieutenant: 280,
  first_lieutenant: 360,
  captain: 450,
  major: 550,
  lieutenant_colonel: 665,
  colonel: 800,
  major_general: 960,
  lieutenant_general: 1150,
  general: 1370,
  general_first_class: 1620,
};

const STAFF_MIN_XP = {
  private_second: 0,
  private_first: 40,
  private_specialist: 90,
  corporal: 150,
  sergeant: 225,
  staff_sergeant: 320,
  second_lieutenant: 430,
  first_lieutenant: 555,
  captain: 695,
  major: 855,
  lieutenant_colonel: 1040,
  colonel: 1250,
  major_general: 1490,
  lieutenant_general: 1765,
  general: 2080,
  general_first_class: 2430,
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
