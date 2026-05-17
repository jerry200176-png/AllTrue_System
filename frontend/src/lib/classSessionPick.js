/**
 * Pick the best ClassSession row when multiple sessions exist on the same date.
 * Used by substitute flow so makeup / rescheduled slots are not confused with the original slot.
 */

export const SESSION_STATUS_PRIORITY = {
  attended: 0,
  completed: 0,
  late: 0,
  absent: 0,
  scheduled: 1,
  leave: 2,
  leave_adjusted: 2,
  excused: 2,
  cancelled: 3,
};

export function normalizeTimeTo30(timeStr) {
  const raw = String(timeStr || '').trim();
  if (!raw) return '';
  const parts = raw.split(':');
  const h = Number(parts[0]);
  const m = Number(parts[1] ?? 0);
  if (!Number.isFinite(h)) return '';
  const mins = h * 60 + (Number.isFinite(m) ? m : 0);
  const rounded = Math.round(mins / 30) * 30;
  const rh = Math.floor(rounded / 60) % 24;
  const rm = rounded % 60;
  return `${String(rh).padStart(2, '0')}:${String(rm).padStart(2, '0')}`;
}

export function pickBestSessionRow(candidates) {
  if (!candidates?.length) return null;
  if (candidates.length === 1) return candidates[0];
  return candidates.slice().sort((a, b) => {
    const pa = SESSION_STATUS_PRIORITY[String(a.status || '').toLowerCase()] ?? 2;
    const pb = SESSION_STATUS_PRIORITY[String(b.status || '').toLowerCase()] ?? 2;
    if (pa !== pb) return pa - pb;
    return (Number(b.id) || 0) - (Number(a.id) || 0);
  })[0];
}

/**
 * @param {Array<{id:number, session_date?:string, SessionDate?:string, start_time?:string, status?:string}>} sessions
 * @param {string} dateYmd
 * @param {string} [startTimeHint] HH:mm from the calendar cell / modal
 * @returns {number|null}
 */
export function resolveSessionIdForSubstitute(sessions, dateYmd, startTimeHint) {
  const targetYmd = String(dateYmd || '').slice(0, 10);
  if (!targetYmd || !Array.isArray(sessions) || !sessions.length) return null;

  const sameDateRows = sessions.filter((r) => {
    const d = String(r.session_date || r.SessionDate || '').slice(0, 10);
    return d === targetYmd;
  });
  if (!sameDateRows.length) return null;

  const hint = normalizeTimeTo30(startTimeHint || '');
  if (hint) {
    const exact = sameDateRows.filter(
      (r) => normalizeTimeTo30(r.start_time || r.StartTime || '') === hint
    );
    if (exact.length) {
      const hit = pickBestSessionRow(exact);
      return hit?.id != null ? Number(hit.id) : null;
    }
  }

  const hit = pickBestSessionRow(sameDateRows);
  return hit?.id != null ? Number(hit.id) : null;
}
