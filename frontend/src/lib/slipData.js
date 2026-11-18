// The payment slip's data, read once for every surface that shows a slip (PaymentSlipModal, 智慧登帳引導).
import { trackedFetch } from './loadingBar';
import { AMOUNT_PENDING_REASON } from './billingDocState.js';

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

async function read(url, fallback) {
  const resp = await trackedFetch(url, { headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` } });
  if (!resp.ok) {
    // Founder D2: a 422 says why (e.g. 金額需核對，不能產生繳費單); show it instead of a generic error.
    const body = await resp.json().catch(() => ({}));
    throw new Error(body.message || fallback);
  }
  return resp.json();
}

/** An issued bill's slip when there is an invoice id, otherwise the contract's slip (預估, no bill yet). */
export async function loadSlipData({ invoiceId = null, studentClassId = null } = {}) {
  if (!invoiceId) return read(`/api/v1/alerts/tuition-slip/${studentClassId}`, '無法載入催繳資料');
  const body = await read(`/api/v1/invoices/${invoiceId}/slip-data`, '無法載入帳單資料');
  // Founder D2: an unpaid bill whose amount still needs checking never becomes a parent slip (no render, no share).
  if (body?.amount_pending_confirmation === true) throw new Error(AMOUNT_PENDING_REASON);
  return body;
}
