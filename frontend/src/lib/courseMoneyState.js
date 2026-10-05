// F4/F7 single module: every field-alias fallback and verdict about a course row's
// sessions / payment mode / payment labels lives here. Pages ask, they do not read
// RemainingSessions / PackageRemainingSessions / payment_type themselves.
//
// Behavior-preserving extraction: where earlier page copies disagreed, the variant is an
// explicit option or a separately named export (see courseMoneyState.test.js for the
// legacy copies this is pinned against). Do not merge variants without a Founder decision.

/** 低堂數門檻：剩餘 <= 2 視為即將用完 */
export const LOW_SESSIONS_THRESHOLD = 2;

/** finite number or null (null/undefined/NaN/Infinity -> null; '' -> 0 like Number('')) */
export const finiteOrNull = (value) => {
  if (value === null || value === undefined) return null;
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
/** @param {{strictPackageId?: boolean}} [opt] strictPackageId: truthy `PackageID` only (CourseManagement legacy) */
export const isPackageMember = (course, { strictPackageId = false } = {}) => (
  strictPackageId ? !!course?.PackageID : Number(course?.PackageID ?? course?.package_id ?? 0) > 0
);

/** own-course remaining (`remaining_sessions` ?? `RemainingSessions`), null when absent */
export const ownRemainingSessions = (course) => finiteOrNull(course?.remaining_sessions ?? course?.RemainingSessions);

/** pool remaining, null when absent */
export const poolRemainingSessions = (course) => (
  finiteOrNull(course?.package_remaining_sessions ?? course?.PackageRemainingSessions)
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
/** StudentsList: payment_type compared case-insensitively to 'monthly' */
export const isMonthlyPaymentType = (course) => String(course?.payment_type || '').toLowerCase() === 'monthly';

/** CourseManagement isMonthlyMode: anything that is not literally 'session' (blank -> session) */
export const isNonSessionPayment = (course) => (course?.payment_type || 'session') !== 'session';

/** CourseManagement isSessionMode: explicit payment_type wins, else purchased > 0 */
export const isSessionPayment = (course) => {
  const paymentType = String(course?.payment_type || '').trim();
  if (paymentType) return paymentType === 'session';
  return Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) > 0;
};

/** ParentPortal: server schedule_mode other than 'count' (default count) */
export const isNonCountSchedule = (course) => String(course?.schedule_mode ?? 'count') !== 'count';

// ── remaining verdicts ───────────────────────────────────────
/**
 * StudentsList list badge: sessions-only, pool for package members, own count otherwise.
 * Monthly -> never low; package without a finite pool value -> not low.
 */
export const isSessionPaymentLow = (course) => {
  if (isMonthlyPaymentType(course)) return false;
  if (course?.PackageID) {
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
  course?.PackageID ? (course?.package_remaining_sessions ?? 0) : (course?.remaining_sessions ?? 0)
);

/** StudentsList 堂數進度條; null for package members / monthly / unverifiable data */
export const courseProgress = (course) => {
  if (isPackageMember(course)) return null;
  if (isMonthlyPaymentType(course)) return null;
  const total = finiteOrNull(course?.PackageID ? course?.package_total_sessions : course?.sessions_purchased);
  const remaining = finiteOrNull(course?.PackageID ? course?.package_remaining_sessions : (course?.remaining_sessions ?? course?.RemainingSessions));
  if (total == null || total <= 0 || remaining == null || remaining < 0) return null;
  const reportedUsed = finiteOrNull(course?.PackageID ? course?.package_used_sessions : (course?.used_sessions ?? course?.sessions_used));
  const used = Math.max(0, reportedUsed == null ? total - remaining : reportedUsed);
  const boundedUsed = Math.min(total, used);
  return {
    total,
    remaining,
    used: boundedUsed,
    percent: Math.min(100, Math.max(0, Math.round((boundedUsed / total) * 100))),
  };
};

// ── payment-status labels (display only; the server owns the status itself) ──
// Each surface historically used its own wording. Variants are kept, not unified.
export const TUITION_STATUS_CONFIG = {
  unpaid: { label: '應收／尚未回報', cls: 'st-unpaid' },
  partial: { label: '部分已入帳', cls: 'st-partial' },
  pending_report: { label: '已回報／待查帳', cls: 'st-pending' },
  pending_reconciliation: { label: '結案／待查帳', cls: 'st-pending' },
  paid: { label: '已確認入帳', cls: 'st-paid' },
  renew_needed: { label: '續課待處理', cls: 'st-renew' },
  monthly_due_soon: { label: '月結將到期', cls: 'st-monthly' },
};
export const INVOICE_STATUS_LABELS = {
  course: { paid: '已繳', unpaid: '未繳', partial: '部分繳', void: '已作廢' },
  ledger: { paid: '已繳', unpaid: '未繳', partial: '部分付款', void: '已作廢' },
};
export const REPORT_STATUS_LABELS = {
  course: { pending: '待對帳', confirmed: '已入帳', rejected: '已退回' },
  ledger: { confirmed: '已核帳', pending: '待對帳', voided: '已撤銷', rejected: '已退回' },
};

// ── monthly period payment labels (folded from monthlyPaymentDisplay.js) ──
export const periodPaymentLabel = (status) => ({
  paid: '已繳費', unpaid: '未繳費', partial: '部分繳', pending_report: '待對帳',
  review_required: '付款期間待確認', unknown: '付款期間待確認',
})[status] || '付款期間待確認';

export function monthlyPaymentLabel(course) {
  const summary = course?.monthly_payment;
  if (!summary) return null;
  if (summary.review_required) return '付款期間待確認';
  if (course.payment_status === 'pending_report' && summary.payment_status === 'unpaid') return `${summary.billing_period} 待對帳`;
  return `${summary.billing_period} ${periodPaymentLabel(summary.payment_status)}`;
}
