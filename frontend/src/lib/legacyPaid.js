// 已繳但沒有帳單（待處理列：payable_status = 'unbilled'，沒有 invoice_id）。這只代表「沒帳單」，不代表「沒收據」（可能有 receipt_without_invoice），
// 所以只用來停用繳費單；收據與撤銷仍交給既有查詢。欄位名由 slip_receipt_states_3894.json + SlipReceiptStateContractTest 鎖住。
export const PAID_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];

export const isLegacyPaidNoInvoice = (row) => !!row?.legacy_paid_without_invoice
  || (PAID_STATUSES.includes(row?.payment_status) && row?.payable_status === 'unbilled');
