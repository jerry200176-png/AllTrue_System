// Billing redesign PRD v2 (D11b/D13/D15/D16/D17): one card per contract with the
// full memo (editable), every lesson grouped by month with its payment state.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

import ContractCard from '../tuition/ContractCard.vue';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const COVERAGE = {
  student_class_id: 9, subject: '數學', schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31',
  memo: '家長要求週二上課\n第二行',
  unscheduled_count: 2,
  sessions: [
    { class_session_id: 1, date: '2026-09-01', start_time: '18:00', status: 'attended', payment: 'paid' },
    { class_session_id: 2, date: '2026-09-08', start_time: '18:00', status: 'leave', payment: 'paid' },
    { class_session_id: 3, date: '2026-10-06', start_time: '18:00', status: 'scheduled', payment: 'unpaid' },
  ],
};

beforeEach(() => authedFetch.mockReset());

describe('ContractCard', () => {
  it('shows title, full memo, month groups, payment state and unscheduled count', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9, subject: '數學' } } });
    await flushPromises();

    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/accounting/contracts/9/sessions');
    expect(w.find('h5').text()).toBe('數學・2026/09/01–2026/12/31');
    expect(w.find('[data-testid="contract-memo"]').text()).toContain('第二行');
    expect(w.findAll('h6').map((h) => h.text())).toEqual(['2026 年 9 月', '2026 年 10 月']);
    const rows = w.findAll('[data-testid="contract-session"]').map((r) => r.text());
    expect(rows[0]).toContain('已付');
    expect(rows[2]).toContain('未付');
    expect(w.find('[data-testid="contract-summary"]').text()).toBe('共 3 堂・已上 1・還沒上 3');
    expect(w.find('[data-testid="contract-unscheduled"]').text()).toBe('還有 2 堂還沒排日期');
    expect(w.text()).not.toMatch(/課程 0|帳單-|收據-/);
  });

  it('U1 numbers count-mode lessons with a countdown and skips cancelled/leave', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();
    // 1 held + 1 upcoming + 2 unscheduled = 4 counted; leave gets no number.
    expect(w.findAll('[data-testid="contract-session-no"]').map((n) => n.text())).toEqual(['第 1 堂・剩 3 堂', '第 2 堂・剩 2 堂']);
    expect(w.findAll('[data-testid="contract-session"]')[1].text()).not.toContain('第');
  });

  it('U2 monthly: month heading shows count and paid state; U1 numbers within month', async () => {
    authedFetch.mockResolvedValueOnce(respond({
      ...COVERAGE, schedule_mode: 'date', unscheduled_count: 0,
      sessions: [
        { class_session_id: 1, date: '2026-09-01', status: 'attended', payment: 'paid' },
        { class_session_id: 2, date: '2026-09-08', status: 'cancelled', payment: 'paid' },
        { class_session_id: 3, date: '2026-09-15', status: 'attended', payment: 'paid' },
        { class_session_id: 4, date: '2026-10-06', status: 'scheduled', payment: 'unpaid' },
      ],
    }));
    const w = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();
    expect(w.findAll('[data-testid="contract-month-summary"]').map((n) => n.text())).toEqual(['2 堂・已收', '1 堂・未繳']);
    expect(w.findAll('[data-testid="contract-session-no"]').map((n) => n.text())).toEqual(['本月第 1 堂', '本月第 2 堂', '本月第 1 堂']);
  });

  it('U3 shows the most recent payment in the header, nothing when absent', async () => {
    authedFetch.mockResolvedValue(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9 }, lastPayment: { date: '2026-10-05', amount: 6600 } } });
    await flushPromises();
    expect(w.find('[data-testid="contract-last-payment"]').text()).toBe('10/5 繳 $6,600');
    const w2 = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();
    expect(w2.find('[data-testid="contract-last-payment"]').exists()).toBe(false);
  });

  it('saves a memo-only PUT and shows the new memo', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE)).mockResolvedValueOnce(respond({}));
    const w = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();

    await w.find('[data-testid="contract-memo-edit"]').trigger('click');
    await w.find('[data-testid="contract-memo-input"]').setValue('改成週四');
    await w.find('[data-testid="contract-memo-save"]').trigger('click');
    await flushPromises();

    const [url, init] = authedFetch.mock.calls[1];
    expect(url).toBe('/api/v1/student-classes/9');
    expect(init.method).toBe('PUT');
    expect(JSON.parse(init.body)).toEqual({ Memo: '改成週四' });
    expect(w.find('[data-testid="contract-memo"]').text()).toBe('改成週四');
    expect(w.emitted('changed')).toHaveLength(1);
  });

  it('keeps the card usable when dates fail to load', async () => {
    authedFetch.mockResolvedValueOnce(respond({}, 500));
    const w = mount(ContractCard, { props: { course: { id: 9, subject: '英文' } } });
    await flushPromises();
    expect(w.text()).toContain('上課日暫時無法載入');
  });
});

