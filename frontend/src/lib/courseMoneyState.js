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

/**
 * Money verdict of a closed course: 'pending' (closed, not paid: settled_pending, or an amended contract the server
 * says is not settled), 'unknown' (an amended contract without a server verdict, e.g. a `_noncanonical` fallback row:
 * never 已結算), 'settled' (an amended contract that is paid/free), null for every other row.
 */
export const closedPaymentState = (course) => {
  const reason = closedReason(course);
  if (reason === 'settled_pending') return 'pending';
  if (reason !== 'contract_amended') return null;
  const settled = isCourseSettled(course);
  if (settled === null) return 'unknown';
  return settled ? 'settled' : 'pending';
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
  pending_report: { label: '已回報付款，待你核對入帳', cls: 'st-pending' },
  // Tab/badge (in-app #395-#404): no money received yet. Rows say ended vs paused (ENDED_/PAUSED_PENDING_LABEL).
  pending_reconciliation: { label: '課已結束，尚未收款', cls: 'st-pending' },
  // Founder D2 (in-app #394): an open bill whose amount is still to be confirmed. Listed, never summed as owed.
  amount_review: { label: '金額待確認', cls: 'st-pending' },
  paid: { label: '已收', cls: 'st-paid' },
  // #4150: free-gift contract (BillingPayableResolver 'free'). Settled, nothing to collect. Same text as FREE_GIFT_LABEL.
  free: { label: '免費／贈送', cls: 'st-paid' },
  renew_needed: { label: '續課待處理', cls: 'st-renew' },
  monthly_due_soon: { label: '月結將到期', cls: 'st-monthly' },
};
export const WAIVED_LABEL = TUITION_STATUS_CONFIG.waived.label;
// #4150: the ledger row's server verdict (ContractMoneyState::isWaived). A real waiver keeps Paid=0, so never infer it here.
export const isWaivedContract = (course) => course?.waived === true;

// #4150 (Codex on #4201): one invoice's payment rows -> the latest receipt still standing after reversals, or null.
// Mirrors BillingPayableResolver::latestUnreversedReceiptDate: PaymentReportController::void writes a separate negative
// Method='void' row and leaves the positive row is_void=false, so rows are netted, never filtered one by one. In write
// (id) order each reversal cancels (1) the receipt sharing its report_id, (2) else the single earlier receipt whose
// standing amount equals the remainder, else the oldest earlier receipts; (3) anything left nets against later receipts,
// so a net-zero invoice has no date while a later valid repayment keeps its own date.
const isReversalRow = (p) => Number(p?.amount || 0) < 0 || String(p?.method || '') === 'void';
const cancelReceipts = (receipts, amount, matches) => {
  let left = amount;
  for (const r of receipts) {
    if (left <= 0) break;
    if (r.left > 0 && matches(r)) {
      const take = Math.min(left, r.left);
      r.left -= take;
      left -= take;
    }
  }
  return left;
};
export const latestStandingPayment = (payments) => {
  const rows = [...(payments || [])].sort((a, b) => Number(a.id) - Number(b.id));
  const receipts = rows.filter((p) => !isReversalRow(p) && Number(p.amount || 0) > 0)
    .map((p) => ({ left: Number(p.amount), paidAt: String(p.paid_at || ''), id: Number(p.id), report: p.report_id == null ? null : Number(p.report_id) }))
    .sort((a, b) => a.paidAt.localeCompare(b.paidAt) || a.id - b.id);
  let orphaned = 0;
  for (const rev of rows.filter(isReversalRow)) {
    let amount = Math.abs(Number(rev.amount || 0));
    const report = rev.report_id == null ? null : Number(rev.report_id);
    if (report !== null) amount = cancelReceipts(receipts, amount, (r) => r.report === report);
    const earlier = (r) => r.id < Number(rev.id);
    const exact = receipts.filter((r) => earlier(r) && r.left > 0 && r.left === amount);
    if (amount > 0 && exact.length === 1) {
      exact[0].left = 0;
      amount = 0;
    }
    orphaned += cancelReceipts(receipts, amount, earlier);
  }
  cancelReceipts(receipts, orphaned, () => true);
  const latest = receipts.filter((r) => r.left > 0 && r.paidAt)
    .sort((a, b) => b.paidAt.localeCompare(a.paidAt) || b.id - a.id)[0];
  return latest ? { date: latest.paidAt.slice(0, 10), amount: latest.left } : null;
};
// #4114 (Founder Q3): paid only by the legacy Paid / package flag, no Payment on record. Neutral: never green 已收, never 未繳.
export const LEGACY_FLAG_UNVERIFIED_LABEL = '需核對：舊資料標記已收，缺付款紀錄';
export const isLegacyPaidFlagOnly = (row) => row?.legacy_paid_flag_only === true;
export const ENDED_PENDING_LABEL = '課已結束，尚未收款';
export const PAUSED_PENDING_LABEL = '暫停中，尚未收款';
export const INVOICE_STATUS_LABELS = { paid: '已收', unpaid: '未繳', partial: '繳了一部分', void: '已作廢' };
// #3991 (Founder 2026-10-13): a NT$0 bill exists only when marked free/gift. Mirrors Invoice::ZERO_REASON_FREE_GIFT.
export const ZERO_REASON_FREE_GIFT = 'free_gift';
export const FREE_GIFT_LABEL = '免費／贈送';
// `voided` only exists in the ledger, the one screen that lists voids.
export const REPORT_STATUS_LABELS = { pending: '待核對入帳', confirmed: '已收', rejected: '已退回', voided: '已撤銷' };

