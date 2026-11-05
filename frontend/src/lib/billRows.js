import { isLegacyPaidFlagOnly, LEGACY_FLAG_UNVERIFIED_HELP, LEGACY_FLAG_UNVERIFIED_LABEL, REPORT_STATUS_LABELS, TUITION_STATUS_CONFIG, WAIVED_LABEL } from './courseMoneyState.js';
import { formatCoursePeriod, isDueLater, isPendingConfirmation, owedItems, pendingReviewAmount, splitOwed } from './studentBillingRows.js';
import { formatNewerCourseOverlapWarning } from './studentClassDisplay.js';

// Billing v3 (PRD §0): one row per contract that needs something, built from the same /alerts/tuition rows as before.
// `kind` drives the chip, the primary button and which rows can be batched together.
export const BILL_KINDS = ['confirm', 'owing', 'issue', 'review', 'paid', 'waived'];
export const TODO_KINDS = ['confirm', 'owing', 'issue', 'review'];

const OWING = new Set(['unpaid', 'partial', 'pending_reconciliation', 'monthly_due_soon', 'renew_needed']);
const PAID = new Set(['paid', 'renew_needed', 'monthly_due_soon', 'free']);
const KIND_ORDER = Object.fromEntries(BILL_KINDS.map((k, i) => [k, i]));
const dayStart = (d) => new Date(`${String(d).slice(0, 10)}T00:00:00`);
const money = (v) => `NT$ ${Number(v || 0).toLocaleString('zh-TW')}`;

function kindOf(r, items) {
  const s = r.payment_status;
  if (s === 'pending_report' || r.latest_payment_report_id) return 'confirm';
  if (isPendingConfirmation(r) || (PAID.has(s) && isLegacyPaidFlagOnly(r))) return 'review';
  if (s === 'waived' || r.closed_reason === 'waived') return 'waived';
  if (OWING.has(s) && items.some((it) => it.amount > 0)) return 'owing';
  // Same renew threshold/status as the old 需續課 chip: the server's renew_needed / monthly_due_soon.
  if (r.renewed_by_newer_course !== true && (s === 'renew_needed' || s === 'monthly_due_soon')) return 'issue';
  if (OWING.has(s) && !PAID.has(s) && r.payable_status === 'unbilled') return 'issue';
  return PAID.has(s) ? 'paid' : 'review'; // never drop a row: an unknown state is something to check
}

function labelOf(kind, r, owedNow) {
  if (kind === 'confirm') return REPORT_STATUS_LABELS.pending;
  if (kind === 'review') return isLegacyPaidFlagOnly(r) ? LEGACY_FLAG_UNVERIFIED_LABEL : '需核對';
  if (kind === 'waived') return WAIVED_LABEL;
  if (kind === 'paid') return REPORT_STATUS_LABELS.confirmed;
  if (kind === 'issue') return r.payment_status === 'renew_needed' || r.payment_status === 'monthly_due_soon' ? TUITION_STATUS_CONFIG[r.payment_status].label : '要開帳單';
  if (owedNow <= 0) return '快到期';
  return r.payment_status === 'partial' || r.payment_status === 'pending_reconciliation' ? TUITION_STATUS_CONFIG[r.payment_status].label : TUITION_STATUS_CONFIG.unpaid.label;
}

function hintOf(kind, r, owedNow, owedLater) {
  if (kind === 'confirm') return '家長已回報';
  if (kind === 'owing' && r.payment_status === 'partial' && Number(r.paid_amount) > 0) return `已收 ${money(r.paid_amount)}`;
  if (kind === 'owing' && owedNow > 0 && owedLater > 0) return `之後還會到期 ${money(owedLater)}`;
  return '';
}

// Notes the old list carried beside a row (#1100 期間重疊, 依實際堂數計算): always shown, never replaced by a hint.
function warningsOf(r) {
  const who = r.subject ? `${r.subject}：` : '';
  const out = [];
  if (r.newer_course_overlap) out.push(`${who}${formatNewerCourseOverlapWarning(r).primary}`);
  if (r.invoice_amount_discrepancy) out.push(`${who}依實際 ${r.invoice_period_sessions} 堂計算（原始帳單 ${money(r.invoice_stored_amount)}；實際計算 ${money(r.invoice_computed_amount)}）`);
  return out;
}

/**
 * @param {Array} alertRows rows from GET /alerts/tuition
 * @param {string} today    YYYY-MM-DD
 */
export function billRows(alertRows, today = new Date().toISOString().slice(0, 10)) {
  const seen = new Set();
  const out = (alertRows || []).map((r, i) => {
    const items = owedItems(r);
    const { now: owed, later } = splitOwed(items, today);
    const kind = kindOf(r, items);
    const dues = items.filter((it) => it.amount > 0 && it.due_date && !isDueLater(it.due_date, today)).map((it) => String(it.due_date).slice(0, 10)).sort();
    const nextDue = dues[0] || items.filter((it) => it.amount > 0 && it.due_date).map((it) => String(it.due_date).slice(0, 10)).sort()[0] || null;
    const base = r.id != null ? `c${r.id}` : r.package_id != null ? `p${r.package_id}` : `i${i}`;
    const key = seen.has(base) ? `${base}-${i}` : base;
    seen.add(base);
    const remaining = r.schedule_mode === 'date' || r.remaining_sessions == null ? null : Math.max(0, Number(r.remaining_sessions));
    return {
      key, kind, src: r,
      class_id: r.id ?? null,
      package_id: r.package_id ?? null,
      student_id: Number(r.student_id) || null,
      student_name: r.student_name || '',
      subject: r.subject || '',
      period: remaining !== null ? `剩 ${remaining} 堂` : formatCoursePeriod(r.course_start_date, r.course_end_date),
      owed: kind === 'owing' ? owed : 0,
      owed_later: later,
      due_date: nextDue,
      overdue_days: kind === 'owing' && dues[0] ? Math.max(0, Math.round((dayStart(today) - dayStart(dues[0])) / 86400000)) : 0,
      remaining,
      sort_remaining: r.renewed_by_newer_course === true ? null : remaining, // in-app #495: a renewed contract is not the lessons-left signal
      review_amount: pendingReviewAmount(r),
      warnings: warningsOf(r),
      label: labelOf(kind, r, owed),
      hint: hintOf(kind, r, owed, later),
      help: kind === 'review' && isLegacyPaidFlagOnly(r) ? LEGACY_FLAG_UNVERIFIED_HELP : '',
    };
  });
  return out.sort((a, b) => KIND_ORDER[a.kind] - KIND_ORDER[b.kind] || b.overdue_days - a.overdue_days || b.owed - a.owed
    || a.student_name.localeCompare(b.student_name, 'zh-Hant'));
}

/** Per-kind counts, plus `todo` (the 要處理 union). */
export function billCounts(rows) {
  const c = Object.fromEntries(BILL_KINDS.map((k) => [k, 0]));
  for (const r of rows || []) c[r.kind] += 1;
  c.todo = TODO_KINDS.reduce((n, k) => n + c[k], 0);
  return c;
}
