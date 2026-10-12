// 舊制已繳、沒有帳單：後端 legacy_paid_without_invoice（無帳單且 Paid=1，已結清分頁的列有旗標）。
// 帳務中心待處理列沒有該旗標；後端 BillingPayableResolver 對「已繳但沒有任何帳單」回
// payable_status = 'unbilled'（有帳單時為 'invoiced'）。注意待處理列沒有 invoice_id（只有 payable_invoice_id）。
// 欄位名由 backend/tests/Fixtures/slip_receipt_states_3894.json + SlipReceiptStateContractTest 鎖住。
export const PAID_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];

export function isLegacyPaidNoInvoice(row) {
  return !!row?.legacy_paid_without_invoice
    || (PAID_STATUSES.includes(row?.payment_status) && row?.payable_status === 'unbilled');
}
