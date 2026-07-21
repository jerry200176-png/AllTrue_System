/**
 * Adapter: PaymentReportController@receipt → ReceiptModal view model.
 * Only maps fields that exist on GET /api/v1/payment-reports/{id}/receipt.
 * Do not invent legal-info / void / PDF fields here.
 */

const BRAND_TITLE = '台北全真一對一補習班';

export function formatReceiptBrandTitle(campusName) {
  const branch = String(campusName || '').trim();
  return branch ? `${BRAND_TITLE}｜${branch}` : BRAND_TITLE;
}

export function buildReceiptItemDescription(api = {}) {
  const parts = [];
  if (api.subject) parts.push(api.subject);
  if (api.session_count != null && api.session_count !== '') {
    parts.push(`${api.session_count} 堂`);
  }
  const mode = api.schedule_mode === 'date'
    ? '月結制'
    : api.schedule_mode === 'count'
      ? '堂數制'
      : '';
  if (mode) parts.push(mode);
  return parts.length ? parts.join(' · ') : '課程費用';
}

export function adaptPaymentReportReceipt(api, reportId) {
  const periodStart = api?.period_start || null;
  const periodEnd = api?.period_end || null;
  return {
    receipt_number: api?.receipt_no || '—',
    report_id: reportId,
    is_backfilled: !!api?.is_backfilled,
    content_snapshot: {
      school_name: formatReceiptBrandTitle(api?.campus_name),
      campus_name: api?.campus_name || '',
      student_name: api?.student_name || '—',
      study_period: (periodStart || periodEnd)
        ? { start: periodStart || '—', end: periodEnd || '—' }
        : null,
      items: [{ description: buildReceiptItemDescription(api || {}), amount: api?.amount }],
      total_amount: api?.amount,
      paid_at: api?.payment_date || null,
      method: api?.payment_method || null,
      confirmed_at: api?.confirmed_at || null,
      confirmed_by: api?.confirmed_by || null,
      session_dates: Array.isArray(api?.session_dates) ? api.session_dates : [],
      attended_dates: Array.isArray(api?.attended_dates) ? api.attended_dates : [],
    },
  };
}

/** Canonical receipt fetch path — regression lock against /api/v1/receipts* drift. */
export function paymentReportReceiptUrl(reportId) {
  return `/api/v1/payment-reports/${Number(reportId)}/receipt`;
}
