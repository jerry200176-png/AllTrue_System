// In-app #535: internal credit movement is neither new cash nor a cancelled receipt.
import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

it('names non-cash movements and explains the retained original receipt', async () => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'isolated' }));
  const methods = ['transfer_out', 'transfer_in', 'transfer_reversal_out', 'transfer_reversal_in'];
  const payload = { summary: {}, scope: { student_class_id: 1 }, courses: [], receipts: [], anomalies: [],
    invoices: [{ id: 7, student_class_id: 1, status: 'paid', total_amount: 9000, calculated_applied_amount: 9000,
      outstanding_amount: 0, transferred_amount: 3000, payments: methods.map((method, id) => ({ id: id + 1,
        method, amount: method.endsWith('out') ? -3000 : 3000, is_void: false, application_status: 'transferred' })) }] };
  vi.stubGlobal('fetch', vi.fn(async (url) => ({ ok: String(url).startsWith('/api/v1/accounting/ledger'),
    status: 200, json: async () => payload })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 },
    global: { stubs: { Transition: false, BillingNotesPanel: true } } });
  try {
    await flushPromises();
    await w.get('[data-testid="ledger-fold-invoices"]').trigger('click');
    expect(w.text()).toContain('已轉出 NT$ 3,000；原收據保留');
    await w.get('[data-testid="ledger-invoice-toggle-7"]').trigger('click');
    const history = w.get('.ledger-timeline__list').text();
    for (const label of ['額度轉出', '額度轉入', '還原轉出', '還原轉入', '額度異動']) expect(history).toContain(label);
    expect(history).not.toContain('更正收款');
    expect(history).not.toContain('transfer_');
  } finally { w.unmount(); }
});
