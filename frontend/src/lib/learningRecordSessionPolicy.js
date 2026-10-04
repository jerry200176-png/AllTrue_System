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

/**
 * Director form: choose which of a day's live sessions the form binds to.
 * Exact ClassSession id wins; with an id but no match, return null (never
 * fall back to another row on the same date). Without an id: prefer the row
 * matching the typed start time, then the best (attended-first) row.
 */
// Mirrors backend AttendanceStatus::requiresLogSessionStatuses().
const LOG_ELIGIBLE_SESSION_STATUSES = new Set(['attended', 'completed', 'late', 'trial', 'tutoring_attend']);

export function selectFormDaySession(daySessions, { classSessionId = 0, startTime = '', normalizeTime }) {
  const id = Number(classSessionId || 0);
  if (id > 0) return daySessions.find((s) => Number(s.id) === id) || null;
  const t = startTime ? normalizeTime(startTime) : '';
  const byTime = t ? daySessions.filter((s) => normalizeTime(s.startTime) === t) : [];
  const pool = byTime.length ? byTime : daySessions;
  // Only attended lessons accept an assessment; never lock the form to an absent one when another qualifies.
  const eligible = pool.filter((s) => LOG_ELIGIBLE_SESSION_STATUSES.has(String(s?.status || '').toLowerCase()));
  return pickBestLearningRecordSession(eligible.length ? eligible : pool);
}

/**
 * Pick the learning record for a calendar session. With a ClassSession id only an
 * exact match or a same-time legacy record without ClassSessionID may bind; a
 * record of another session in the slot, or any same-date guess, never does.
 */
export function pickSessionRecord({ csId = 0, byCs = null, byTime = null, byDate = null, byUnboundTime = null }) {
  if (byCs) return byCs;
  if (Number(csId) <= 0) return byTime || byDate;
  return byUnboundTime;
}

/** Record id a calendar card opens: the API's own id, else the session-safe lookup (never a same-date guess). */
export function sessionRecordId({ apiLrId = null, csId = 0, byCs = null, byTime = null, byUnboundTime = null }) {
  if (apiLrId != null && Number(apiLrId) > 0) return Number(apiLrId);
  const r = pickSessionRecord({ csId, byCs, byTime, byUnboundTime });
  return r?.id ? Number(r.id) : null;
}
