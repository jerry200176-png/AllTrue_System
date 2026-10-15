// In-app #409 / GitHub #4151: on a 堂數制 course with remaining lessons, a subject, teacher or slot
// change in 課程設定 would rewrite history (or hit a 422). The audited forward-only flow is 轉課
// (split-contract). This helper only detects the change and builds a prefill; it never writes.
import { isPackageMember, isSessionPayment, ownRemainingSessions } from './courseMoneyState.js';

export const FORWARD_CHANGE_TRANSFER_HINT = '已上過的課不會變動；要從某日起改科目／老師／時段，請用轉課';

const slotKey = (slot) => `${Number(slot?.day || 0)}@${String(slot?.start_time || '').slice(0, 5)}/${Number(slot?.duration_hours || 0)}`;
const slotSignature = (form) => {
  const slots = Array.isArray(form?.day_time_slots) ? form.day_time_slots : [];
  if (slots.length) return slots.map(slotKey).sort().join(',');
  const days = Array.isArray(form?.days_of_week) ? [...form.days_of_week].map(Number).sort() : [];
  return `${days.join('|')}@${String(form?.start_time || '').slice(0, 5)}/${Number(form?.duration_hours || 0)}`;
};

/** A 堂數制 (count-mode), non-package course that still has lessons left. */
export function isForwardTransferCandidate(course) {
  if (!course || !isSessionPayment(course) || isPackageMember(course)) return false;
  return Number(ownRemainingSessions(course) ?? 0) > 0;
}

/**
 * Compare the original edit form with the current one.
 * Returns { changed: string[], prefill } or null when nothing forward-only changed
 * or the course is not a candidate.
 */
export function detectForwardChange(course, original, current) {
  if (!original || !current || !isForwardTransferCandidate(course)) return null;
  const changed = [];
  const prefill = {};
  if (String(current.subject ?? '') !== String(original.subject ?? '')) {
    changed.push('科目');
    prefill.subject = current.subject;
  }
  if (String(current.teacher_id ?? '') !== String(original.teacher_id ?? '')) {
    changed.push('老師');
    if (current.teacher_id) prefill.teacher_id = current.teacher_id;
  }
  if (slotSignature(current) !== slotSignature(original)) {
    changed.push('時段');
    const before = new Set((original.day_time_slots || []).map(slotKey));
    const slot = (current.day_time_slots || []).find((s) => !before.has(slotKey(s))) || current.day_time_slots?.[0];
    if (slot?.day) {
      prefill.weekday = Number(slot.day);
      prefill.time = String(slot.start_time || '16:00').slice(0, 5);
      prefill.duration_minutes = Math.round(Number(slot.duration_hours || 2) * 60);
    }
  }
  return changed.length ? { changed, prefill } : null;
}

