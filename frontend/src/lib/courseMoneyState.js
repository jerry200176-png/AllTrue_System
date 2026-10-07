// F4/F7 single module: every field-alias fallback and verdict about a course row's
// sessions / payment mode / payment labels lives here. Pages ask, they do not read
// RemainingSessions / PackageRemainingSessions / payment_type themselves.
//
// Founder-approved 2026-10-06 (option A): one verdict per course on every page. Monthly is
// payment_type === 'monthly' (case-insensitive); package membership is PackageID or package_id;
// one wording per payment status (see courseMoneyState.test.js). Remaining per-caller options
// (pascalAlias, fallbackToPurchased) are session-count display aliases, not money status.

import { isCourseSettled } from './paymentStatus.js';

/** 低堂數門檻：剩餘 <= 2 視為即將用完 */
export const LOW_SESSIONS_THRESHOLD = 2;

/**
 * Number(v) when finite, else null. Mirrors the old page parseCourseNumber: explicit `null`
 * coerces to 0 (Number(null)); only undefined/NaN/Infinity/junk give null.
 */
export const finiteOrNull = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
};

export const isLowRemaining = (remaining) => remaining != null && remaining <= LOW_SESSIONS_THRESHOLD;

/** 'empty' (<=0) | 'low' (<=2) | 'watch' (<=watchAt) | 'ok'. Pages map tone -> their own colour. */
export const remainingTone = (remaining, { watchAt = 4 } = {}) => {
  const r = remaining ?? 0;
  if (r <= 0) return 'empty';
  if (isLowRemaining(r)) return 'low';
  if (r <= watchAt) return 'watch';
  return 'ok';
};

// ── package pool ─────────────────────────────────────────────
/** PackageID or package_id > 0 */
export const isPackageMember = (course) => Number(course?.PackageID ?? course?.package_id ?? 0) > 0;

/** own-course remaining (`remaining_sessions` ?? `RemainingSessions`), null when absent */
export const ownRemainingSessions = (course) => finiteOrNull(course?.remaining_sessions ?? course?.RemainingSessions);

/** pool remaining, null when absent. pascalAlias:false = CourseManagement display, which never honored PackageRemainingSessions */
export const poolRemainingSessions = (course, { pascalAlias = true } = {}) => (
  finiteOrNull(pascalAlias
    ? (course?.package_remaining_sessions ?? course?.PackageRemainingSessions)
    : course?.package_remaining_sessions)
);

/** pool total, null when absent. fallbackToPurchased: StudentsList/CourseManagement fall back to sessions_purchased and clamp to >= 0. */
export const poolTotalSessions = (course, { fallbackToPurchased = false } = {}) => {
  const base = course?.package_total_sessions ?? course?.PackageTotalSessions;
  if (!fallbackToPurchased) return finiteOrNull(base);
  const total = Number(base ?? course?.sessions_purchased ?? 0);
  return Number.isFinite(total) && total > 0 ? total : 0;
};

/** pool used = total(with purchased fallback) - remaining(default 0), floored at 0 (CourseManagement getPackageUsedSessions) */
export const poolUsedSessions = (course) => {
  const remaining = poolRemainingSessions(course);
  const used = poolTotalSessions(course, { fallbackToPurchased: true }) - (remaining ?? 0);
  return used > 0 ? used : 0;
};

/** purchased sessions of this course (>= 0) */
export const purchasedSessions = (course) => Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);

// ── payment mode ─────────────────────────────────────────────
/** The one monthly rule: backend billing type payment_type === 'monthly', case-insensitive */
export const isMonthlyPaymentType = (course) => String(course?.payment_type || '').toLowerCase() === 'monthly';

/** CourseManagement isSessionMode: explicit payment_type wins, else purchased > 0 */
export const isSessionPayment = (course) => {
  const paymentType = String(course?.payment_type || '').trim();
  if (paymentType) return paymentType === 'session';
  return Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) > 0;
};

// ── remaining verdicts ───────────────────────────────────────
/**
 * StudentsList list badge: sessions-only, pool for package members, own count otherwise.
 * Monthly -> never low; package without a finite pool value -> not low.
 */
export const isSessionPaymentLow = (course) => {
  if (isMonthlyPaymentType(course)) return false;
  if (isPackageMember(course)) {
    const pr = Number(course?.package_remaining_sessions ?? NaN);
    return Number.isFinite(pr) && pr <= LOW_SESSIONS_THRESHOLD;
  }
  return isLowRemaining(ownRemainingSessions(course));
};