// ── monthly period payment labels (folded from monthlyPaymentDisplay.js) ──
// Review wording only for review cases; any other unmapped status stays neutral (in-app #395-#404).
export const REVIEW_REQUIRED_LABEL = '月結帳單與上課堂數不符，請核對';
export const UNKNOWN_STATUS_LABEL = '狀態待確認';
export const periodPaymentLabel = (status) => ({
  paid: '已收', unpaid: '未繳', partial: '繳了一部分', pending_report: REPORT_STATUS_LABELS.pending,
  // 'unknown' = sessions with no bill (MonthlyPeriodPaymentService), itself a review case.
  review_required: REVIEW_REQUIRED_LABEL, unknown: REVIEW_REQUIRED_LABEL,
})[status] || UNKNOWN_STATUS_LABEL;

export function monthlyPaymentLabel(course) {
  const summary = course?.monthly_payment;
  if (!summary) return null;
  if (summary.review_required) return REVIEW_REQUIRED_LABEL;
  if (course.payment_status === 'pending_report' && summary.payment_status === 'unpaid') return `${summary.billing_period} ${periodPaymentLabel('pending_report')}`;
  return `${summary.billing_period} ${periodPaymentLabel(summary.payment_status)}`;
}

// ── course-level payment badge (StudentsList + CourseManagement) ──
export const PAYMENT_STATUS_LOADING_LABEL = '繳費狀態載入中';
const BADGE_TONES = {
  paid: 'settled', free: 'free', partial: 'owing', unpaid: 'owing',
  pending_report: 'review', amount_review: 'review', review_required: 'review',
};
const NEXT_ACTIONS = { unpaid: 'report_payment', partial: 'report_payment', pending_report: 'view_report' };

/**
 * #4150: the one course payment badge. Returns { label, tone, nextAction }; pages map tone to their
 * own CSS class and nextAction to their own button text. Every server status passes through;
 * a status this does not know is neutral (狀態待確認), never 未繳.
 * tone: anomaly | no_payment | settled | free | owing | review | neutral
 * nextAction: none | report_payment | view_report | billing
 * monthlyPeriod: show the dated monthly period label (課程查找) when the course has one.
 */
export const coursePaymentBadge = (course, { monthlyPeriod = false } = {}) => {
  if (course?.class_type === 'tutoring') {
    return course?.tutoring_billing_anomaly === true
      ? { label: '帳務資料需修正', tone: 'anomaly', nextAction: 'none' }
      : { label: '無須繳費', tone: 'no_payment', nextAction: 'none' };
  }
  if (closedReason(course) === 'waived') return { label: WAIVED_LABEL, tone: 'settled', nextAction: 'billing' };
  // #4114 (Founder Q3): paid by the legacy flag alone (no Payment) is 需核對, never 已收.
  if (isLegacyPaidFlagOnly(course)) return { label: LEGACY_FLAG_UNVERIFIED_LABEL, tone: 'review', nextAction: 'billing' };
  if (isCourseSettled(course) === null) return { label: PAYMENT_STATUS_LOADING_LABEL, tone: 'neutral', nextAction: 'billing' };
  const status = String(course.payment_status).toLowerCase();
  const nextAction = NEXT_ACTIONS[status] || 'billing';
  const tone = BADGE_TONES[status] || 'neutral';
  // The free/gift verdict outranks the period label: its NT$0 period reads 已收 or review (Codex P2 on #4204).
  const monthly = monthlyPeriod && status !== 'free' ? monthlyPaymentLabel(course) : null;
  if (monthly) return { label: monthly, tone: course.monthly_payment.review_required ? 'review' : tone, nextAction };
  if (status === 'review_required') return { label: REVIEW_REQUIRED_LABEL, tone, nextAction };
  return { label: TUITION_STATUS_CONFIG[status]?.label || UNKNOWN_STATUS_LABEL, tone, nextAction };
};
