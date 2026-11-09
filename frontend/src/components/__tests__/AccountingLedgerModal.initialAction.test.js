// In-app #398: 「登記收款」 from 課程查找 opened the student ledger and the director still had to find the card
// and click 「登記這筆」. With initialAction='record' the entry form opens for that course once the ledger has loaded;
// a course whose card would not offer 登記這筆 just shows the ledger (no new eligibility rule).
// In-app #425: the ledger also offers 查看收據 on confirmed receipt rows and 繳費單 on contract cards.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import { menuIds, expandAll, openCardMenu, menuItem, pickMenu, unfold } from './cardMenu.js';

const course = (id, extra = {}) => ({ id, subject: `科目${id}`, schedule_mode: 'count', charge: 4400, paid: false, stop: false, package_id: null, ...extra });
const inv = (id, scId) => ({ id, student_class_id: scId, status: 'unpaid', outstanding_amount: 4400, total_amount: 4400, due_date: '2026-10-01', payments: [] });

const EntryStub = { name: 'PaymentEntryModal', props: ['show', 'row', 'mode', 'placement'], template: '<div v-if="show" data-testid="entry-stub">{{ row && row.id }}</div>' };
const ReceiptStub = { name: 'ReceiptModal', props: ['show', 'reportId', 'laterLabel'], template: '<div v-if="show" data-testid="receipt-stub">{{ reportId }}|{{ laterLabel }}</div>' };
const SlipStub = { name: 'PaymentSlipModal', props: ['show', 'invoiceId', 'studentClassId'], template: '<div v-if="show" data-testid="slip-stub">{{ invoiceId }}|{{ studentClassId }}</div>' };

function mountLedger({ courses, invoices = [], receipts = [], props = {}, role = 'director', drafts = [] }) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role } }));
  const payload = { summary: {}, scope: { student_class_id: props.studentClassId ?? 11 }, anomalies: [], courses, invoices, receipts };
  const fetchMock = vi.fn(async (url) => (String(url).startsWith('/api/v1/accounting/ledger')
    ? { ok: true, status: 200, json: async () => payload }
    : String(url).startsWith('/api/v1/accounting/monthly-drafts') ? { ok: true, status: 200, json: async () => ({ data: drafts }) }
    : { ok: false, status: 500, json: async () => ({}) }));
  vi.stubGlobal('fetch', fetchMock);
  return mount(AccountingLedgerModal, {
    attachTo: document.body,
    props: { show: true, studentClassId: 11, branchId: 1, ...props },
    global: { stubs: { Transition: false, PaymentEntryModal: EntryStub, ReceiptModal: ReceiptStub, PaymentSlipModal: SlipStub, ContractCard: false } },
  });
}

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); document.body.innerHTML = ''; });