/**
 * StudentsList 加購堂數 modal: pool or own, missing -> 0 (so missing counts as low).
 * No monthly exclusion and no Pascal alias — kept as-is from the page.
 */
export const modalRemainingSessions = (course) => (
  isPackageMember(course) ? (course?.package_remaining_sessions ?? 0) : (course?.remaining_sessions ?? 0)
);

/** StudentsList 堂數進度條; null for package members / monthly / unverifiable data */
export const courseProgress = (course) => {
  if (isPackageMember(course)) return null;
  if (isMonthlyPaymentType(course)) return null;
  const total = finiteOrNull(isPackageMember(course) ? course?.package_total_sessions : course?.sessions_purchased);
  const remaining = finiteOrNull(isPackageMember(course) ? course?.package_remaining_sessions : (course?.remaining_sessions ?? course?.RemainingSessions));
  if (total == null || total <= 0 || remaining == null || remaining < 0) return null;
  const reportedUsed = finiteOrNull(isPackageMember(course) ? course?.package_used_sessions : (course?.used_sessions ?? course?.sessions_used));
  const used = Math.max(0, reportedUsed == null ? total - remaining : reportedUsed);
  const boundedUsed = Math.min(total, used);
  return {
    total,
    remaining,
    used: boundedUsed,
    percent: Math.min(100, Math.max(0, Math.round((boundedUsed / total) * 100))),
  };
};

// ── closed-reason verdict ────────────────────────────────────
/** Reasons that put a course in the "已結案" family (CourseManagement callout / status label). */
const CLOSED_REASONS = ['settled', 'settled_pending', 'waived', 'contract_amended', 'completed', 'converted_trial'];
export const isClosedReason = (reason) => CLOSED_REASONS.includes(reason);

/**
 * The one closed-reason verdict. Server `closed_reason` wins; for legacy rows without one an
 * inactive course counts as 'completed' when it is monthly/non-session, or session-mode with the
 * server saying paid (`isCourseSettled === true`, so `_noncanonical` rows never count) and a known
 * own remaining <= 0 (missing remaining is not "used up").
 */
export const closedReason = (course) => {
  if (course?.closed_reason) return course.closed_reason;
  if (String(course?.status || '').toLowerCase() !== 'inactive') return null;
  if (!isSessionPayment(course)) return 'completed';
  const remaining = ownRemainingSessions(course);
  return isCourseSettled(course) === true && remaining != null && remaining <= 0 ? 'completed' : null;
};

/** History list membership. includePending: StudentsList also files 待對帳結案 rows under history; CourseManagement keeps them in the active list. */
export const isHistoryCourse = (course, { includePending = false } = {}) => {
  const reason = closedReason(course);
  return reason === 'settled' || reason === 'completed' || reason === 'waived' || (includePending && reason === 'settled_pending');
};

// ── payment-status labels (display only; the server owns the status itself) ──
export const TUITION_STATUS_CONFIG = {
  unpaid: { label: '未繳', cls: 'st-unpaid' },
  partial: { label: '繳了一部分', cls: 'st-partial' },
  waived: { label: '不收了', cls: 'st-paid' },
  pending_report: { label: '家長說繳了，等你確認', cls: 'st-pending' },
  pending_reconciliation: { label: '課已結束，等你確認收款', cls: 'st-pending' },
  paid: { label: '已收', cls: 'st-paid' },
  renew_needed: { label: '續課待處理', cls: 'st-renew' },
  monthly_due_soon: { label: '月結將到期', cls: 'st-monthly' },
};
export const WAIVED_LABEL = '不收了';
export const INVOICE_STATUS_LABELS = { paid: '已收', unpaid: '未繳', partial: '繳了一部分', void: '已作廢' };
// `voided` only exists in the ledger, the one screen that lists voids.
export const REPORT_STATUS_LABELS = { pending: '等你確認', confirmed: '已收', rejected: '已退回', voided: '已撤銷' };

// ── monthly period payment labels (folded from monthlyPaymentDisplay.js) ──
export const periodPaymentLabel = (status) => ({
  paid: '已收', unpaid: '未繳', partial: '繳了一部分', pending_report: '等你確認',
  review_required: '付款期間待確認', unknown: '付款期間待確認',
})[status] || '付款期間待確認';

export function monthlyPaymentLabel(course) {
  const summary = course?.monthly_payment;
  if (!summary) return null;
  if (summary.review_required) return '付款期間待確認';
  if (course.payment_status === 'pending_report' && summary.payment_status === 'unpaid') return `${summary.billing_period} ${periodPaymentLabel('pending_report')}`;
  return `${summary.billing_period} ${periodPaymentLabel(summary.payment_status)}`;
}
