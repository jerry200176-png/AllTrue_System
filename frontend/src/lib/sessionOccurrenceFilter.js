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

export function rowOccupiesPurchasedQuota(row) {
  if (row?.isProjected) return false;
  if (row?.isContractException || row?.is_contract_exception) return false;
  return !SESSION_NOT_OCCUPYING_QUOTA.has(sessionStatusOf(row));
}
