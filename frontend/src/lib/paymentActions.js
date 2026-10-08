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
      body: JSON.stringify(body ?? {}),
    });
    const data = await resp.json().catch(() => ({}));
    if (resp.ok) return { ok: true, data, code: null, message: '' };
    return { ok: false, data, code: data.code ?? null, message: humanizeApiErrorMessage(data.message || `${fallback}（${resp.status}）`) };
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
