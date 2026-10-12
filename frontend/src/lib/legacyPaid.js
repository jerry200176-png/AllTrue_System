// 舊制已繳、沒有帳單：後端 legacy_paid_without_invoice（無帳單且 Paid=1）。
// 帳務中心待處理列沒帶旗標時，以「已繳狀態＋無 invoice_id」判斷。
export const PAID_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];

export function isLegacyPaidNoInvoice(row) {
  return !!row?.legacy_paid_without_invoice
    || (PAID_STATUSES.includes(row?.payment_status) && !row?.invoice_id);
}
