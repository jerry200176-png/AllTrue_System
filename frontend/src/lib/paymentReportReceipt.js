import { humanizeDocumentRef } from './studentClassDisplay.js';
import { classTypeLabel } from './calendarFormat.js';

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
  const typeKey = String(api.class_type || '').trim();
  if (typeKey) parts.push(classTypeLabel(typeKey) || typeKey);
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
    receipt_number: humanizeDocumentRef(api?.receipt_no) || '—',
    report_id: reportId,
    is_backfilled: !!api?.is_backfilled,
    // #934: course's billing mode changed since this receipt was issued —
    // the amount/period shown may no longer reflect the current contract.
    billing_mode_changed: !!api?.billing_mode_changed,
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
      note: api?.note || '',
      confirmed_at: api?.confirmed_at || null,
      confirmed_by: api?.confirmed_by || null,
      session_dates: Array.isArray(api?.session_dates) ? api.session_dates : [],
      attended_dates: Array.isArray(api?.attended_dates) ? api.attended_dates : [],
      class_type: api?.class_type || '',
      class_type_label: api?.class_type_label || '',
      course_lifecycle_label: api?.course_lifecycle_label || '',
      first_session_display: api?.first_session_display || api?.first_session_date || '',
      first_session_note: api?.first_session_note || '',
    },
  };
}

/** Positive integer payment-report id, or null (never NaN). */
export function parsePositiveReportId(raw) {
  if (raw == null || raw === '') return null;
  const n = typeof raw === 'number' ? raw : Number(String(raw).trim());
  if (!Number.isInteger(n) || n <= 0) return null;
  return n;
}

/**
 * Canonical receipt fetch path — regression lock against /api/v1/receipts* drift.
 * Throws if reportId is not a positive integer (callers must fail-fast before fetch).
 */
export function paymentReportReceiptUrl(reportId) {
  const id = parsePositiveReportId(reportId);
  if (id == null) {
    throw new Error('invalid_report_id');
  }
  return `/api/v1/payment-reports/${id}/receipt`;
}

const RECEIPT_METHOD_LABELS = {
  cash: '現金',
  transfer: '匯款',
  card: '信用卡',
  line_pay: 'LINE Pay',
  backfill: '現金（補建）',
};

function receiptAmount(value) {
  if (value == null || value === '' || Number.isNaN(Number(value))) return '—';
  return `NT$ ${Number(value).toLocaleString('zh-TW')}`;
}

/** Build the same human-readable fields shown in ReceiptModal. */
export function buildReceiptCopyText(snapshot = {}, receiptNumber = '—') {
  const lines = ['電子收據'];
  if (snapshot.student_name) lines.push(`學生姓名：${snapshot.student_name}`);
  if (snapshot.campus_name) lines.push(`分校：${snapshot.campus_name}`);
  if (snapshot.study_period) {
    lines.push(`修業期間：${snapshot.study_period.start || '—'} ~ ${snapshot.study_period.end || '—'}`);
  }
  lines.push(`收據號碼：${receiptNumber || '—'}`);

  const items = Array.isArray(snapshot.items) ? snapshot.items : [];
  if (items.length) {
    lines.push('收費項目：');
    items.forEach((item) => lines.push(`- ${item.description || '課程費用'}：${receiptAmount(item.amount)}`));
  }
  lines.push(`合計：${receiptAmount(snapshot.total_amount)}`);

  const sessionDates = Array.isArray(snapshot.session_dates) ? snapshot.session_dates : [];
  if (sessionDates.length) {
    lines.push(`上課日期：${sessionDates.slice(0, 16).map((session) => `${session.date || '—'}${session.expected ? '（尚未上）' : ''}`).join('、')}`);
    if (sessionDates.length > 16) lines.push(`上課日期：共 ${sessionDates.length} 堂`);
  }
  lines.push(`收款日期：${snapshot.paid_at || '—'}`);
  lines.push(`收款方式：${RECEIPT_METHOD_LABELS[snapshot.method] || snapshot.method || '—'}`);
  if (snapshot.note) lines.push(`備註：${snapshot.note}`);
  return lines.join('\n');
}