describe('AccountingLedgerModal contract cards', () => {
  it('renders one card per contract, money-owed first', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond({
      summary: {}, scope: {}, receipts: [], anomalies: [],
      courses: [{ id: 1, subject: '英文' }, { id: 2, subject: '數學' }],
      invoices: [{ id: 5, student_class_id: 2, outstanding_amount: 3000, status: 'unpaid', payments: [] }],
    })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    expect(w.findAll('[data-testid^="contract-card-"]').map((c) => c.attributes('data-testid'))).toEqual(['contract-card-2', 'contract-card-1']);
    vi.unstubAllGlobals();
  });
});

describe('ContractCard payment steps (PRD v2 D2/D9/D20)', () => {
  it('offers 登記收款 when money is due and emits record', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9 }, outstanding: 3000 } });
    await flushPromises();
    expect(w.find('[data-testid="contract-money"]').text()).toContain('NT$ 3,000');
    await w.find('[data-testid="contract-record"]').trigger('click');
    expect(w.emitted('record')[0][0]).toEqual({ id: 9 });
  });

  it('asks before confirming a pending report (取消 focused), then confirms in place', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE)).mockResolvedValueOnce(respond({}));
    const w = mount(ContractCard, { props: { course: { id: 9 }, outstanding: 3000, pendingReport: { report_id: 77, amount: 1000 } }, attachTo: document.body });
    await flushPromises();
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
    expect(w.text()).toContain('家長說繳了 NT$ 1,000，等你確認');
    await w.find('[data-testid="contract-confirm"]').trigger('click');
    await flushPromises();
    // nothing is booked until the dialog is accepted; 取消 holds the focus
    expect(authedFetch).toHaveBeenCalledTimes(1);
    expect(document.activeElement?.getAttribute('data-testid')).toBe('contract-confirm-cancel');
    expect(document.body.querySelector('[data-testid="contract-confirm-text"]').textContent).toContain('NT$ 1,000');
    document.body.querySelector('[data-testid="contract-confirm-cancel"]').click();
    await flushPromises();
    expect(authedFetch).toHaveBeenCalledTimes(1);
    await w.find('[data-testid="contract-confirm"]').trigger('click');
    await flushPromises();
    document.body.querySelector('[data-testid="contract-confirm-submit"]').click();
    await flushPromises();
    const [url, init] = authedFetch.mock.calls[1];
    expect(url).toBe('/api/v1/payment-reports/77/confirm');
    expect(init.method).toBe('PUT');
    expect(w.emitted('changed')).toHaveLength(1);
    w.unmount();
  });


  it('rejects with a reason from an in-panel dialog (no window.prompt) and shows server errors', async () => {
    const prompt = vi.fn();
    vi.stubGlobal('prompt', prompt);
    authedFetch.mockResolvedValueOnce(respond(COVERAGE)).mockResolvedValueOnce(respond({ message: '已處理過' }, 422));
    const w = mount(ContractCard, { props: { course: { id: 9 }, pendingReport: { report_id: 77, amount: 1000 } }, attachTo: document.body });
    await flushPromises();
    await w.find('[data-testid="contract-reject"]').trigger('click');
    await flushPromises();
    const q = (id) => document.body.querySelector(`[data-testid="${id}"]`);
    expect(prompt).not.toHaveBeenCalled();
    expect(authedFetch).toHaveBeenCalledTimes(1);
    // empty / whitespace reason cannot be submitted (same rule as before)
    expect(q('contract-reject-submit').disabled).toBe(true);
    q('contract-reject-reason').value = '   ';
    q('contract-reject-reason').dispatchEvent(new Event('input'));
    await flushPromises();
    expect(q('contract-reject-submit').disabled).toBe(true);
    q('contract-reject-reason').value = ' 金額不對 ';
    q('contract-reject-reason').dispatchEvent(new Event('input'));
    await flushPromises();
    q('contract-reject-submit').click();
    await flushPromises();
    expect(authedFetch.mock.calls[1][0]).toBe('/api/v1/payment-reports/77/reject');
    expect(JSON.parse(authedFetch.mock.calls[1][1].body)).toEqual({ rejection_note: '金額不對' });
    expect(w.text()).toContain('已處理過');
    expect(w.emitted('changed')).toBeUndefined();
    w.unmount();
    vi.unstubAllGlobals();
  });

  it('cancelling the reject dialog sends nothing', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9 }, pendingReport: { report_id: 77, amount: 1000 } }, attachTo: document.body });
    await flushPromises();
    await w.find('[data-testid="contract-reject"]').trigger('click');
    await flushPromises();
    document.body.querySelector('[data-testid="contract-reject-cancel"]').click();
    await flushPromises();
    expect(document.body.querySelector('[data-testid="contract-reject-reason"]')).toBeNull();
    expect(authedFetch).toHaveBeenCalledTimes(1);
    w.unmount();
  });


  it('hides 登記收款 for a paid contract with nothing due', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9, paid: true }, outstanding: 0 } });
    await flushPromises();
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
  });
});

