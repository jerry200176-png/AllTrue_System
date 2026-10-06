// M1 (docs/plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md, FR-003 / FR-009 / NFR-005):
// expanding an invoice or a confirmed receipt in 學生帳務對帳 lists every covered
// lesson date, from the same endpoints the printed slip / receipt use.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';
import { COVERAGE_PREVIEW_LIMIT } from '../../lib/ledgerCoverageDates.js';

const MONTHLY_INVOICE = {
  id: 41, student_class_id: 1, subject: '數學', first_session_date: '2026-08-03', billing_period: '2026-08',
  total_amount: 6000, calculated_applied_amount: 6000, outstanding_amount: 0, status: 'paid', payments: [],
};
const COUNT_INVOICE = {
  id: 42, student_class_id: 2, subject: '英文', first_session_date: '2026-09-01', billing_period: null,
  total_amount: 8000, calculated_applied_amount: 0, outstanding_amount: 8000, status: 'unpaid', payments: [],
};
const MONTHLY_SLIP = {
  invoice_id: 41, items: [], status: 'paid',
  sessions: [
    { date: '2026-08-03', status: 'attended' },
    { date: '2026-08-10', status: 'scheduled' },
    { date: '2026-08-17', status: 'scheduled' },
    { date: '2026-08-24', status: 'scheduled' },
  ],
};
const countSessions = (n) => Array.from({ length: n }, (_, i) => ({
  date: `2026-${String(9 + Math.floor(i / 4)).padStart(2, '0')}-${String(1 + (i % 4) * 7).padStart(2, '0')}`,
  status: i < 2 ? 'attended' : 'scheduled',
}));
const COUNT_RECEIPT_ROW = {
  report_id: 7, student_class_id: 2, amount: 8000, status: 'confirmed', payment_method: 'cash', receipt_no: 'RCPT-202609-000007',
};
const COUNT_RECEIPT = {
  receipt_no: 'R-000007', amount: 8000, schedule_mode: 'count',
  session_dates: [
    { date: '2026/09/01', expected: false },
    { date: '2026/09/08', expected: false },
    { date: '2026/09/15', expected: true },
    { date: '2026/09/22', expected: true },
  ],
};

function ledger(over = {}) {
  return { summary: {}, scope: { student_class_id: 1 }, invoices: [], receipts: [], anomalies: [], ...over };
}

function respond(body, status = 200) {
  return { ok: status < 400, status, json: async () => body };
}

/** routes: { [urlSubstring]: response | () => response } ; ledger always answers first. */
async function mountLedger(payload, routes = {}) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  const fetchMock = vi.fn(async (url) => {
    if (String(url).startsWith('/api/v1/accounting/ledger')) return respond(payload);
    const hit = Object.keys(routes).find((k) => String(url).includes(k));
    if (!hit) return respond({ message: 'unexpected' }, 500);
    const r = routes[hit];
    return typeof r === 'function' ? r() : r;
  });
  vi.stubGlobal('fetch', fetchMock);
  const w = mount(AccountingLedgerModal, {
    props: { show: true, studentClassId: 1, branchId: 1 },
    global: { stubs: { Transition: false } },
  });
  await flushPromises();
  return { w, fetchMock };
}

function invoiceToggle(w, id) {
  return w.find(`[data-testid="ledger-invoice-toggle-${id}"]`);
}

function coverageDates(w, key) {
  return w.findAll(`[data-testid="ledger-coverage-${key}"] [data-testid="ledger-coverage-date"]`).map((li) => li.text());
}

afterEach(() => vi.unstubAllGlobals());

