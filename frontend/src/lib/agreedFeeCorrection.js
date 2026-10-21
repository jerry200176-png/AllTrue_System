import { authedFetch } from './authedFetch';

// Staff UI for the existing, default-off agreed-fee correction API
// (POST /student-classes/{id}/agreed-fee-correction/{preview|apply}).
// The server re-checks every gate; these helpers only shape requests and
// surface the server's own refusal message verbatim.

export const REASON_MIN = 3;
export const REASON_MAX = 80;
export const CHARGE_MAX = 999999;

/** True only when the invoices response explicitly says the correction would be accepted. */
export function isAgreedFeeCorrectionAvailable(invoicesPayload) {
  return invoicesPayload?.agreed_fee_correction_available === true;
}

/** The course's single live unpaid invoice id, or null when there is none or more than one. */
export function singleLiveUnpaidInvoiceId(invoices) {
  const live = (Array.isArray(invoices) ? invoices : [])
    .filter((invoice) => invoice && invoice.status !== 'void');
  if (live.length !== 1) return null;
  const [invoice] = live;
  if (invoice.status !== 'unpaid' || Number(invoice.paid_amount || 0) > 0) return null;
  const id = Number(invoice.id);
  return Number.isInteger(id) && id > 0 ? id : null;
}

/** Staff-facing invoice label: the list's invoice_no when known, else INV-...-<6-digit id> like the backend. */
export function formatInvoiceRef(id, invoices = []) {
  const n = Number(id);
  if (!Number.isInteger(n) || n < 1) return '—';
  const known = (Array.isArray(invoices) ? invoices : []).find((invoice) => Number(invoice?.id) === n);
  if (known?.invoice_no) return String(known.invoice_no);
  return `INV-…-${String(n).padStart(6, '0')}`;
}

export function newIdempotencyKey(cryptoImpl = globalThis.crypto) {
  return cryptoImpl.randomUUID();
}

/** Returns a Chinese validation message, or '' when the form can be previewed. */
export function validateCorrectionForm(form) {
  if (form.scope !== 'billed' && form.scope !== 'unbilled') return '請選擇更正範圍。';
  if (form.scope === 'billed') {
    const invoiceId = Number(form.originalInvoiceId);
    if (!Number.isInteger(invoiceId) || invoiceId < 1) return '請填寫要更正的原帳單編號。';
  }
  const charge = Number(form.newCharge);
  if (!Number.isInteger(charge) || charge < 1 || charge > CHARGE_MAX) return `新約定費用需為 1 到 ${CHARGE_MAX} 的整數。`;
  const reason = String(form.reason || '').trim();
  if (reason.length < REASON_MIN || reason.length > REASON_MAX) return `更正原因需為 ${REASON_MIN}–${REASON_MAX} 字。`;
  return '';
}

/** Request body sent to preview; apply sends the identical body plus preview_token. */
export function buildCorrectionBody(form, idempotencyKey) {
  const body = {
    scope: form.scope,
    new_charge: Number(form.newCharge),
    reason: String(form.reason || '').trim(),
    idempotency_key: idempotencyKey,
  };
  if (form.scope === 'billed') body.original_invoice_id = Number(form.originalInvoiceId);
  return body;
}

function firstValidationMessage(json) {
  const errors = json?.errors;
  if (!errors || typeof errors !== 'object') return '';
  const first = Object.values(errors).flat().find((value) => typeof value === 'string' && value);
  return first || '';
}

/**
 * POSTs one step. Resolves to { ok, status, data, code, message }; never throws for HTTP errors.
 * `message` is the server's message verbatim when it sent one.
 */
export async function postAgreedFeeCorrection(courseId, step, body, fetchImpl = authedFetch) {
  if (step !== 'preview' && step !== 'apply') throw new Error(`unknown step ${step}`);
  const res = await fetchImpl(`/api/v1/student-classes/${Number(courseId)}/agreed-fee-correction/${step}`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (res.ok) return { ok: true, status: res.status, data, code: '', message: '' };
  const fallback = res.status === 404 ? '此課程目前不能使用約定費用更正。' : `更正失敗（HTTP ${res.status}）。`;
  return {
    ok: false,
    status: res.status,
    data,
    code: typeof data?.code === 'string' ? data.code : '',
    message: firstValidationMessage(data) || (typeof data?.message === 'string' && data.message) || fallback,
  };
}

/** Refusals after which the current preview token can no longer be used. */
export function requiresNewPreview(result) {
  return !result.ok && result.status === 409;
}