describe('學生帳務 · 右側收款面板 (sheetEntry, #4659 step 3)', () => {
  it('shows only the entry sheet (ledger hidden), and saving closes it and refreshes the list', async () => {
    const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)], props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    expect(w.getComponent(EntryStub).props('placement')).toBe('right');
    expect(w.get('.ledger-overlay').isVisible()).toBe(false);
    w.getComponent(EntryStub).vm.$emit('confirmed', { report_id: 5, status: 'pending' });
    await flushPromises();
    expect(w.emitted('changed')).toHaveLength(1);
    expect(w.emitted('close')).toHaveLength(1);
    w.unmount();
  });

  it('passes the bill\'s cycle range to the entry form (in-app #514)', async () => {
    const bill = { ...inv(1, 11), billing_period: '2026-05', period_start: '2026-04-29', period_end: '2026-05-27' };
    const w = mountLedger({ courses: [course(11)], invoices: [bill], props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    expect(w.getComponent(EntryStub).props('row')).toMatchObject({ billing_period: '2026-05', billing_period_start: '2026-04-29', billing_period_end: '2026-05-27' });
    w.unmount();
  });

  it('cancelling the sheet closes everything', async () => {
    const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)], props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    w.getComponent(EntryStub).vm.$emit('close');
    expect(w.emitted('close')).toHaveLength(1);
    w.unmount();
  });

  it('another row\'s 登記收款 while the sheet is open re-arms it for that course (no dead sheet)', async () => {
    const w = mountLedger({ courses: [course(11), course(12)], invoices: [inv(1, 11), inv(2, 12)], props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    await w.setProps({ studentClassId: 12 });
    await flushPromises();
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('12');
    expect(w.get('.ledger-overlay').isVisible()).toBe(false);
    w.unmount();
  });

  it('a 補登收款 blocked by a confirmed report after a conflict shows the visible ledger with the reason', async () => {
    const receipts = [];
    const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)], receipts, props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    w.vm.$.setupState.openEntry(course(11), 'legacy-backfill');
    await flushPromises();
    receipts.push({ report_id: 5, student_class_id: 11, status: 'confirmed', amount: 4400 });
    w.getComponent(EntryStub).vm.$emit('conflict');
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('.ledger-overlay').isVisible()).toBe(true);
    expect(w.get('[data-testid="ledger-initial-action-note"]').exists()).toBe(true);
    w.unmount();
  });

  it('shows a loading sheet while the ledger loads, and the ledger itself if it never answers', async () => {
    vi.useFakeTimers();
    try {
      const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)], props: { initialAction: 'record', sheetEntry: true } });
      vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
      await w.setProps({ studentClassId: 12 });
      expect(document.body.querySelector('[data-testid="ledger-sheet-loading"]')).not.toBeNull();
      await vi.advanceTimersByTimeAsync(10_100);
      expect(w.get('.ledger-overlay').isVisible()).toBe(true);
      expect(document.body.querySelector('[data-testid="ledger-sheet-loading"]')).toBeNull();
      w.unmount();
    } finally { vi.useRealTimers(); }
  });

  it('a blocked course falls back to the visible ledger with the reason', async () => {
    const w = mountLedger({ courses: [course(11, { class_type: 'tutoring' })], invoices: [inv(1, 11)], props: { initialAction: 'record', sheetEntry: true } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('.ledger-overlay').isVisible()).toBe(true);
    expect(w.get('[data-testid="ledger-initial-action-note"]').text()).toContain('輔導課不用繳費');
    w.unmount();
  });
});

