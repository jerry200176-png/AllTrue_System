// 學生帳務對帳「涵蓋上課日」(M1, FR-003). No date rule lives here: the lists come
// from the printed payment slip (invoices/{id}/slip-data) and receipt
// (payment-reports/{id}/receipt) endpoints, normalised by the same view models
// BillingDocument renders, so the ledger can never disagree with the documents.
import { paymentSlipView, receiptView, formatSessionDate, STATUS_ZH, statusTone } from './billingDocumentView.js';
import { adaptPaymentReportReceipt, paymentReportReceiptUrl } from './paymentReportReceipt.js';

/** Dates shown before the 尚有 N 堂 toggle (DoS / scan density). */
export const COVERAGE_PREVIEW_LIMIT = 12;

export function invoiceCoverageUrl(invoiceId) {
  return `/api/v1/invoices/${Number(invoiceId)}/slip-data`;
}

export function receiptCoverageUrl(reportId) {
  return paymentReportReceiptUrl(reportId);
}

/** Only confirmed reports have a receipt document (the endpoint 422s otherwise). */
export function canShowReceiptCoverage(receipt) {
  return receipt?.status === 'confirmed' && Number(receipt?.report_id) > 0;
}

function toCoverage(sessions) {
  return (sessions || [])
    .filter((s) => s && s.date)
    .map((s, i) => {
      const status = String(s.status || '');
      return {
        key: `${s.date}-${s.start_time || ''}-${i}`,
        label: formatSessionDate(s.date),
        status,
        status_label: STATUS_ZH[status] || '',
        tone: statusTone(status),
      };
    });
}

export function coverageFromSlip(raw) {
  return toCoverage(paymentSlipView(raw || {}).sessions);
}

export function coverageFromReceipt(api, reportId) {
  return toCoverage(receiptView(adaptPaymentReportReceipt(api || {}, reportId)).sessions);
}

/** { total, shown, hidden } for the collapsed / show-all state. */
export function coveragePreview(sessions, showAll = false, limit = COVERAGE_PREVIEW_LIMIT) {
  const list = sessions || [];
  const shown = showAll ? list : list.slice(0, limit);
  return { total: list.length, shown, hidden: list.length - shown.length };
}
