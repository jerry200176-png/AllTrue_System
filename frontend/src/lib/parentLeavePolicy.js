/**
 * Policy rules for parental leave requests (Issue #2527 / Bug #254):
 * 1. Cutoff for normal online leave: 24 hours before session start.
 * 2. < 24 hours but before class start: Allowed as "臨時請假" (late leave).
 * 3. At or after session start time: Strictly prohibited in parent portal.
 */

export function getSessionStartTime(session) {
  if (!session?.SessionDate || !session?.StartTime) return null;
  const dateStr = String(session.SessionDate).substring(0, 10);
  const timeStr = String(session.StartTime).substring(0, 5);
  const dt = new Date(`${dateStr}T${timeStr}:00`);
  return Number.isNaN(dt.getTime()) ? null : dt;
}

export function isSessionStartedOrPast(session, nowMs = Date.now()) {
  if (typeof session?.is_past_or_started === 'boolean') {
    return session.is_past_or_started;
  }
  const dt = getSessionStartTime(session);
  return dt ? nowMs >= dt.getTime() : false;
}

export function isLateLeave(session, nowMs = Date.now()) {
  if (typeof session?.is_late_leave === 'boolean') {
    return session.is_late_leave;
  }
  const dt = getSessionStartTime(session);
  if (!dt) return false;
  const cutoff = dt.getTime() - 24 * 60 * 60 * 1000;
  return nowMs >= cutoff && nowMs < dt.getTime();
}

export function canRequestParentLeave(session, crossCampusActionsEnabled = true, nowMs = Date.now()) {
  if (!crossCampusActionsEnabled) return false;
  const status = String(session?.Status || '').toLowerCase();
  if (!['scheduled', 'rescheduled'].includes(status)) return false;
  return !isSessionStartedOrPast(session, nowMs);
}
