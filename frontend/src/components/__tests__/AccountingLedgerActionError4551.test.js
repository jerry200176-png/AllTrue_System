// #4551: a failed 撤銷收款 keeps the ledger on screen, rereads it and shows the reason beside a reload button.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

describe('學生帳務 · 操作失敗後保留畫面', () => {
  it('keeps the cards and shows the 422 reason after a failed void', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    vi.spyOn(window, 'prompt').mockReturnValue('重複登記');
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices: [], receipts: [], anomalies: [] };
    const fetchMock = vi.fn(async (url, opts = {}) => (opts.method === 'PUT'
      ? { ok: false, status: 422, json: async () => ({ message: '這筆收款已不能撤銷' }) }
      : { ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    const gets = fetchMock.mock.calls.filter(([, o]) => !o || !o.method || o.method === 'GET').length;
    await w.vm.voidReport(9);
    await flushPromises();
    expect(w.find('[data-testid="ledger-action-error"]').text()).toContain('這筆收款已不能撤銷');
    expect(w.text()).toContain('合成學生'); // the ledger header/cards are still there
    expect(fetchMock.mock.calls.filter(([, o]) => !o || !o.method || o.method === 'GET').length).toBeGreaterThan(gets); // reread
    expect(w.find('[data-testid="ledger-action-reload"]').exists()).toBe(true);
  });

  it('says so when the reason is missing or too short, instead of doing nothing', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices: [], receipts: [], anomalies: [] };
    const fetchMock = vi.fn(async (url, opts = {}) => (opts.method === 'POST'
      ? { ok: true, status: 200, json: async () => ({ message: '帳單已作廢。' }) }
      : { ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').length;
    const prompt = vi.spyOn(window, 'prompt');

    prompt.mockReturnValue('錯');
    await w.vm.voidInvoice({ id: 7 }, 'direct');
    await flushPromises();
    expect(w.get('[data-testid="ledger-reason-error"]').text()).toContain('原因至少要寫 2 個字');
    expect(posts()).toBe(0);

    prompt.mockReturnValue('   ');
    await w.vm.voidReport(9);
    await flushPromises();
    expect(w.get('[data-testid="ledger-reason-error"]').text()).toContain('沒有寫原因');

    // Cancelling the prompt is not an error.
    prompt.mockReturnValue(null);
    await w.vm.voidInvoice({ id: 7 }, 'direct');
    await flushPromises();
    expect(w.find('[data-testid="ledger-reason-error"]').exists()).toBe(false);

    // Two characters are a whole reason in Chinese.
    prompt.mockReturnValue('重複');
    await w.vm.voidInvoice({ id: 7 }, 'direct');
    await flushPromises();
    expect(posts()).toBe(1);
    expect(w.find('[data-testid="ledger-reason-error"]').exists()).toBe(false);
  });
});
