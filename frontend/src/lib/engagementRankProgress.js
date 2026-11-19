/**
 * Rank progress bar math. The rank table lives only on the server (EngagementRankProgression.php, TD-017):
 * the engagement payload (UserEngagementPresenter::forMe) carries `xp_total`, `xp_at_current` and
 * `next_rank_xp` (null at the top rank), and this file only turns them into a bar.
 */

/** @returns {{ pct: number, xpAtCurrent: number, xpAtNext: number|null, isMax: boolean }} */
export function rankTierProgress(engagement) {
  const xp = Math.max(0, Number(engagement?.xp_total) || 0);
  const xpAtCurrent = Math.max(0, Number(engagement?.xp_at_current) || 0);
  const next = engagement?.next_rank_xp;
  if (next === null || next === undefined) {
    return { pct: 100, xpAtCurrent, xpAtNext: null, isMax: true };
  }
  const xpAtNext = Number(next);
  const span = xpAtNext - xpAtCurrent;
  const pct = span > 0 ? Math.min(100, Math.max(0, ((xp - xpAtCurrent) / span) * 100)) : 100;
  return { pct, xpAtCurrent, xpAtNext, isMax: false };
}

/** XP still needed to reach the next rank (0 at the top rank). */
export function xpRemainingToNext(engagement) {
  const { xpAtNext, isMax } = rankTierProgress(engagement);
  if (isMax) return 0;
  return Math.max(0, xpAtNext - Math.max(0, Number(engagement?.xp_total) || 0));
}
