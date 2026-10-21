// In-app #398: 「登記繳費回報」 from 課程查找 opened the student ledger and the director still had to find the card
// and click 「登記這筆」. With initialAction='record' the entry form opens for that course once the ledger has loaded;
// a course whose card would not offer 登記這筆 just shows the ledger (no new eligibility rule).
// In-app #425: the ledger also offers 查看收據 on confirmed receipt rows and 繳費單 on contract cards.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const course = (id, extra = {}) => ({ id, subject: `科目${id}`, schedule_mode: 'count', charge: 4400, paid: false, stop: false, package_id: null, ...extra });
const inv = (id, scId) => ({ id, student_class_id: scId, status: 'unpaid', outstanding_amount: 4400, total_amount: 4400, due_date: '2026-10-01', payments: [] });

const EntryStub = { name: 'PaymentEntryModal', props: ['show', 'row', 'mode'], template: '<div v-if="show" data-testid="entry-stub">{{ row && row.id }}</div>' };
const ReceiptStub = { name: 'ReceiptModal', props: ['show', 'reportId', 'laterLabel'], template: '<div v-if="show" data-testid="receipt-stub">{{ reportId }}|{{ laterLabel }}</div>' };
const SlipStub = { name: 'PaymentSlipModal', props: ['show', 'invoiceId', 'studentClassId'], template: '<div v-if="show" data-testid="slip-stub">{{ invoiceId }}|{{ studentClassId }}</div>' };

function mountLedger({ courses, invoices = [], receipts = [], props = {}, role = 'director' }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role } }));
  const payload = { summary: {}, scope: { student_class_id: props.studentClassId ?? 11 }, anomalies: [], courses, invoices, receipts };
  const fetchMock = vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : { ok: false, status: 500, json: async () => ({}) }));
  vi.stubGlobal('fetch', fetchMock);
  return mount(AccountingLedgerModal, {
    attachTo: document.body,
    props: { show: true, studentClassId: 11, branchId: 1, ...props },
    global: { stubs: { Transition: false, PaymentEntryModal: EntryStub, ReceiptModal: ReceiptStub, PaymentSlipModal: SlipStub, ContractCard: false } },
  });
}

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('學生帳務 · 從課程查找直接登記 (in-app #398)', () => {
  it('a legacy paid flag keeps its explicit backfill choice instead of opening the ordinary record form', async () => {
    const w = mountLedger({ courses: [course(11, { paid: true })], invoices: [inv(1, 11)], props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('[data-testid="ledger-initial-action-note"]').text()).toContain('舊資料標記已收');
    await w.get('[data-testid="legacy-backfill-open"]').trigger('click');
    expect(w.getComponent(EntryStub).props('mode')).toBe('legacy-backfill');
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    w.unmount();
  });

  it("initialAction='record' opens the entry form for the opened course once, after the ledger loads", async () => {
    const w = mountLedger({ courses: [course(11), course(12)], invoices: [inv(1, 11)], props: { initialAction: 'record' } });
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false); // not before load
    await flushPromises();
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    expect(w.findComponent(EntryStub).props('row')).toMatchObject({ id: 11, invoice_id: 1, payable_status: 'invoiced' });
    // Cancelling returns to the ledger; a reload of the same open does not reopen the form.
    w.findComponent(EntryStub).vm.$emit('close');
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-card-11"]').exists()).toBe(true);
    await w.setProps({ branchId: 2 }); // same open, ledger reloads
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    // A new open re-arms it.
    await w.setProps({ show: false });
    await w.setProps({ show: true });
    await flushPromises();
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    w.unmount();
  });

  it.each([
    ['pending report', { courses: [course(11)], invoices: [inv(1, 11)], receipts: [{ report_id: 5, student_class_id: 11, status: 'pending', amount: 4400, payment_date: '2026-10-02' }] }],
    ['waived', { courses: [course(11, { waived: true })], invoices: [] }],
    ['tutoring', { courses: [course(11, { class_type: 'tutoring' })], invoices: [inv(1, 11)] }],
    ['multi-bill package member', { courses: [course(11, { package_id: 3 }), course(12, { package_id: 3 })], invoices: [inv(1, 11), inv(2, 12)] }],
  ])('%s: opens the ledger only, no form, and says why', async (_label, data) => {
    const w = mountLedger({ ...data, props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('[data-testid="ledger-initial-action-note"]').attributes('role')).toBe('status');
    w.unmount();
  });

  it('without initialAction the ledger opens as before', async () => {
    const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)] });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-initial-action-note"]').exists()).toBe(false);
    w.unmount();
  });
});

describe('學生帳務 · 繳費單與收據 (in-app #425)', () => {
  const receipts = [
    { report_id: 7, student_class_id: 11, status: 'confirmed', amount: 4400, payment_date: '2026-09-02' },
    { report_id: 8, student_class_id: 11, status: 'pending', amount: 4400, payment_date: '2026-10-02' },
    { report_id: 9, student_class_id: 11, status: 'void', amount: 4400, payment_date: '2026-08-02' },
  ];

  it('confirmed receipt rows open that receipt; pending and void rows have no button', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [inv(1, 12)], receipts });
    await flushPromises();
    await w.get('[data-testid="ledger-other-receipts-toggle"]').trigger('click');
    expect(w.findAll('[data-testid^="ledger-receipt-view-"]').map((b) => b.attributes('data-testid'))).toEqual(['ledger-receipt-view-7']);
    await w.get('[data-testid="ledger-receipt-view-7"]').trigger('click');
    expect(w.get('[data-testid="receipt-stub"]').text()).toBe('7|'); // viewing, not the 稍後再開 step after 確認入帳
    w.unmount();
  });

  it('non-director sees no receipt button and no 繳費單', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [inv(1, 12)], receipts, role: 'teacher' });
    await flushPromises();
    expect(w.find('[data-testid^="ledger-receipt-view-"]').exists()).toBe(false);
    expect(w.find('[data-testid="contract-slip"]').exists()).toBe(false);
    w.unmount();
  });

  it('繳費單 on a card opens the slip for its oldest open bill, or by course when it has none', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [{ ...inv(2, 12), due_date: '2026-11-01' }, inv(1, 12)] });
    await flushPromises();
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.get('[data-testid="slip-stub"]').text()).toBe('1|');
    w.unmount();
  });

  it('繳費單 on a contract with no bill yet opens the estimated slip by course', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [] });
    await flushPromises();
    await w.get('[data-testid="contract-slip"]').trigger('click');
    expect(w.get('[data-testid="slip-stub"]').text()).toBe('|12');
    w.unmount();
  });

  it('a 金額待確認 contract keeps 繳費單 disabled with the list reason', async () => {
    const w = mountLedger({ courses: [course(12, { stop: true, amount_pending_confirmation: true })], invoices: [inv(1, 12)] });
    await flushPromises();
    expect(w.get('[data-testid="contract-slip"]').element.disabled).toBe(true);
    expect(w.get('[data-testid="contract-slip-reason"]').text()).toBe('金額待確認，請先確認應收金額');
    w.unmount();
  });
});
