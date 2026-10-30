// 繳費單／收據 availability: the one rule shared by the 帳務中心 list (TuitionDocActions, #3894) and the student
// ledger contract card (in-app #425). Input is a tuition-list row shape; each surface maps its own data into it.
// Returns { ok: true } or { ok: false, reason } (one line, shown or used to decide visibility by the caller).
import { isLegacyPaidNoInvoice, PAID_STATUSES as RECEIPT_STATUSES } from './legacyPaid.js';

const SLIP_STATUSES = ['unpaid', 'partial', 'pending_reconciliation'];
export const NO_PERMISSION = '沒有權限';
export const AMOUNT_PENDING_REASON = '金額需核對，不能產生繳費單';

export function slipAvailability(row, canView = true) {
  if (!canView) return { ok: false, reason: NO_PERMISSION };
  if (isLegacyPaidNoInvoice(row)) return { ok: false, reason: '已繳清，系統裡沒有帳單' };
  const s = row.payment_status;
  if (row.amount_pending_confirmation === true) return { ok: false, reason: AMOUNT_PENDING_REASON };
  if (row.id == null && !row.invoice_id && SLIP_STATUSES.includes(s)) return { ok: false, reason: '共用方案繳費單請至成員課程開立' }; // 沒有課程 id 也沒有帳單 id，繳費單無從查起
  if (SLIP_STATUSES.includes(s)) return { ok: true };
  if (s === 'pending_report') return { ok: false, reason: '已回報，等確認入帳' };
  if (RECEIPT_STATUSES.includes(s)) return { ok: false, reason: '已繳清，不用繳費單' };
  return { ok: false, reason: '還沒開帳單' };
}

export function receiptAvailability(row, canView = true) {
  if (!canView) return { ok: false, reason: NO_PERMISSION };
  if (row.id == null) return { ok: false, reason: '共用方案收據請至成員課程查看' }; // 月結包列沒有課程 id 可查收據
  if (row.has_confirmed_receipt === true) return { ok: true }; // #3942: the row's current bill has a confirmed receipt (e.g. a confirmed partial payment)
  if (row.payable_status === 'invoiced' && row.has_confirmed_receipt === false) return { ok: false, reason: '收款確認後才有收據' }; // #3942 review: the API's current-bill flag decides for a billed row, never an older term's receipt
  if (RECEIPT_STATUSES.includes(row.payment_status)) return { ok: true };
  return { ok: false, reason: '收款確認後才有收據' };
}
