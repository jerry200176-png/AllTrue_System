// Billing UX F-2 (Founder 2026-10-30, F2 = A): one main button per list row, the drawer opens on that step, and after
// 登記 the director may press 現在確認入帳. Register and confirm stay two buttons and two requests; nothing merges them.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import StudentBillingList from '../tuition/StudentBillingList.vue';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import PaymentAllocation from '../tuition/PaymentAllocation.vue';
import { buildStudentBillingRows, rowPrimaryAction } from '../../lib/studentBillingRows.js';

const ALERTS = [
  { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' },
  { id: 21, student_id: 2, student_name: '乙', payment_status: 'pending_report', payable_outstanding: 1800, due_date: null },
  { id: 31, student_id: 3, student_name: '丙', payment_status: 'unpaid', payable_outstanding: 500, due_date: '2026-12-01' },
];

describe('list row main button', () => {
  it('names the action after the result and points at the contract it is about', () => {
    const rows = buildStudentBillingRows(ALERTS, [{ id: 4, name: '丁' }], '2026-10-07');
    const by = (id) => rows.find((r) => r.student_id === id);
    expect(rowPrimaryAction(by(1))).toEqual({ kind: 'record', label: '登記收款', classId: 11 });
    expect(rowPrimaryAction(by(2))).toEqual({ kind: 'confirm', label: '確認入帳', classId: 21 });
    expect(rowPrimaryAction(by(3))).toBeNull(); // not due yet: nothing to collect now
    expect(rowPrimaryAction(by(4))).toBeNull();
  });

  it('emits action with the row and its action; the row itself still just opens', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ALERTS, today: '2026-10-07' } });
    expect(w.find('[data-testid="sbl-action-3"]').exists()).toBe(false);
    await w.get('[data-testid="sbl-action-2"]').trigger('click');
    expect(w.emitted('action')[0][1]).toMatchObject({ kind: 'confirm', classId: 21 });
    expect(w.emitted('open')).toBeUndefined();
    await w.get('[data-testid="sbl-row-1"]').trigger('click');
    expect(w.emitted('open')).toHaveLength(1);
  });
});

const course = (id) => ({ id, subject: `科目${id}`, schedule_mode: 'count', charge: 4400, paid: false, stop: false, package_id: null });
const EntryStub = { name: 'PaymentEntryModal', props: ['show', 'row', 'mode'], template: '<div v-if="show" data-testid="entry-stub" />' };
const ReceiptStub = { name: 'ReceiptModal', props: ['show', 'reportId'], template: '<div v-if="show" data-testid="receipt-stub">{{ reportId }}</div>' };

function mountLedger({ receipts = [], props = {} }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
  const payload = { summary: {}, scope: { student_class_id: 11 }, anomalies: [], courses: [course(11)], receipts,
    invoices: [{ id: 1, student_class_id: 11, status: 'unpaid', outstanding_amount: 4400, total_amount: 4400, due_date: '2026-10-01', payments: [] }] };
  const fetchMock = vi.fn(async (url, opts) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: true, status: 200, json: async () => ({ method: opts?.method }) }));
  vi.stubGlobal('fetch', fetchMock);
  const w = mount(AccountingLedgerModal, {
    attachTo: document.body,
    props: { show: true, studentClassId: 11, branchId: 1, ...props },
    global: { stubs: { Transition: false, PaymentEntryModal: EntryStub, ReceiptModal: ReceiptStub } },
  });
  return { w, fetchMock };
}
const confirmCalls = (fetchMock) => fetchMock.mock.calls.filter(([u]) => String(u).includes('/confirm'));
const body = (id) => document.body.querySelector(`[data-testid="${id}"]`);

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('drawer opens on the step', () => {
  const pending = [{ report_id: 8, student_class_id: 11, status: 'pending', amount: 4400, payment_date: '2026-10-02' }];

  it("initialAction='confirm' asks the card's own confirm question, and sends nothing until the director agrees", async () => {
    const { w, fetchMock } = mountLedger({ receipts: pending, props: { initialAction: 'confirm' } });
    await flushPromises();
    expect(body('contract-confirm-text').textContent).toContain('NT$ 4,400');
    expect(confirmCalls(fetchMock)).toHaveLength(0);
    w.unmount();
  });

  it("initialAction='confirm' on a contract with no pending report just shows the ledger", async () => {
    const { w } = mountLedger({ props: { initialAction: 'confirm' } });
    await flushPromises();
    expect(body('contract-confirm-text')).toBeNull();
    w.unmount();
  });
});

describe('shared package', () => {
  it("initialAction='confirm' on the anchor opens the card that actually holds the pending report", async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const payload = { summary: {}, scope: { student_class_id: 11 }, anomalies: [], invoices: [],
      courses: [{ ...course(11), package_id: 3 }, { ...course(12), package_id: 3 }],
      receipts: [{ report_id: 8, student_class_id: 12, status: 'pending', amount: 4400, payment_date: '2026-10-02' }] };
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => payload })));
    const w = mount(AccountingLedgerModal, { attachTo: document.body, props: { show: true, studentClassId: 11, branchId: 1, initialAction: 'confirm' }, global: { stubs: { Transition: false, PaymentEntryModal: EntryStub, ReceiptModal: ReceiptStub } } });
    await flushPromises();
    expect(body('contract-confirm-text').textContent).toContain('NT$ 4,400');
    w.unmount();
  });
});

