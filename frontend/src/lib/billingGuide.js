// 智慧登帳引導: one bill at a time. Fixed rules only: the queue is the bill list's 要處理 rows (billRows), and every
// amount, date and state shown is what the server sent. Nothing here calculates money or decides a payment state.
import { TODO_KINDS } from './billRows.js';
import { formatCoursePeriod } from './studentBillingRows.js';

export const NO_DATA = '沒有資料';
const MODE = { count: '堂數制', date: '月結制' };
const ymd = (d) => (d ? String(d).slice(0, 10) : null);

/** The rows the guide walks, in the list's own order (billRows sorts 待確認入帳 → 未繳 → 要開帳單 → 需核對). */
export const guideQueue = (bills) => (bills || []).filter((b) => TODO_KINDS.includes(b.kind));

/**
 * Which bill comes next. `done` = handled or 略過 this run (never offered again); `later` = 稍後處理 (offered after all the
 * others, oldest first). Returns null when nothing is left.
 */
export function nextBill(queue, { done = new Set(), later = [] } = {}) {
  const open = queue.filter((b) => !done.has(b.key));
  return open.find((b) => !later.includes(b.key))
    || later.map((key) => open.find((b) => b.key === key)).find(Boolean)
    || null;
}

/**
 * What one bill shows. A missing value stays null (the screen prints 沒有資料), never a guess.
 * `invoice` = that bill's row from /accounting/ledger (the bill 登記 and the slip point at), when there is one.
 */
export function guideFacts(bill, invoice = null) {
  const r = bill.src || {};
  const billed = r.payable_status === 'invoiced' || r.payable_invoice_id != null;
  const num = (v) => (v == null || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
  const range = (a, b) => (a || b ? formatCoursePeriod(a, b) : null);
  return {
    student: bill.student_name || null,
    course: bill.subject || null,
    is_package: bill.class_id == null,
    mode: MODE[r.schedule_mode] || null,
    course_period: range(r.course_start_date, r.course_end_date),
    billed,
    bill_period: billed ? range(invoice?.period_start ?? r.billing_period_start, invoice?.period_end ?? r.billing_period_end) || r.billing_period || null : null,
    due_date: ymd(invoice?.due_date ?? r.due_date), // a count contract's deadline is only on its bill
    amount_pending: r.amount_pending_confirmation === true,
    // 應收／已收／未繳 of THAT bill, as the server reports them. No bill yet: only the server's 預估.
    receivable: billed ? num(invoice?.total_amount ?? r.payable_amount) : null,
    received: billed ? num(invoice ? invoice.calculated_applied_amount : null) : null,
    outstanding: billed ? num(invoice?.outstanding_amount ?? r.payable_outstanding) : null,
    estimate: billed ? null : num(r.estimated_amount),
  };
}
