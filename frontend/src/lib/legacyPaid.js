// 舊制已繳、沒有帳單。待處理列沒有 legacy_paid_without_invoice（只有已結清分頁有）也沒有 invoice_id；
// 後端對「已繳但沒有任何帳單」回 payable_status = 'unbilled'。欄位名由 slip_receipt_states_3894.json + SlipReceiptStateContractTest 鎖住。
export const PAID_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];

export const isLegacyPaidNoInvoice = (row) => !!row?.legacy_paid_without_invoice
  || (PAID_STATUSES.includes(row?.payment_status) && row?.payable_status === 'unbilled');