describe('學生帳務對帳 · 涵蓋上課日 (M1)', () => {
  it('monthly invoice: expanding lists every service-period lesson from slip-data, not only the first', async () => {
    const { w, fetchMock } = await mountLedger(ledger({ invoices: [MONTHLY_INVOICE] }), {
      '/api/v1/invoices/41/slip-data': respond(MONTHLY_SLIP),
    });
    // Lazy: nothing fetched until the director expands the row.
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('slip-data'))).toBe(false);

    const toggle = invoiceToggle(w, 41);
    expect(toggle.attributes('disabled')).toBeUndefined();
    await toggle.trigger('click');
    await flushPromises();

    const dates = coverageDates(w, 'inv-41');
    expect(dates).toHaveLength(4);
    expect(dates.join(' ')).toContain('2026/08/03');
    expect(dates.join(' ')).toContain('2026/08/24');
    expect(dates[0]).toContain('已到課');
    expect(dates[1]).toContain('排定');
    expect(w.find('[data-testid="ledger-coverage-inv-41"]').text()).toContain('共 4 堂');
    expect(w.find('[data-testid="ledger-coverage-inv-41"] ol, [data-testid="ledger-coverage-inv-41"] ul').exists()).toBe(true);
    expect(toggle.attributes('aria-expanded')).toBe('true');
  });

  it('count-mode invoice: lists the purchased range and truncates with 尚有 N 堂', async () => {
    const total = COVERAGE_PREVIEW_LIMIT + 5;
    const { w } = await mountLedger(ledger({ invoices: [COUNT_INVOICE] }), {
      '/api/v1/invoices/42/slip-data': respond({ invoice_id: 42, items: [], sessions: countSessions(total) }),
    });
    await invoiceToggle(w, 42).trigger('click');
    await flushPromises();

    const block = w.find('[data-testid="ledger-coverage-inv-42"]');
    expect(block.text()).toContain(`共 ${total} 堂`);
    expect(coverageDates(w, 'inv-42')).toHaveLength(COVERAGE_PREVIEW_LIMIT);
    const more = w.find('[data-testid="ledger-coverage-more-inv-42"]');
    expect(more.text()).toContain('尚有 5 堂');

    await more.trigger('click');
    expect(coverageDates(w, 'inv-42')).toHaveLength(total);
  });

  it('confirmed count-mode receipt: lists held + expected purchased lessons from the receipt endpoint', async () => {
    const { w } = await mountLedger(ledger({ receipts: [COUNT_RECEIPT_ROW] }), {
      '/api/v1/payment-reports/7/receipt': respond(COUNT_RECEIPT),
    });
    await w.find('[data-testid="ledger-receipt-toggle-7"]').trigger('click');
    await flushPromises();

    const dates = coverageDates(w, 'rcpt-7');
    expect(dates).toHaveLength(4);
    expect(dates[0]).toContain('2026/09/01');
    expect(dates[3]).toContain('2026/09/22');
    expect(dates[3]).toContain('預計');
    expect(w.find('[data-testid="ledger-coverage-rcpt-7"]').text()).toContain('共 4 堂');
  });

  it('does not offer 上課日 for a receipt that is not confirmed (no receipt document exists yet)', async () => {
    const { w } = await mountLedger(ledger({ receipts: [{ ...COUNT_RECEIPT_ROW, report_id: 8, status: 'pending' }] }));
    expect(w.find('[data-testid="ledger-receipt-toggle-8"]').exists()).toBe(false);
  });

  it('NFR-005: coverage failure keeps the amount visible and offers a retry', async () => {
    let calls = 0;
    const { w } = await mountLedger(ledger({ invoices: [MONTHLY_INVOICE] }), {
      '/api/v1/invoices/41/slip-data': () => (++calls === 1 ? respond({ message: 'boom' }, 500) : respond(MONTHLY_SLIP)),
    });
    await invoiceToggle(w, 41).trigger('click');
    await flushPromises();

    const block = w.find('[data-testid="ledger-coverage-inv-41"]');
    expect(block.text()).toContain('上課日暫時無法載入');
    expect(w.text()).toContain('6,000');
    expect(w.text()).not.toContain('boom');

    await w.find('[data-testid="ledger-coverage-retry-inv-41"]').trigger('click');
    await flushPromises();
    expect(coverageDates(w, 'inv-41')).toHaveLength(4);
  });

  it('FR-009: the row keeps its short first-lesson label while the full list lives in the expansion', async () => {
    const { w } = await mountLedger(ledger({ invoices: [MONTHLY_INVOICE] }), {
      '/api/v1/invoices/41/slip-data': respond(MONTHLY_SLIP),
    });
    expect(w.text()).toContain('數學 · 上課');
    await invoiceToggle(w, 41).trigger('click');
    await flushPromises();
    expect(coverageDates(w, 'inv-41').length).toBeGreaterThan(1);
  });
});
