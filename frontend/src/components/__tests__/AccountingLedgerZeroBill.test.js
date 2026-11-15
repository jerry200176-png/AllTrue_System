// Founder 2026-11-16: an old 「0 元」 登記 left a NT$0 bill marked paid; the ledger now says so and offers the way out.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

const bill = (o) => ({ id: 7, invoice_no: 'INV-7', student_class_id: 1, status: 'paid', total_amount: 0, paid_amount: 0, payments: [], reports: [], ...o });
const mountLedger = async (invoices) => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
  const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { name: '合成學生' }, invoices, receipts: [], anomalies: [] };
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ledger })));
  const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
  await flushPromises();
  return w;
};

describe('學生帳務 · 0 元卻標成已繳的帳單', () => {
  it('offers to undo the 0 元 receipt when one stands', async () => {
    const w = await mountLedger([bill({ reports: [{ report_id: 9, status: 'confirmed' }] })]);
    expect(w.text()).toContain('這張帳單是 0 元，卻標成已繳');
    expect(w.text()).toContain('撤銷 0 元收款');
    expect(w.get('[data-testid="ledger-exception-hint"]').text()).toContain('再照實際金額登記收款');
    expect(w.find('[data-testid="ledger-exception-report"]').exists()).toBe(false); // not a 回報給系統管理員 dead end
  });

  it('points at 作廢 when no receipt is left, and says nothing about a marked or a real bill', async () => {
    const bare = await mountLedger([bill({})]);
    expect(bare.text()).toContain('這張帳單是 0 元，卻標成已繳');
    expect(bare.get('[data-testid="ledger-exception-hint"]').text()).toContain('按「作廢」');
    expect(bare.text()).not.toContain('撤銷 0 元收款');
    expect(bare.find('[data-testid="ledger-exception-report"]').exists()).toBe(false);

    const fine = await mountLedger([bill({ zero_amount_reason: 'free_gift' }), bill({ id: 8, total_amount: 6000, paid_amount: 6000 })]);
    expect(fine.text()).not.toContain('這張帳單是 0 元，卻標成已繳');
  });
});
