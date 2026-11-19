// One module for every billing money write (architecture review candidate #2).
// Callers say "do X to this report/invoice" and get { ok, data, code, message };
// auth, JSON, and human error wording live here instead of in each page/modal.
import { authedFetch } from './authedFetch.js';
import { humanizeApiErrorMessage } from './humanizeApiErrorMessage.js';

async function send(path, method, body, fallback) {
  try {
    const resp = await authedFetch(`/api/v1/${path}`, {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      ...(method === 'GET' ? {} : { body: JSON.stringify(body ?? {}) }),
    });
    const data = await resp.json().catch(() => ({}));
    if (resp.ok) return { ok: true, status: resp.status, data, code: null, message: '' };
    return { ok: false, status: resp.status, data, code: data.code ?? null, message: humanizeApiErrorMessage(data.message || `${fallback}（${resp.status}）`) };
  } catch (e) {
    return { ok: false, data: {}, code: 'network', message: humanizeApiErrorMessage(e?.message || fallback) };
  }
}

const id = (v) => Number(v);

export const recordPayment = (body) => send('payment-reports/director-record', 'POST', body, '登錄失敗');
export const recordPaymentBatch = (body) => send('payment-reports/director-record-batch', 'POST', body, '批次登錄失敗');
export const confirmReport = (reportId) => send(`payment-reports/${id(reportId)}/confirm`, 'PUT', {}, '確認入帳失敗');
export const confirmReportBatch = (body) => send('payment-reports/confirm-batch', 'POST', body, '批次確認失敗');
export const rejectReport = (reportId, note) => send(`payment-reports/${id(reportId)}/reject`, 'PUT', { rejection_note: note }, '退回失敗');
export const voidReport = (reportId, reason) => send(`payment-reports/${id(reportId)}/void`, 'PUT', { void_reason: reason }, '撤銷失敗');
export const voidInvoice = (invoiceId, reason, { exception = false } = {}) =>
  send(`invoices/${id(invoiceId)}/${exception ? 'exception-void' : 'void'}`, 'POST', { reason }, exception ? '更正並作廢失敗' : '作廢失敗');
// Step 2 更正帳單 (backend #5483): preview shows the plan and its state_hash; apply must send that hash back.
export const previewBillCorrection = (invoiceId, body) => send(`invoices/${id(invoiceId)}/correction/preview`, 'POST', body, '預覽失敗');
export const applyBillCorrection = (invoiceId, body) => send(`invoices/${id(invoiceId)}/correction/apply`, 'POST', body, '更正失敗');
// 收據移到正確帳單 (#5113 backend #5304/#5305): targets lists the bills that can take the receipt, preview shows both balances
// and a state_hash, apply must send that hash and a reason back.
export const getReceiptMoveTargets = (reportId, studentId) =>
  send(`payment-reports/${id(reportId)}/reassign-targets${studentId ? `?student_id=${id(studentId)}` : ''}`, 'GET', null, '讀取帳單失敗');
export const previewReceiptMove = (reportId, targetInvoiceId) =>
  send(`payment-reports/${id(reportId)}/reassign`, 'POST', { target_invoice_id: id(targetInvoiceId), preview: true }, '預覽失敗');
export const applyReceiptMove = (reportId, body) =>
  send(`payment-reports/${id(reportId)}/reassign`, 'POST', { ...body, target_invoice_id: id(body.target_invoice_id) }, '移動失敗');
// Founder GO 2026-10-21 (§只有舊「已收」標記): resolve a legacy-flag-only course either way.
export const backfillLegacyPayment = (body) => send('payment-reports/director-record', 'POST', { ...body, legacy_flag_backfill: true }, '補登收款失敗');
export const clearLegacyPaidFlag = (studentClassId, reason) =>
  send('payment-reports/legacy-flag-clear', 'POST', { student_class_id: id(studentClassId), reason }, '改成未繳失敗');
