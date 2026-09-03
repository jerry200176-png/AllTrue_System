/**
 * Learning Records' current session-selection policy.
 *
 * This is intentionally separate from classSessionPick.js for now: the two
 * domains have different occurrence semantics. Learning Records preserves
 * distinct materialized ClassSession IDs on the same date/time until the
 * TD-076 contract is complete.
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

export function pickBestLearningRecordSession(candidates) {
  if (!candidates.length) return null;
  if (candidates.length === 1) return candidates[0];
  return candidates.slice().sort((a, b) => {
    const sa = String(a?.status || a?.Status || '').toLowerCase();
    const sb = String(b?.status || b?.Status || '').toLowerCase();
    const pa = SESSION_STATUS_PRIORITY[sa] ?? 2;
    const pb = SESSION_STATUS_PRIORITY[sb] ?? 2;
    if (pa !== pb) return pa - pb;
    return (Number(b.id) || 0) - (Number(a.id) || 0);
  })[0];
}

/**
 * Preserve the Learning Records page's current behavior while making it
 * directly testable before any policy reconciliation with classSessionPick.
 * @param {Array<object>} sessions
 * @param {(value: string) => string} normalizeTime
 */
export function deduplicateLearningRecordSessions(sessions = [], normalizeTime) {
  if (typeof normalizeTime !== 'function') {
    throw new TypeError('Learning Record session policy requires normalizeTime');
  }

  const groups = {};
  for (const session of sessions) {
    if (session?.isProjected) continue;
    const id = Number(session?.id || 0);
    const date = String(session?.date || '').slice(0, 10);
    const time = normalizeTime(session?.startTime) || '';
    // Keep different ClassSession IDs even when they share the same slot:
    // a legitimate reschedule-to-past can produce two lessons on one date/time.
    const key = id > 0 ? `id:${id}` : `slot:${date}|${time}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(session);
  }
  return Object.values(groups).map((group) => pickBestLearningRecordSession(group));
}
