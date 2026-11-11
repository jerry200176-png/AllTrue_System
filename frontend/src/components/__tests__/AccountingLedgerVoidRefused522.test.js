// in-app #522: when the server refuses 撤銷收據 because the receipt sits on another bill/student, the card must
// stop offering the same dead-end button and say why and what to do instead.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

const REFUSAL = '這張收據綁在別位學生或別門課的帳單上，這裡不能撤銷。請聯絡總部對帳，確認收據該歸哪張帳單。';

function setup(putResponse) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
  vi.spyOn(window, 'prompt').mockReturnValue('收據開錯');
  const ledger = {
    summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices: [], receipts: [],
    anomalies: [{ code: 'receipt_without_invoice', severity: 'warning', message: 'm', report_id: 9, invoice_id: null, student_class_id: 1, action: { type: 'void_report', report_id: 9 } }],
  };
  const fetchMock = vi.fn(async (url, opts = {}) => (opts.method === 'PUT' ? putResponse : { ok: true, status: 200, json: async () => ledger }));
  vi.stubGlobal('fetch', fetchMock);
  return { fetchMock, mountIt: () => mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } }) };
}

describe('學生帳務 · 撤銷收據被拒絕後', () => {
  it('replaces the void button with the reason and the next step', async () => {
    const { mountIt } = setup({ ok: false, status: 422, json: async () => ({ message: REFUSAL, code: 'void_invoice_mismatch', reason: 'invoice_other_owner' }) });
    const w = mountIt();
    await flushPromises();
    expect(w.findAll('button.ledger-action--danger').length).toBe(1); // offered before the first try
    await w.vm.voidReport(9);
    await flushPromises();
    expect(w.findAll('button.ledger-action--danger').length).toBe(0); // no second dead-end click
    const refused = w.find('[data-testid="ledger-void-refused"]');
    expect(refused.exists()).toBe(true);
    expect(refused.text()).toContain('這裡不能撤銷');
    expect(refused.text()).toContain('聯絡總部');
    expect(w.find('[data-testid="ledger-action-error"]').text()).toContain('這裡不能撤銷');
  });

  it('keeps the button after an unrelated failure (retry may work)', async () => {
    const { mountIt } = setup({ ok: false, status: 500, json: async () => ({ message: 'Server error' }) });
    const w = mountIt();
    await flushPromises();
    await w.vm.voidReport(9);
    await flushPromises();
    expect(w.findAll('button.ledger-action--danger').length).toBe(1);
    expect(w.find('[data-testid="ledger-void-refused"]').exists()).toBe(false);
  });
});
