/**
 * Shared series/occurrence display filter for Course Management chips and Calendar.
 * Internal reschedule bookkeeping must never appear as a “ghost cancelled” session.
 */

export const INTERNAL_CANCEL_PLACEHOLDER_NOTE = 'cancelled-duplicate-reschedule-placeholder';

const SESSION_NOT_OCCUPYING_QUOTA = new Set([
  'cancelled',
  'leave',
  'leave_adjusted',
  'excused',
]);

export function sessionStatusOf(row) {
  return String(row?.status || row?.Status || '').toLowerCase();
}

export function sessionNoteOf(row) {
  return String(row?.note || row?.Note || '');
}

/** Cancelled ClassSession written only to bookkeep a moved occurrence. */
export function isInternalCancelPlaceholder(row) {
  if (sessionStatusOf(row) !== 'cancelled') return false;
  return sessionNoteOf(row).includes(INTERNAL_CANCEL_PLACEHOLDER_NOTE);
}

/** Manual / visible cancel (not internal bookkeeping). */
export function isVisibleCancelledSession(row) {
  return sessionStatusOf(row) === 'cancelled' && !isInternalCancelPlaceholder(row);
}

/**
 * Default “effective” occurrence for chips / calendar live rows:
 * exclude cancelled (including internal placeholders).
 */
export function isEffectiveSession(row) {
  if (!row) return false;
  if (isInternalCancelPlaceholder(row)) return false;
  return sessionStatusOf(row) !== 'cancelled';
}

export function filterEffectiveSessions(rows = []) {
  return (Array.isArray(rows) ? rows : []).filter(isEffectiveSession);
}

export function filterVisibleCancelledSessions(rows = []) {
  return (Array.isArray(rows) ? rows : []).filter(isVisibleCancelledSession);
}

/** Strip internal placeholders; keep manual cancelled for progressive disclosure. */
export function filterDisplayableSessions(rows = []) {
  return (Array.isArray(rows) ? rows : []).filter((row) => !isInternalCancelPlaceholder(row));
}

export function rowOccupiesPurchasedQuota(row, { exceptionsOccupyQuota = true } = {}) {
  if (row?.isProjected) return false;
  if (!exceptionsOccupyQuota && (row?.isContractException || row?.is_contract_exception)) {
    return false;
  }
  return !SESSION_NOT_OCCUPYING_QUOTA.has(sessionStatusOf(row));
}

function hourOfSessionRow(row, parseHour) {
  if (typeof parseHour !== 'function') return null;
  const raw = row?.start_time || row?.StartTime || row?.startTime || '';
  const hour = parseHour(raw);
  return Number.isFinite(hour) ? hour : null;
}

/**
 * Weekly template at `hour` occupies a calendar slot only if that course has
 * no active ClassSession that day, or at least one active session starts then.
 *
 * Same-day second reschedule (in-app #238): live session at 19:00 must not let
 * the leftover weekly/scheduled 17:00 template keep a 已滿 badge.
 * Leave + makeup with no ClassSession at the new time still occupies (R13).
 */
export function weeklyTemplateOccupiesHour({ sessionRowsOnDate = [], hour, parseHour }) {
  const rows = Array.isArray(sessionRowsOnDate) ? sessionRowsOnDate : [];
  const active = rows.filter((row) => !SESSION_NOT_OCCUPYING_QUOTA.has(sessionStatusOf(row)));
  if (active.length === 0) return true;
  return active.some((row) => hourOfSessionRow(row, parseHour) === hour);
}
