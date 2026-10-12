import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

// #3894 — real page + real PaymentSlipModal/ReceiptModal, mocked fetch, synthetic rows; the entry points only ever READ.
const mkRow = (id, payment_status, payable_status) => ({
  id, student_id: 9000 + id, student_name: `測試${id}`, subject: '數學', schedule_mode: 'date', payment_status, payable_status,
  charge: 4000, outstanding: 4000, payable_amount: 4000, payable_outstanding: 4000,
});
const rows = [mkRow(101, 'unpaid', 'invoiced'), mkRow(103, 'paid', 'invoiced'), mkRow(104, 'none', 'unbilled'), mkRow(105, 'paid', 'unbilled')];
const slipJson = { student_name: '測試', student_class_id: 101, subject: '數學', amount: 4000, items: [] };
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

const tid = (w, id, k) => rowOf(w, id).get(`[data-testid="${k}"]`);

describe('帳務中心 學生列：繳費單／收據入口', () => {
  it('撤銷 is disabled with a reason for legacy paid (nothing to void); the ledger link only reads', async () => {
    const w = await mountPage();
    expect(tid(w, 105, 'tc-void').element.disabled).toBe(true);
    expect(tid(w, 105, 'tc-void-reason').text()).toBe('舊制沒有系統收款，無可撤銷');
    expect(tid(w, 103, 'tc-void').element.disabled).toBe(false);
    await tid(w, 105, 'tc-doc-ledger').trigger('click');
    await flushPromises();
    expect(writes()).toEqual([]);
    w.unmount();
  });

  it('double-click opens ONE existing preview (slip, receipt), cancel closes it, disabled entries call nothing, only GETs', async () => {
    const w = await mountPage();
    for (const [id, k] of [[101, 'slip'], [103, 'receipt']]) {
      await tid(w, id, `tc-doc-${k}`).trigger('click');
      await tid(w, id, `tc-doc-${k}`).trigger('click');
      await flushPromises();
    }
    expect(document.querySelectorAll('.slip-modal')).toHaveLength(1);
    expect(calls.filter((c) => c.url.includes('/api/v1/payment-reports?'))).toHaveLength(1);
    expect(calls.some((c) => c.url.includes('/api/v1/payment-reports/9/receipt'))).toBe(true);
    await new Promise((r) => setTimeout(r, 450)); // 剛開啟 400ms 內忽略關閉（雙擊保護）
    document.querySelector('.slip-modal .icon-btn').click();
    await flushPromises();
    expect(document.querySelectorAll('.slip-modal')).toHaveLength(0);
    const n = calls.length;
    await tid(w, 104, 'tc-doc-slip').trigger('click');
    await tid(w, 101, 'tc-doc-receipt').trigger('click');
    await flushPromises();
    expect(calls.length).toBe(n);
    expect(writes()).toEqual([]);
    w.unmount();
  });
});