describe('AccountingLedgerModal 登記收款', () => {
  it('opens the entry form on the oldest open invoice of that contract', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond({
      summary: {}, scope: {}, receipts: [], anomalies: [], student: { name: '學生' },
      courses: [{ id: 2, subject: '數學', paid: false }],
      invoices: [
        { id: 6, student_class_id: 2, outstanding_amount: 2000, due_date: '2026-10-01', status: 'unpaid', payments: [] },
        { id: 5, student_class_id: 2, outstanding_amount: 3000, due_date: '2026-09-01', status: 'unpaid', payments: [] },
      ],
    })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 2 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    await w.find('[data-testid="contract-record"]').trigger('click');
    const entry = w.findComponent({ name: 'PaymentEntryModal' });
    expect(entry.props('show')).toBe(true);
    expect(entry.props('row')).toMatchObject({ id: 2, invoice_id: 5, payable_amount: 3000, payable_status: 'invoiced' });
    vi.unstubAllGlobals();
  });
});

describe('ContractCard money states (#3731 review)', () => {
  it('tutoring shows no payment action', async () => {
    authedFetch.mockResolvedValueOnce(respond({ ...COVERAGE, class_type: 'tutoring' }));
    const w = mount(ContractCard, { props: { course: { id: 9, paid: false }, outstanding: 0 } });
    await flushPromises();
    expect(w.text()).toContain('輔導課不用繳費');
    expect(w.find('[data-testid="contract-record"]').exists()).toBe(false);
  });

  it('unbilled debt is not shown as NT$ 0', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9, paid: false }, outstanding: 0 } });
    await flushPromises();
    expect(w.find('[data-testid="contract-money"]').text()).toContain('還沒開帳單，金額待確認');
    expect(w.find('[data-testid="contract-money"]').text()).not.toContain('NT$ 0');
  });
});