describe('學生帳務 · 從課程查找直接登記 (in-app #398)', () => {
  it('a tutoring course with a stale paid flag still explains that no payment is required', async () => {
    const w = mountLedger({ courses: [course(11, { class_type: 'tutoring', paid: true })], invoices: [inv(1, 11)], props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('[data-testid="ledger-initial-action-note"]').text()).toContain('輔導課不用繳費');
    w.unmount();
  });

  it('a legacy paid flag keeps its explicit backfill choice instead of opening the ordinary record form', async () => {
    const w = mountLedger({ courses: [course(11, { paid: true })], invoices: [inv(1, 11)], props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
    expect(w.get('[data-testid="ledger-initial-action-note"]').text()).toContain('舊資料標記已收');
    expect(w.find('[data-testid="ledger-sticky-pay"]').exists()).toBe(false);
    await pickMenu(w, 'legacy-backfill');
    expect(w.getComponent(EntryStub).props('mode')).toBe('legacy-backfill');
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    w.unmount();
  });

  it("initialAction='legacy-backfill' opens the back-fill form straight away for a legacy-paid course (in-app #490)", async () => {
    const w = mountLedger({ courses: [course(11, { paid: true })], invoices: [inv(1, 11)], props: { initialAction: 'legacy-backfill' } });
    await flushPromises();
    expect(w.getComponent(EntryStub).props('mode')).toBe('legacy-backfill');
    expect(w.get('[data-testid="entry-stub"]').text()).toBe('11');
    w.unmount();
  });

  it("initialAction='legacy-backfill' on an ordinary course just shows the ledger", async () => {
    const w = mountLedger({ courses: [course(11)], invoices: [inv(1, 11)], props: { initialAction: 'legacy-backfill' } });
    await flushPromises();
    expect(w.find('[data-testid="entry-stub"]').exists()).toBe(false);
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

  it("initialAction='issue' opens the 開立這期帳單 question straight away when a draft is ready", async () => {
    const w = mountLedger({ courses: [course(11)], props: { initialAction: 'issue' }, drafts: [{ student_class_id: 11, status: 'ready', subject: '科目11', proposed_start_date: '2026-11-01', proposed_end_date: '2999-11-30', period_sessions: 4, amount: 4400 }] });
    await flushPromises();
    expect(document.body.querySelector('[data-testid="ledger-issue-text"]')).not.toBeNull();
    w.unmount();
  });

  it("initialAction='issue' does nothing when the drawer moved on while drafts loaded", async () => {
    const w = mountLedger({ courses: [course(11)], props: { initialAction: 'issue' }, drafts: [{ student_class_id: 11, status: 'ready', subject: '科目11', proposed_start_date: '2026-11-01', proposed_end_date: '2999-11-30', period_sessions: 4, amount: 4400 }] });
    await w.setProps({ show: false });
    await flushPromises();
    expect(document.body.querySelector('[data-testid="ledger-issue-text"]')).toBeNull();
    w.unmount();
  });

  it("initialAction='issue' without a ready draft says so instead of opening nothing", async () => {
    const w = mountLedger({ courses: [course(11)], props: { initialAction: 'issue' } });
    await flushPromises();
    expect(document.body.querySelector('[data-testid="ledger-issue-text"]')).toBeNull();
    expect(w.get('[data-testid="ledger-initial-action-note"]').text()).toContain('沒有可以開立');
    w.unmount();
  });

  it('F-5a1: a card action is passed up with the contract id, and only where the page allowed it', async () => {
    const w = mountLedger({ courses: [course(11), course(12)], invoices: [inv(1, 11)], props: { rowActions: { 11: { waive: true } } } });
    await flushPromises();
    expect(await menuIds(w.find('[data-testid="contract-card-12"]'))).not.toContain('waive');
    await expandAll(w);
    await openCardMenu(w);
    expect(menuItem('waive')).not.toBeNull();
    menuItem('waive').click();
    await flushPromises();
    expect(w.emitted('row-action')).toEqual([[{ kind: 'waive', courseId: 11 }]]);
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
    await unfold(w, 'receipts');
    await w.get('[data-testid="ledger-other-receipts-toggle"]').trigger('click');
    expect(w.findAll('[data-testid^="ledger-receipt-view-"]').map((b) => b.attributes('data-testid'))).toEqual(['ledger-receipt-view-7']);
    await w.get('[data-testid="ledger-receipt-view-7"]').trigger('click');
    expect(w.get('[data-testid="receipt-stub"]').text()).toBe('7|'); // viewing, not the 稍後再開 step after 確認入帳
    w.unmount();
  });

  // in-app #521: money of the student's other courses must not read as money for the unbilled course.
  it('labels each receipt with its course and lists other courses\' receipts last under their own heading', async () => {
    const mixed = [
      { report_id: 20, student_class_id: 12, status: 'confirmed', amount: 3000, payment_date: '2026-09-02' },
      { report_id: 21, student_class_id: 11, status: 'confirmed', amount: 4400, payment_date: '2026-10-02' },
    ];
    const w = mountLedger({ courses: [course(11, { start_date: '2026-09-01' }), course(12)], invoices: [inv(1, 11)], receipts: mixed, props: { studentClassId: 11 } });
    await flushPromises();
    await unfold(w, 'receipts');
    const order = w.findAll('[data-testid^="ledger-receipt-course-"]').map((e) => e.attributes('data-testid'));
    expect(order).toEqual(['ledger-receipt-course-21', 'ledger-receipt-course-20']);
    expect(w.get('[data-testid="ledger-receipt-course-21"]').text()).toBe('科目11（2026-09-01 開始）');
    expect(w.get('[data-testid="ledger-receipt-course-20"]').text()).toBe('科目12');
    expect(w.findAll('[data-testid="ledger-foreign-receipts"]')).toHaveLength(1);
    w.unmount();
  });

  // PR #5107: an invoice/package can pay the opened course through another member.
  it.each([
    ['shared package', [course(11, { package_id: 3 }), course(12, { package_id: 3 })], [], { student_class_id: 12 }],
    ['combined invoice', [course(11), course(12)], [{ ...inv(1, 12), covered_student_class_ids: [11, 12] }], { student_class_id: 12, invoice_id: 1 }],
    ['combined invoice via its payment', [course(11), course(12)], [{ ...inv(1, 12), covered_student_class_ids: [11, 12], payments: [{ id: 8, report_id: 21 }] }], { student_class_id: 12, invoice_id: null }],
  ])('keeps a %s receipt with the opened obligation', async (_, courses, invoices, receipt) => {
    const mixed = [
      { report_id: 20, student_class_id: 13, status: 'confirmed', amount: 3000 },
      { report_id: 21, status: 'confirmed', amount: 4400, ...receipt },
    ];
    const w = mountLedger({ courses: [...courses, course(13, { package_id: 4 })], invoices, receipts: mixed });
    await flushPromises();
    await unfold(w, 'receipts');
    const section = w.get('#ledger-sec-receipts');
    expect(section.findAll('[data-testid^="ledger-receipt-course-"]').map((e) => e.attributes('data-testid')))
      .toEqual(['ledger-receipt-course-21', 'ledger-receipt-course-20']);
    const headings = section.findAll('[data-testid="ledger-foreign-receipts"]');
    expect(headings).toHaveLength(1);
    expect(headings[0].element.nextElementSibling.querySelector('[data-testid="ledger-receipt-course-20"]')).not.toBeNull();
    expect(global.fetch.mock.calls.filter(([, init]) => (init?.method || 'GET') !== 'GET')).toEqual([]);
    w.unmount();
  });

  it('does not call another student-wide receipt foreign', async () => {
    const w = mountLedger({ courses: [course(11), course(12)], receipts: [
      { report_id: 20, student_class_id: 12, status: 'confirmed', amount: 3000 },
    ], props: { studentWide: true } });
    await flushPromises();
    await unfold(w, 'receipts');
    expect(w.find('[data-testid="ledger-foreign-receipts"]').exists()).toBe(false);
    w.unmount();
  });

  // Moved from the old queue-table doc-entry test (#3894): opening a slip or a receipt only ever reads.
  it('opening 繳費單 and 查看收據 sends no write request', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [inv(1, 12)], receipts });
    await flushPromises();
    await pickMenu(w, 'slip');
    await unfold(w, 'receipts');
    await w.get('[data-testid="ledger-other-receipts-toggle"]').trigger('click');
    await w.get('[data-testid="ledger-receipt-view-7"]').trigger('click');
    const writes = global.fetch.mock.calls.filter(([, init]) => (init?.method || 'GET').toUpperCase() !== 'GET');
    expect(writes).toEqual([]);
    w.unmount();
  });

  it('non-director sees no receipt button and no 繳費單', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [inv(1, 12)], receipts, role: 'teacher' });
    await flushPromises();
    expect(w.find('[data-testid^="ledger-receipt-view-"]').exists()).toBe(false);
    await openCardMenu(w);
    expect(menuItem('slip')).toBeNull();
    w.unmount();
  });

  it('繳費單 on a card opens the slip for its oldest open bill, or by course when it has none', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [{ ...inv(2, 12), due_date: '2026-11-01' }, inv(1, 12)] });
    await flushPromises();
    await pickMenu(w, 'slip');
    expect(w.get('[data-testid="slip-stub"]').text()).toBe('1|');
    w.unmount();
  });

  it('繳費單 on a contract with no bill yet opens the estimated slip by course', async () => {
    const w = mountLedger({ courses: [course(12)], invoices: [] });
    await flushPromises();
    await pickMenu(w, 'slip');
    expect(w.get('[data-testid="slip-stub"]').text()).toBe('|12');
    w.unmount();
  });

  it('a 金額待確認 contract keeps 繳費單 disabled with the list reason', async () => {
    const w = mountLedger({ courses: [course(12, { stop: true, amount_pending_confirmation: true })], invoices: [inv(1, 12)] });
    await flushPromises();
    await openCardMenu(w);
    expect(menuItem('slip').getAttribute('aria-disabled')).toBe('true');
    expect(w.get('[data-testid="contract-slip-reason"]').text()).toBe('金額需核對，不能產生繳費單');
    w.unmount();
  });
});

