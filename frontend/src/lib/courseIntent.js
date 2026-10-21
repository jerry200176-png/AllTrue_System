/**
 * in-app #429 (GitHub #4331): one name and one entry for "add / renew a course".
 * Founder 2026-10-15 Q8=A: adding lessons to the same course is always 「續課」.
 * Every call site (課程查找 row + ⋯ menu, 學生管理 course card, server messages) uses these
 * constants instead of 加購堂數／續報加購／續約／加購.
 */
import { courseProgress, isMonthlyPaymentType, isPackageMember, isSessionPayment } from './courseMoneyState.js';

export const RENEW_LESSONS_LABEL = '續課';
export const COURSE_ENTRY_LABEL = '新增課程／續課';

/** Intent chooser options. Each id maps to an existing flow; nothing here creates a new one. */
export const COURSE_INTENTS = Object.freeze([
  Object.freeze({ id: 'new', label: '全新課程', hint: '第一次報這門課：新的科目，或還沒有課的學生。', icon: 'add' }),
  Object.freeze({ id: 'renew', label: `同一門課${RENEW_LESSONS_LABEL}`, hint: '同科目、同老師，再買一期堂數接著上；原合約與上課紀錄不變。', icon: 'autorenew' }),
  Object.freeze({ id: 'change', label: '換科目／老師／時段', hint: '用「轉課」把已上課紀錄轉到另一門課。', icon: 'swap_horiz' }),
]);

/** Picker capabilities only; the existing endpoints remain the mutation authority. */
export function courseIntentEligibility(course) {
  const closed = String(course?.status || '').toLowerCase() === 'inactive'
    || Number(course?.Stop ?? 0) === 1 || Boolean(course?.closed_reason);
  const monthly = isMonthlyPaymentType(course);
  const session = isSessionPayment(course);
  const packaged = isPackageMember(course);
  const trial = course?.class_type === 'trial';
  const tutoring = course?.class_type === 'tutoring';
  const progress = courseProgress(course);
  return {
    // Exhausted paid courses still need renewal; history-list membership is not eligibility.
    renewable: !closed && !trial && !(tutoring && packaged) && (monthly || session || tutoring),
    transferable: !closed && !trial && !tutoring && session && !packaged
      && String(course?.rate_unit || 'session').toLowerCase() === 'session'
      && progress != null && progress.used > 0 && progress.remaining > 0,
    showRemainingSessions: !monthly && !packaged,
  };
}

/**
 * Run the handler for the chosen intent. Returns false when the intent is unknown or the page
 * did not wire a handler, so callers never fall through to a different flow silently.
 */
export function routeCourseIntent(intentId, handlers = {}, ...args) {
  const fn = handlers[intentId];
  if (!COURSE_INTENTS.some((i) => i.id === intentId) || typeof fn !== 'function') return false;
  fn(...args);
  return true;
}