describe('AccountingLedgerModal invoice pick (#3731 review)', () => {
  it('skips paid-status invoices and breaks ties oldest id first', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond({
      summary: {}, scope: {}, receipts: [], anomalies: [], student: { name: '學生' },
      courses: [{ id: 2, subject: '數學', paid: false }],
      invoices: [
        { id: 9, student_class_id: 2, outstanding_amount: 500, status: 'paid', payments: [] },
        { id: 8, student_class_id: 2, outstanding_amount: 2000, status: 'unpaid', payments: [] },
        { id: 7, student_class_id: 2, outstanding_amount: 3000, status: 'unpaid', payments: [] },
      ],
    })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 2 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    await w.find('[data-testid="contract-record"]').trigger('click');
    expect(w.findComponent({ name: 'PaymentEntryModal' }).props('row')).toMatchObject({ invoice_id: 7 });
    vi.unstubAllGlobals();
  });
});

describe('AccountingLedgerModal keyboard / focus (a11y)', () => {
  it('moves focus in, traps Tab, closes on Esc and returns focus', async () => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond({ summary: {}, scope: {}, receipts: [], anomalies: [], courses: [], invoices: [] })));
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const w = mount(AccountingLedgerModal, { props: { show: false, studentClassId: 1 }, global: { stubs: { Transition: false } }, attachTo: document.body });
    await w.setProps({ show: true });
    await flushPromises();
    const dialog = w.find('[role="dialog"]');
    expect(dialog.attributes('aria-labelledby')).toBe('ledger-modal-title');
    expect(document.activeElement).toBe(dialog.element);
    expect(w.find('.ledger-close').attributes('aria-label')).toBe('關閉學生帳務');

    // Shift+Tab from the dialog wraps to the last control; Tab from the last wraps to the first.
    const items = [...dialog.element.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')];
    await dialog.trigger('keydown', { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(items[items.length - 1]);
    await dialog.trigger('keydown', { key: 'Tab' });
    expect(document.activeElement).toBe(items[0]);

    await dialog.trigger('keydown', { key: 'Escape' });
    expect(w.emitted('close')).toHaveLength(1);
    await w.setProps({ show: false });
    expect(document.activeElement).toBe(opener);
    w.unmount();
    opener.remove();
  });
});

describe('AccountingLedgerModal mobile (PRD v2 D5)', () => {
  const mountLedger = async (ledger) => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
    vi.stubGlobal('fetch', vi.fn(async () => respond(ledger)));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1 }, global: { stubs: { Transition: false } } });
    await flushPromises();
    return w;
  };
  const INVOICE = { id: 5, student_class_id: 2, outstanding_amount: 3000, total_amount: 3000, status: 'unpaid', due_date: '2026-10-01', payments: [] };

  it('labels every invoice cell so the table can collapse into cards, and keeps a sticky 登記收款 for the first contract owing money', async () => {
    const w = await mountLedger({
      summary: {}, scope: {}, receipts: [], anomalies: [],
      courses: [{ id: 1, subject: '英文', paid: true }, { id: 2, subject: '數學', paid: false }],
      invoices: [INVOICE],
    });
    const labels = w.findAll('.ledger-table tbody tr:first-child td[data-label]').map((td) => td.attributes('data-label'));
    expect(labels).toEqual(['應繳日', '應收', '已記入', '未結清', '狀態']);
    const bar = w.find('[data-testid="ledger-sticky-pay"]');
    expect(bar.exists()).toBe(true);
    expect(bar.text()).toContain('登記收款');
    expect(bar.text()).toContain('數學');
    await w.find('[data-testid="ledger-sticky-pay-btn"]').trigger('click');
    expect(w.findComponent({ name: 'PaymentEntryModal' }).props('show')).toBe(true);
    vi.unstubAllGlobals();
  });

  it('has no sticky bar when nothing is owed or a parent report is pending', async () => {
    const w = await mountLedger({
      summary: {}, scope: {}, anomalies: [], invoices: [],
      courses: [{ id: 2, subject: '數學', paid: true }],
      receipts: [],
    });
    expect(w.find('[data-testid="ledger-sticky-pay"]').exists()).toBe(false);
    vi.unstubAllGlobals();
    const w2 = await mountLedger({
      summary: {}, scope: {}, anomalies: [], invoices: [INVOICE],
      courses: [{ id: 2, subject: '數學', paid: false }],
      receipts: [{ report_id: 9, student_class_id: 2, status: 'pending', amount: 1000 }],
    });
    expect(w2.find('[data-testid="ledger-sticky-pay"]').exists()).toBe(false);
    vi.unstubAllGlobals();
  });
});