// in-app #432 (Founder 2026-10-28, option A): a monthly contract with no open bill collects the month the server names
// (courses[].collection_period, the same answer directorRecord checks), so the form shows that month and its amount.
describe('學生帳務 · 月結依月份收款 (in-app #432)', () => {
  it('the entry form for an unbilled monthly contract carries the server month and amount', async () => {
    const monthly = course(11, { schedule_mode: 'date', charge: 8250, collection_period: { billing_period: '2026-08', amount: 1650 } });
    const w = mountLedger({ courses: [monthly], props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.findComponent(EntryStub).props('row')).toMatchObject({ id: 11, payable_status: 'unbilled', billing_period: '2026-08', estimated_amount: 1650 });
    w.unmount();
  });

  it('an open bill still wins: its own period, no estimate', async () => {
    const monthly = course(11, { schedule_mode: 'date', collection_period: { billing_period: '2026-08', amount: 1650 } });
    const w = mountLedger({ courses: [monthly], invoices: [{ ...inv(1, 11), billing_period: '2026-09' }], props: { initialAction: 'record' } });
    await flushPromises();
    expect(w.findComponent(EntryStub).props('row')).toMatchObject({ invoice_id: 1, billing_period: '2026-09', payable_status: 'invoiced' });
    expect(w.findComponent(EntryStub).props('row').estimated_amount).toBeUndefined();
    w.unmount();
  });
});
