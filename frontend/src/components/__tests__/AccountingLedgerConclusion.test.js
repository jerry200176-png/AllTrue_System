// F-1 (billing copy spec L1+L2): one-line conclusion at the top of the student drawer, long explanations behind 「為什麼？」.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a), getAccessToken: async () => 'tok' }));

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const inv = (o) => ({ id: 1, student_class_id: 2, total_amount: 3000, calculated_applied_amount: 0, outstanding_amount: 3000, status: 'unpaid', due_date: '2020-01-01', payments: [], ...o });
const course = (o) => ({ id: 2, subject: '數學', paid: false, class_type: 'regular', ...o });

const open = async (ledger) => {
  const { default: Modal } = await import('../AccountingLedgerModal.vue');
  authedFetch.mockResolvedValue(respond({ sessions: [], unscheduled_count: 0 }));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
  vi.stubGlobal('fetch', vi.fn(async (url) => respond(String(url).includes('monthly-drafts') ? { data: [] } : ledger)));
  const w = mount(Modal, { props: { show: true, studentClassId: 2 }, global: { stubs: { Transition: false } }, attachTo: document.body });
  await flushPromises();
  return w;
};
const base = { student: { id: 1, name: '王小明' }, scope: {}, anomalies: [], receipts: [], summary: {} };

beforeEach(() => authedFetch.mockReset());

describe('ledger drawer conclusion', () => {
  it('says 未繳 with the amount owed to date, and keeps the explanation behind 為什麼？', async () => {
    const w = await open({ ...base, courses: [course()], invoices: [inv()] });
    const line = w.get('[data-testid="ledger-conclusion"]');
    expect(line.text()).toContain('未繳 NT$ 3,000');
    expect(line.find('summary[aria-label="為什麼？"]').exists()).toBe(true);
    w.unmount(); vi.unstubAllGlobals();
  });

  it('adds 待確認入帳 for a pending report, and never calls it 已收', async () => {
    const w = await open({
      ...base, courses: [course()], invoices: [inv({ outstanding_amount: 0 })],
      receipts: [{ report_id: 5, student_class_id: 2, status: 'pending', amount: 3000, payment_date: '2026-10-05' }],
    });
    const text = w.get('[data-testid="ledger-conclusion"]').text();
    expect(text).toContain('待確認入帳 NT$ 3,000');
    expect(text).not.toContain('已收齊');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('reads 已收齊 only when nothing is owed, pending or unbilled', async () => {
    const w = await open({ ...base, courses: [course({ paid: true })], invoices: [inv({ outstanding_amount: 0, status: 'paid', calculated_applied_amount: 3000 })],
      receipts: [{ report_id: 6, student_class_id: 2, status: 'confirmed', amount: 3000, payment_date: '2026-10-05' }] });
    expect(w.get('[data-testid="ledger-conclusion"]').text()).toContain('已收齊');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('flags a contract with no bill instead of claiming 已收齊', async () => {
    const w = await open({ ...base, courses: [course()], invoices: [] });
    expect(w.get('[data-testid="ledger-conclusion"]').text()).toContain('還沒開帳單');
    w.unmount(); vi.unstubAllGlobals();
  });
  it('does not say 已收齊 when the only bill is not due yet', async () => {
    const w = await open({ ...base, courses: [course()], invoices: [inv({ due_date: '2999-01-01' })] });
    const text = w.get('[data-testid="ledger-conclusion"]').text();
    expect(text).toContain('到今天沒有未繳');
    expect(text).not.toContain('已收齊');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('reads 需核對, not 已收齊, for a legacy Paid flag with no payment evidence', async () => {
    const w = await open({ ...base, courses: [course({ paid: true, paid_at: '2026-01-05' })], invoices: [] });
    const text = w.get('[data-testid="ledger-conclusion"]').text();
    expect(text).toContain('需核對');
    expect(text).not.toContain('已收齊');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('keeps 還沒開帳單 when only an older settled invoice exists', async () => {
    const w = await open({ ...base, courses: [course({ schedule_mode: 'date' })], invoices: [inv({ outstanding_amount: 0, status: 'paid', calculated_applied_amount: 3000 })] });
    const text = w.get('[data-testid="ledger-conclusion"]').text();
    expect(text).toContain('還沒開帳單');
    expect(text).not.toContain('已收齊');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('never calls a tutoring course unbilled', async () => {
    const w = await open({ ...base, scope: { student_class_id: 2, no_payment_obligation: true }, courses: [course({ class_type: 'tutoring' })], invoices: [] });
    expect(w.get('[data-testid="ledger-conclusion"]').text()).not.toContain('還沒開帳單');
    w.unmount(); vi.unstubAllGlobals();
  });

  it('#4699: a tutoring course that is not the opened one is not unbilled, on the headline or on its card', async () => {
    const w = await open({
      ...base, scope: { student_class_id: 2, no_payment_obligation: false },
      courses: [course({ paid: true }), course({ id: 3, subject: '輔導', class_type: undefined, no_payment_obligation: true })],
      invoices: [inv({ outstanding_amount: 0, status: 'paid', calculated_applied_amount: 3000 })],
    });
    expect(w.get('[data-testid="ledger-conclusion"]').text()).not.toContain('還沒開帳單');
    expect(w.get('[data-testid="contract-card-3"]').text()).not.toContain('還沒開帳單');
    expect(w.get('[data-testid="contract-card-3"]').text()).toContain('輔導課不用繳費');
    w.unmount(); vi.unstubAllGlobals();
  });
});
