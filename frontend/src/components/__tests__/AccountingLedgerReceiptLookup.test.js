import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const Receipt = { props: ['show', 'reportId'], emits: ['close'], template: '<div v-if="show" data-testid="lookup-receipt">{{ reportId }}<button @click="$emit(\'close\')">關閉收據</button></div>' };
const payment = (id, reportId, status = 'confirmed', extra = {}) => ({ id, report_id: reportId, report_status: status, receipt_no: `R-${reportId}`, amount: 500, applied_amount: 500, application_status: 'applied', method: 'cash', paid_at: '2026-11-01', ...extra });
const invoice = (id, payments) => ({ id, invoice_no: `I-${id}`, student_class_id: 42, status: 'paid', total_amount: 1000, calculated_applied_amount: 1000, outstanding_amount: 0, payments });
const data = () => ({ student: { id: 5, name: '合成學生' }, scope: { student_class_id: 42 }, summary: {}, courses: [], anomalies: [], invoices: [invoice(501, [payment(71, 801), payment(72, 802)]), invoice(502, [payment(73, 803, 'pending'), payment(74, 804, 'voided', { is_void: true }), payment(75, null)])], receipts: [] });

async function open({ role = 'director', payload = data(), action = 'documents', realContracts = false } = {}) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic', user: { role } }));
  const fetchMock = vi.fn(async (url) => ({ ok: true, status: 200, json: async () => String(url).startsWith('/api/v1/accounting/ledger') ? payload : { data: [] } }));
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = mount(AccountingLedgerModal, { attachTo: document.body, props: { show: true, studentClassId: 42, branchId: 7, initialAction: action }, global: { stubs: { Transition: false, BillingNotesPanel: true, ReceiptModal: Receipt, ContractCard: !realContracts } } });
  await flushPromises();
  return { wrapper, fetchMock };
}

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('#4148 bill-linked receipt lookup', () => {
  it('shows both equal-amount payments of their own invoice, opening the exact server-linked report without coverage or business writes', async () => {
    const payload = data();
    payload.invoices[0].outstanding_amount = 1000;
    payload.invoices[0].total_amount = 2000;
    const { wrapper: w, fetchMock } = await open({ payload });
    try {
      expect(w.get('[data-testid="ledger-fold-invoices"]').attributes('aria-expanded')).toBe('true');
      const first = w.get('[data-testid="ledger-invoice-payments-501"]');
      expect(first.findAll('[data-testid^="ledger-payment-receipt-"]').map((b) => b.attributes('data-testid'))).toEqual(['ledger-payment-receipt-71', 'ledger-payment-receipt-72']);
      expect(w.get('[data-testid="ledger-invoice-payments-502"]').findAll('[data-testid^="ledger-payment-receipt-"]')).toHaveLength(0);
      await first.get('[data-testid="ledger-payment-receipt-72"]').trigger('click');
      expect(w.get('[data-testid="lookup-receipt"]').text()).toContain('802');
      await w.get('[data-testid="lookup-receipt"] button').trigger('click');
      expect(w.find('[data-testid="lookup-receipt"]').exists()).toBe(false);
      expect(first.isVisible()).toBe(true);
      expect(fetchMock.mock.calls.filter(([u]) => String(u).startsWith('/api/v1/accounting/ledger?'))).toHaveLength(1);
      expect(fetchMock.mock.calls.filter(([u]) => /\/api\/v1\/invoices\//.test(String(u)))).toEqual([]);
      expect(fetchMock.mock.calls.every(([, o]) => !o?.method || o.method === 'GET')).toBe(true);
    } finally { w.unmount(); }
  });

  it('explains pending, voided and unlinked payments instead of fabricating a receipt', async () => {
    const { wrapper: w } = await open();
    try {
      const text = w.get('[data-testid="ledger-invoice-payments-502"]').text();
      expect(text).toContain('待確認入帳，尚無收據');
      expect(text).toContain('已撤銷，不提供有效收據');
      expect(text).toContain('未連結收據');
    } finally { w.unmount(); }
  });

  it('retains the director receipt capability check', async () => {
    const { wrapper: w } = await open({ role: 'teacher' });
    try { expect(w.findAll('[data-testid^="ledger-payment-receipt-"]')).toHaveLength(0); }
    finally { w.unmount(); }
  });

  it('starts with the opened contract and combined invoice, retaining other bills and the ordinary server order', async () => {
    const payload = data();
    payload.invoices.unshift({ ...invoice(503, []), student_class_id: 77 });
    payload.invoices.push({ ...invoice(504, []), student_class_id: 11, covered_student_class_ids: [11, 42] });
    for (const [action, expected] of [['documents', [501, 502, 504, 503]], [null, [503, 501, 502, 504]]]) {
      const { wrapper: w } = await open({ payload, action });
      try {
        expect(w.findAll('[data-testid^="ledger-invoice-"]').filter((r) => /^ledger-invoice-\d+$/.test(r.attributes('data-testid'))).map((r) => Number(r.attributes('data-testid').split('-').at(-1)))).toEqual(expected);
        expect(w.findAll('[data-testid="ledger-other-course"]')).toHaveLength(1);
      } finally { w.unmount(); }
    }
  });

  it('closes the previous receipt when campus changes and a new scope is refused', async () => {
    const { wrapper: w, fetchMock } = await open();
    try {
      await w.get('[data-testid="ledger-payment-receipt-71"]').trigger('click');
      expect(w.get('[data-testid="lookup-receipt"]').text()).toContain('801');
      fetchMock.mockImplementation(async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) }));
      await w.setProps({ branchId: 8 }); await flushPromises();
      expect(w.find('[data-testid="lookup-receipt"]').exists()).toBe(false);
      expect(w.find('[data-testid="ledger-payment-receipt-71"]').exists()).toBe(false);
      const last = fetchMock.mock.calls.filter(([u]) => String(u).startsWith('/api/v1/accounting/ledger?')).at(-1)[0];
      expect(new URL(last, 'https://synthetic.test').searchParams.get('branch_id')).toBe('8');
    } finally { w.unmount(); }
  });

  it('keeps confirmed unbilled legacy receipts in the same document lookup', async () => {
    const payload = data();
    payload.receipts = [{ report_id: 900, invoice_id: null, student_class_id: null, amount: 200, status: 'confirmed', payment_method: 'cash' }];
    const { wrapper: w } = await open({ payload });
    try {
      await w.get('[data-testid="ledger-receipt-view-900"]').trigger('click');
      expect(w.get('[data-testid="lookup-receipt"]').text()).toContain('900');
    } finally { w.unmount(); }
  });
});

