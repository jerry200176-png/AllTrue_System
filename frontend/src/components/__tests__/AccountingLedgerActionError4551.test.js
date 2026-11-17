// #4551: a failed 撤銷收款 keeps the ledger on screen, rereads it and shows the reason beside a reload button.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

// The reason is typed in the drawer now (no window.prompt): start the action, type, press the action button.
async function withReason(w, call, reason) {
  const done = call();
  await flushPromises();
  await w.get('[data-testid="ledger-reason-input"]').setValue(reason);
  await w.get('[data-testid="ledger-reason-submit"]').trigger('click');
  await done;
}

describe('學生帳務 · 操作失敗後保留畫面', () => {
  it('keeps the cards and shows the 422 reason after a failed void', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices: [], receipts: [], anomalies: [] };
    const fetchMock = vi.fn(async (url, opts = {}) => (opts.method === 'PUT'
      ? { ok: false, status: 422, json: async () => ({ message: '這筆收款已不能撤銷' }) }
      : { ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    const gets = fetchMock.mock.calls.filter(([, o]) => !o || !o.method || o.method === 'GET').length;
    await withReason(w, () => w.vm.voidReport(9), '重複登記');
    await flushPromises();
    expect(w.find('[data-testid="ledger-action-error"]').text()).toContain('這筆收款已不能撤銷');
    expect(w.text()).toContain('合成學生'); // the ledger header/cards are still there
    expect(fetchMock.mock.calls.filter(([, o]) => !o || !o.method || o.method === 'GET').length).toBeGreaterThan(gets); // reread
    expect(w.find('[data-testid="ledger-action-reload"]').exists()).toBe(true);
  });

  it('asks for the reason in the drawer: too short cannot be sent, 取消 sends nothing, two characters are enough', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices: [], receipts: [], anomalies: [] };
    const fetchMock = vi.fn(async (url, opts = {}) => (opts.method === 'POST'
      ? { ok: true, status: 200, json: async () => ({ message: '帳單已作廢。' }) }
      : { ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const prompt = vi.fn();
    vi.stubGlobal('prompt', prompt);
    const w = mount(AccountingLedgerModal, { attachTo: document.body, props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    const posts = () => fetchMock.mock.calls.filter(([, o]) => o?.method === 'POST').length;
    const submit = () => w.get('[data-testid="ledger-reason-submit"]');

    const cancelled = w.vm.voidInvoice({ id: 7 }, 'direct');
    await flushPromises();
    expect(w.get('[data-testid="ledger-reason"]').text()).toContain('至少 2 個字');
    expect(submit().attributes('disabled')).toBeDefined();
    await w.get('[data-testid="ledger-reason-input"]').setValue(' 錯 ');
    expect(submit().attributes('disabled')).toBeDefined();
    await w.get('[data-testid="ledger-reason-cancel"]').trigger('click');
    await cancelled;
    await flushPromises();
    expect(w.find('[data-testid="ledger-reason"]').exists()).toBe(false);
    expect(posts()).toBe(0);
    expect(document.activeElement?.closest('[role="dialog"]')).not.toBeNull(); // focus stays in the drawer (Codex #5435)

    await withReason(w, () => w.vm.voidInvoice({ id: 7 }, 'direct'), '重複');
    await flushPromises();
    expect(posts()).toBe(1);
    expect(JSON.parse(fetchMock.mock.calls.find(([, o]) => o?.method === 'POST')[1].body).reason).toBe('重複');
    expect(prompt).not.toHaveBeenCalled();
    w.unmount();
  });
});