describe('現在確認入帳 after 登記', () => {
  const recorded = [{ report_id: 8, student_class_id: 11, status: 'pending', amount: 4400, payment_date: '2026-10-02' }];

  it('shows the button after a pending report; confirming is its own request, then the receipt opens', async () => {
    const { w, fetchMock } = mountLedger({ receipts: recorded, props: { initialAction: 'record' } });
    await flushPromises();
    // The card already shows the pending report, so open the entry through the stub's own emit.
    w.findComponent(EntryStub).vm.$emit('confirmed', { report_id: 8, status: 'pending' });
    await flushPromises();
    expect(body('ledger-recorded-text').textContent).toContain('NT$ 4,400');
    expect(confirmCalls(fetchMock)).toHaveLength(0);
    body('ledger-recorded-confirm').click();
    await flushPromises();
    const calls = confirmCalls(fetchMock);
    expect(calls).toHaveLength(1);
    expect(calls[0][0]).toContain('/payment-reports/8/confirm');
    expect(body('ledger-recorded-text')).toBeNull();
    expect(w.get('[data-testid="receipt-stub"]').text()).toBe('8');
    w.unmount();
  });

  it('稍後再確認 closes without any confirm request', async () => {
    const { w, fetchMock } = mountLedger({ receipts: recorded });
    await flushPromises();
    const entry = () => w.findComponent(EntryStub);
    entry().vm.$emit('confirmed', { report_id: 8, status: 'pending' });
    await flushPromises();
    body('ledger-recorded-later').click();
    await flushPromises();
    expect(body('ledger-recorded-text')).toBeNull();
    expect(confirmCalls(fetchMock)).toHaveLength(0);
    w.unmount();
  });

  it('offers nothing when the drawer moved to another student while the ledger reloaded', async () => {
    const { w } = mountLedger({ receipts: recorded });
    await flushPromises();
    w.findComponent(EntryStub).vm.$emit('confirmed', { report_id: 8, status: 'pending' });
    await w.setProps({ studentClassId: 99 }); // stepped on before the reload resolved
    await flushPromises();
    expect(body('ledger-recorded-text')).toBeNull();
    w.unmount();
  });

  it('a failed confirm that returns after the prompt was dismissed does not bring it back', async () => {
    const { w, fetchMock } = mountLedger({ receipts: recorded });
    await flushPromises();
    w.findComponent(EntryStub).vm.$emit('confirmed', { report_id: 8, status: 'pending' });
    await flushPromises();
    let fail;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { fail = () => r({ ok: false, status: 500, json: async () => ({}) }); }));
    body('ledger-recorded-confirm').click();
    await flushPromises();
    await w.setProps({ show: false }); // dismissed / moved on while the request is in flight
    fail();
    await flushPromises();
    expect(body('ledger-recorded-text')).toBeNull();
    w.unmount();
  });

  it('does not open the receipt over another student when the drawer moved during the reload after confirming', async () => {
    const { w, fetchMock } = mountLedger({ receipts: recorded });
    await flushPromises();
    w.findComponent(EntryStub).vm.$emit('confirmed', { report_id: 8, status: 'pending' });
    await flushPromises();
    const ledgerFetch = fetchMock.getMockImplementation();
    const releases = [];
    fetchMock.mockImplementation(async (url, opts) => {
      if (String(url).startsWith('/api/v1/accounting/ledger')) await new Promise((r) => { releases.push(r); });
      return ledgerFetch(url, opts);
    });
    body('ledger-recorded-confirm').click();
    await flushPromises();
    await w.setProps({ studentClassId: 99 });
    releases.forEach((r) => r());
    await flushPromises();
    expect(w.find('[data-testid="receipt-stub"]').exists()).toBe(false);
    w.unmount();
  });

  it('offers nothing when the response is already confirmed (補登) or has no report', async () => {
    const { w } = mountLedger({ receipts: recorded });
    await flushPromises();
    w.findComponent(EntryStub).vm.$emit('confirmed', { report_id: 8, status: 'confirmed' });
    await flushPromises();
    expect(body('ledger-recorded-text')).toBeNull();
    w.findComponent(EntryStub).vm.$emit('confirmed', { status: 'pending' });
    await flushPromises();
    expect(body('ledger-recorded-text')).toBeNull();
    w.unmount();
  });
});

describe('現在確認入帳 in the allocation result', () => {
  const respond = (b, status = 200) => ({ ok: status < 400, status, json: async () => b });
  it('confirms one contract with its own request after the batch reported it', async () => {
    const f = vi.fn(async (url) => (String(url).includes('director-record-batch')
      ? respond({ results: [{ student_class_id: 2, http_status: 200, report_id: 55 }] })
      : respond({})));
    vi.stubGlobal('fetch', f);
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const w = mount(PaymentAllocation, { props: { studentName: '王小明', contracts: [{ id: 2, subject: '數學', start_date: '2026-09-01', end_date: '2026-12-31', owed: 8000, invoice_id: 11 }] } });
    await w.get('[data-testid="alloc-submit"]').trigger('submit');
    await flushPromises();
    expect(f.mock.calls.some(([u]) => String(u).includes('/confirm'))).toBe(false);
    let release;
    f.mockImplementationOnce(() => new Promise((r) => { release = () => r(respond({})); }));
    await w.get('[data-testid="alloc-confirm-2"]').trigger('click');
    expect(w.get('[data-testid="alloc-done"]').attributes('disabled')).toBeDefined(); // no 完成 while confirming
    release();
    await flushPromises();
    expect(w.get('[data-testid="alloc-done"]').attributes('disabled')).toBeUndefined();
    expect(f.mock.calls.filter(([u]) => String(u).includes('/payment-reports/55/confirm'))).toHaveLength(1);
    expect(w.get('[data-testid="alloc-result-2"]').text()).toContain('已確認入帳');
    expect(w.find('[data-testid="alloc-confirm-2"]').exists()).toBe(false);
    w.unmount();
  });
});