describe('contract invoice shortcut', () => {
  function withContract(invoices) {
    return {
      ...data(),
      courses: [{ id: 42, subject: '數學', schedule_mode: 'count', paid: true }],
      invoices,
    };
  }

  async function openContractMenu(wrapper) {
    const trigger = wrapper.get('[data-testid="contract-card-42"] button[aria-haspopup="menu"]');
    await trigger.trigger('keydown', { key: 'ArrowDown' });
    await flushPromises();
    return document.querySelector('[role="menu"] [data-action="invoice-documents"]');
  }

  it('reopens the invoice fold and focuses the uniquely linked combined bill without opening a receipt or writing data', async () => {
    const related = { ...invoice(501, [payment(71, 801), payment(72, 802)]), student_class_id: 11, covered_student_class_ids: [11, 42] };
    const unrelated = { ...invoice(502, [payment(73, 803)]), student_class_id: 77 };
    const { wrapper: w, fetchMock } = await open({ payload: withContract([unrelated, related]), action: null, realContracts: true });
    try {
      await w.get('[data-testid="ledger-fold-invoices"]').trigger('click');
      const item = await openContractMenu(w);
      expect(item).not.toBeNull();
      expect(item?.textContent).toContain('查看帳單與收據');
      item.click();
      await flushPromises();
      const rowToggle = w.get('[data-testid="ledger-invoice-toggle-501"]');
      expect(rowToggle.attributes('aria-expanded')).toBe('true');
      expect(document.activeElement).toBe(rowToggle.element);
      expect(w.get('[data-testid="ledger-invoice-toggle-502"]').attributes('aria-expanded')).toBe('false');
      expect(w.find('[data-testid="lookup-receipt"]').exists()).toBe(false);
      await w.get('[data-testid="ledger-payment-receipt-72"]').trigger('click');
      expect(w.get('[data-testid="lookup-receipt"]').text()).toContain('802');
      expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
    } finally { w.unmount(); }
  });

  it.each([
    ['director', [], 'no bill'],
    ['director', [invoice(501, []), invoice(502, [])], 'more than one bill'],
    ['teacher', [invoice(501, [])], 'without document permission'],
  ])('keeps the general documents entry whenever direct lookup is unavailable (%s, %s, %s)', async (role, invoices) => {
    const { wrapper: w } = await open({ role, payload: withContract(invoices), action: null, realContracts: true });
    try {
      expect(await openContractMenu(w)).toBeNull();
      expect(w.get('[data-testid="ledger-fold-invoices"]').exists()).toBe(true);
    } finally { w.unmount(); }
  });
});