describe('billing panel visual tokens (spec B-PR2)', () => {
  const read = async (rel) => (await import('node:fs')).readFileSync(new URL(rel, import.meta.url), 'utf8');
  it('uses hairline borders, 12px minimum text and AtButton (no white text on --ds-primary)', async () => {
    for (const rel of ['../tuition/ContractCard.vue', '../tuition/StudentBillingList.vue', '../AccountingLedgerModal.vue']) {
      const src = await read(rel);
      expect(src, rel).not.toMatch(/border[a-z-]*:\s*[\d.]+px solid var\(--ds-canvas-soft\)/);
      expect(src, rel).not.toContain('font-size:11px');
      expect(src, rel).not.toMatch(/color:var\(--ds-on-primary/);
    }
    expect(await read('../tuition/ContractCard.vue')).not.toContain('contract__btn');
  });

  it('keeps 已付 / 未付 stated on every lesson day (D16) with 已付 as plain text', async () => {
    authedFetch.mockResolvedValueOnce(respond(COVERAGE));
    const w = mount(ContractCard, { props: { course: { id: 9 } } });
    await flushPromises();
    const rows = w.findAll('[data-testid="contract-session"]');
    expect(rows.every((r) => /已付|未付/.test(r.text()))).toBe(true);
    expect(rows[0].find('.pay-paid').exists()).toBe(true);
  });
});

describe('AccountingLedgerModal copy + hierarchy (spec B-PR1)', () => {
  const mountLedger = async (ledger, extraFetch) => {
    const { default: AccountingLedgerModal } = await import('../AccountingLedgerModal.vue');
    authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const f = vi.fn(async (url) => (extraFetch?.(String(url)) || respond(String(url).includes('monthly-drafts') ? { data: [] } : ledger)));
    vi.stubGlobal('fetch', f);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 2 }, global: { stubs: { Transition: false } }, attachTo: document.body });
    await flushPromises();
    return { w, f };
  };
  const inv = (o) => ({ id: 1, student_class_id: 2, total_amount: 3000, calculated_applied_amount: 0, status: 'unpaid', payments: [], ...o });

  it('titles the panel 「{姓名}的帳務」 and leads with 到今天未繳, same rule as the student list', async () => {
    const { buildStudentBillingRows } = await import('../../lib/studentBillingRows.js');
    const today = new Date().toISOString().slice(0, 10);
    const past = '2020-01-01'; const future = '2099-01-01';
    const { w } = await mountLedger({
      student: { id: 1, name: '王小明' }, summary: { overpaid_total: 0 }, scope: {}, receipts: [], anomalies: [], courses: [{ id: 2, subject: '數學', paid: false }],
      invoices: [inv({ id: 1, outstanding_amount: 3000, due_date: past }), inv({ id: 2, outstanding_amount: 2000, due_date: future }), inv({ id: 3, outstanding_amount: 900, status: 'void', due_date: past })],
    });
    expect(w.find('#ledger-modal-title').text()).toBe('王小明的帳務');
    expect(w.text()).not.toMatch(/對帳|對齊帳單/);
    expect(w.find('[data-testid="ledger-owed-now"]').text()).toBe('NT$ 3,000');
    expect(w.find('[data-testid="ledger-owed-later"]').text()).toContain('NT$ 2,000');
    const row = buildStudentBillingRows([
      { id: 2, student_id: 1, student_name: '王小明', payment_status: 'unpaid', payable_outstanding: 3000, due_date: past },
      { id: 2, student_id: 1, student_name: '王小明', payment_status: 'unpaid', payable_outstanding: 2000, due_date: future },
    ], [], today)[0];
    expect([row.owed_now, row.owed_later]).toEqual([3000, 2000]);
    w.unmount(); vi.unstubAllGlobals();
  });

  it('names the overpaid payment, offers 撤銷這筆 and a one-line explanation; no 請聯絡總部', async () => {
    const { w } = await mountLedger({
      student: { id: 1, name: '王小明' }, summary: { overpaid_total: 500 }, scope: {}, receipts: [], anomalies: [], courses: [{ id: 2, subject: '數學', paid: true }],
      invoices: [inv({ outstanding_amount: 0, status: 'paid', payments: [{ id: 7, report_id: 9, paid_at: '2026-10-05', amount: 3500, method: 'transfer', unapplied_amount: 500, application_status: 'overpayment_pending_review', receipt_no: 'RCPT-000123' }] })],
    });
    expect(w.find('[data-testid="ledger-overpaid"]').text()).toContain('多收 NT$ 500');
    const text = w.text();
    expect(text).toContain('2026/10/05 匯款 NT$ 3,500 這筆');
    expect(text).toContain('撤銷這筆');
    expect(w.find('[data-testid="ledger-exception-hint"]').text()).toContain('撤銷這筆就好');
    expect(text).not.toMatch(/請聯絡總部|RCPT-|收據-/);
    w.unmount(); vi.unstubAllGlobals();
  });

  it('shows 已收 / 等你確認 receipts by default and folds rejected, voided and 0 元 under 其他紀錄', async () => {
    const r = (id, status, amount) => ({ report_id: id, student_class_id: 2, status, amount, payment_date: '2026-10-01', payment_method: 'cash', receipt_no: `RCPT-${id}` });
    const { w } = await mountLedger({
      student: { id: 1, name: '王小明' }, summary: {}, scope: {}, anomalies: [], courses: [], invoices: [],
      receipts: [r(1, 'confirmed', 3000), r(2, 'pending', 500), r(3, 'rejected', 800), r(4, 'voided', 700), r(5, 'confirmed', 0)],
    });
    expect(w.findAll('.ledger-receipt')).toHaveLength(2);
    expect(w.text()).not.toMatch(/RCPT-/);
    const toggle = w.find('[data-testid="ledger-other-receipts-toggle"]');
    expect(toggle.text()).toContain('其他紀錄（3）');
    await toggle.trigger('click');
    expect(w.findAll('.ledger-receipt')).toHaveLength(5);
    w.unmount(); vi.unstubAllGlobals();
  });

  it('offers 開帳單 in place only where a ready monthly draft exists, via the existing renew-monthly request', async () => {
    const draft = { student_class_id: 2, status: 'ready', subject: '數學', proposed_start_date: '2099-01-02', proposed_end_date: '2099-02-01', period_sessions: 4, amount: 4000, due_date: '2099-01-10' };
    const { w } = await mountLedger({
      student: { id: 1, name: '王小明' }, summary: {}, scope: {}, receipts: [], anomalies: [], invoices: [],
      courses: [{ id: 2, subject: '數學', paid: false }, { id: 3, subject: '英文', paid: false }],
    }, (url) => {
      if (url.includes('monthly-drafts')) return respond({ data: [draft] });
      return null;
    });
    await flushPromises();
    await flushPromises();
    expect(w.findAll('[data-testid="contract-issue"]')).toHaveLength(1);
    await w.find('[data-testid="contract-issue"]').trigger('click');
    await flushPromises();
    expect(document.body.querySelector('[data-testid="ledger-issue-text"]').textContent).toContain('NT$ 4,000');
    authedFetch.mockResolvedValueOnce(respond({}));
    document.body.querySelector('[data-testid="ledger-issue-submit"]').click();
    await flushPromises();
    const renew = authedFetch.mock.calls.find((c) => String(c[0]).includes('/student-classes/2/renew-monthly'));
    expect(renew).toBeTruthy();
    expect(JSON.parse(renew[1].body)).toEqual({ end_date: '2099-02-01' });
    w.unmount(); vi.unstubAllGlobals();
  });
});
