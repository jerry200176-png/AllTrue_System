import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

// #3894 — real page + real PaymentSlipModal/ReceiptModal, mocked fetch, synthetic rows.
// Asserts the entry points only ever READ: no POST/PUT/PATCH/DELETE to any endpoint.
const rows = [
  { id: 101, student_name: '測試甲', subject: '數學', schedule_mode: 'date', payment_status: 'unpaid', charge: 4200, paid_amount: 0, outstanding: 4200, payable_amount: 4200, payable_outstanding: 4200, invoice_id: 501 },
  { id: 102, student_name: '測試乙', subject: '英文', schedule_mode: 'date', payment_status: 'partial', charge: 4000, paid_amount: 1000, outstanding: 3000, payable_amount: 4000, payable_outstanding: 3000, invoice_id: 502 },
  { id: 103, student_name: '測試丙', subject: '自然', schedule_mode: 'date', payment_status: 'paid', charge: 3800, paid_amount: 3800, outstanding: 0, payable_amount: 3800, payable_outstanding: 0, invoice_id: 503 },
  { id: 104, student_name: '測試丁', subject: '國文', schedule_mode: 'date', payment_status: 'none', charge: 0, paid_amount: 0, outstanding: 0 },
  { id: 105, student_name: '測試戊', subject: '社會', schedule_mode: 'date', payment_status: 'paid', charge: 3000, paid_amount: 3000, outstanding: 0, payable_amount: 3000, payable_outstanding: 0 },
];
const slipJson = { student_name: '測試甲', student_class_id: 101, subject: '數學', amount: 4200, items: [] };
let calls;

function installFetch() {
  calls = [];
  vi.stubGlobal('fetch', vi.fn(async (input, init = {}) => {
    const url = String(input);
    calls.push({ method: (init.method || 'GET').toUpperCase(), url });
    const ok = (body) => ({ ok: true, status: 200, json: async () => body, text: async () => JSON.stringify(body), headers: new Headers() });
    if (url.includes('/api/v1/alerts/tuition-slip/') || url.includes('/slip-data')) return ok(slipJson);
    if (url.includes('/api/v1/alerts/tuition')) return ok(rows);
    if (url.includes('/api/v1/payment-reports/9/receipt')) return ok({ data: {} });
    if (url.includes('/api/v1/payment-reports')) return ok({ data: [{ id: 9 }] });
    return ok({ data: [] });
  }));
}
function session(role) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { id: 1, role } }));
}
async function mountPage() {
  const w = mount(TuitionCollectionPage, { props: { branchId: 1, initialTab: 'all' }, attachTo: document.body });
  await flushPromises();
  return w;
}
const rowOf = (w, id) => w.get(`#tuition-row-${id}`);
const writes = () => calls.filter((c) => c.method !== 'GET');

beforeEach(() => { installFetch(); session('director'); });
afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = ''; localStorage.clear(); });

describe('帳務中心 學生列：繳費單／收據入口', () => {
  it('shows both entries on every row with the right enabled state and reason', async () => {
    const w = await mountPage();
    const t = (id, k) => rowOf(w, id).get(`[data-testid="tc-doc-${k}"]`).element.disabled;
    expect([t(101, 'slip'), t(101, 'receipt')]).toEqual([false, true]);
    expect([t(102, 'slip'), t(102, 'receipt')]).toEqual([false, true]);
    expect([t(103, 'slip'), t(103, 'receipt')]).toEqual([true, false]);
    expect([t(104, 'slip'), t(104, 'receipt')]).toEqual([true, true]);
    expect(rowOf(w, 104).get('[data-testid="tc-doc-slip-reason"]').text()).toBe('還沒開帳單');
    expect(rowOf(w, 101).get('[data-testid="tc-doc-receipt-reason"]').text()).toBe('收款確認後才有收據');
    // legacy paid, no invoice
    expect([t(105, 'slip'), t(105, 'receipt')]).toEqual([true, true]);
    expect(rowOf(w, 105).get('[data-testid="tc-doc-slip-reason"]').text()).toBe('已在舊系統繳清，沒有新帳單');
    expect(rowOf(w, 105).get('[data-testid="tc-doc-receipt-reason"]').text()).toBe('舊系統繳費，系統內沒有電子收據；可看繳費明細');
    await rowOf(w, 105).get('[data-testid="tc-doc-ledger"]').trigger('click');
    await flushPromises();
    expect(writes()).toEqual([]);
    w.unmount();
  });

  it('撤銷 is disabled with a reason for legacy paid (nothing to void), still enabled for invoiced paid', async () => {
    const w = await mountPage();
    const v105 = rowOf(w, 105).get('[data-testid="tc-void"]');
    expect(v105.element.disabled).toBe(true);
    expect(rowOf(w, 105).get('[data-testid="tc-void-reason"]').text()).toBe('舊制沒有系統收款，無可撤銷');
    expect(rowOf(w, 103).get('[data-testid="tc-void"]').element.disabled).toBe(false);
    expect(rowOf(w, 103).find('[data-testid="tc-void-reason"]').exists()).toBe(false);
    w.unmount();
  });

  it('non-director session: both disabled with 沒有權限', async () => {
    session('teacher');
    const w = await mountPage();
    for (const id of [101, 103]) {
      expect(rowOf(w, id).get('[data-testid="tc-doc-slip"]').element.disabled).toBe(true);
      expect(rowOf(w, id).get('[data-testid="tc-doc-receipt"]').element.disabled).toBe(true);
      expect(rowOf(w, id).get('[data-testid="tc-doc-slip-reason"]').text()).toBe('沒有權限');
    }
    w.unmount();
  });

  it('slip: double-click opens one preview, cancel closes it, no writes', async () => {
    const w = await mountPage();
    const btn = rowOf(w, 101).get('[data-testid="tc-doc-slip"]');
    await btn.trigger('click');
    await btn.trigger('click');
    await flushPromises();
    expect(document.querySelectorAll('.slip-modal')).toHaveLength(1);
    expect(calls.some((c) => c.url.includes('/api/v1/alerts/tuition-slip/') || c.url.includes('slip-data'))).toBe(true);
    await new Promise((r) => setTimeout(r, 450)); // 剛開啟 400ms 內忽略關閉（雙擊保護）
    document.querySelector('.slip-modal .icon-btn').click();
    await flushPromises();
    expect(document.querySelectorAll('.slip-modal')).toHaveLength(0);
    expect(writes()).toEqual([]);
    w.unmount();
  });

  it('receipt: double-click opens one preview, cancel closes it, only GETs', async () => {
    const w = await mountPage();
    const btn = rowOf(w, 103).get('[data-testid="tc-doc-receipt"]');
    await btn.trigger('click');
    await btn.trigger('click');
    await flushPromises();
    expect(calls.filter((c) => c.url.includes('/api/v1/payment-reports?'))).toHaveLength(1);
    expect(calls.some((c) => c.method === 'GET' && c.url.includes('/api/v1/payment-reports/9/receipt'))).toBe(true);
    expect(document.querySelectorAll('.modal-overlay').length).toBeGreaterThanOrEqual(1);
    expect(writes()).toEqual([]);
    w.unmount();
  });

  it('disabled entries never call any endpoint', async () => {
    const w = await mountPage();
    const before = calls.length;
    await rowOf(w, 104).get('[data-testid="tc-doc-slip"]').trigger('click');
    await rowOf(w, 101).get('[data-testid="tc-doc-receipt"]').trigger('click');
    await flushPromises();
    expect(calls.length).toBe(before);
    w.unmount();
  });
});
