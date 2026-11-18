// 帳務重整 §3: a 要先處理 step that needs HQ has its own button; the copy never says just 「請聯絡總部」.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

describe('學生帳務 · 要總部處理的項目', () => {
  it('historical bills stay readable and cannot offer correction, withdrawal, overpay or zero-bill writes', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const base = { historical_course_missing: true, student_class_id: 7, can_direct_void: true, can_exception_void: true, can_correct: true };
    const ledger = { summary: { overpaid_total: 600 }, scope: { student_class_id: 1 }, student: { id: 55, name: '合成學生' }, courses: [], receipts: [], anomalies: [],
      invoices: [{ ...base, id: 8, status: 'paid', total_amount: 4400, calculated_applied_amount: 4400, outstanding_amount: 0,
        payments: [{ id: 2, amount: 5000, unapplied_amount: 600, application_status: 'overpayment_pending_review', report_id: 9 }], reports: [] },
      { ...base, id: 10, status: 'paid', total_amount: 0, paid_amount: 0, calculated_applied_amount: 0, outstanding_amount: 0, payments: [], reports: [{ report_id: 10, status: 'confirmed' }] }] };
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const sent = [];
    const onReport = (e) => sent.push(e.detail);
    window.addEventListener('alltrue-open-bug-report', onReport);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    try {
      await flushPromises();
      expect(w.get('[data-testid="ledger-historical-8"]').text()).toContain('原課程已移除');
      expect(w.text()).toContain('4,400');
      expect(w.text()).toContain('多收');
      expect(w.text()).not.toContain('沒有對應的收款');
      expect(w.findAll('button.ledger-action--danger')).toHaveLength(0);
      expect(w.findAll('button.ledger-action--warning')).toHaveLength(0);
      expect(w.find('[data-testid="ledger-correct-8"]').exists()).toBe(false);
      expect(w.find('[data-testid="ledger-correct-10"]').exists()).toBe(false);
      expect(w.text()).not.toContain('按「撤銷');
      expect(w.findAll('[data-testid="ledger-exception-report"]')).toHaveLength(2);
      await w.findAll('[data-testid="ledger-exception-report"]')[1].trigger('click');
      expect(sent).toHaveLength(1);
      expect(sent[0].relatedReference).toContain('帳單ID 10');
      expect(sent[0].relatedReference).toContain('課程ID 7');
      expect(sent[0].relatedReference).toContain('收據ID 10');
      expect(sent[0].description).toContain('0 元帳單需核對');
      expect(sent[0].description).not.toContain('undefined');
      expect(sent[0].description).not.toContain('沒有可撤銷的收款');
      expect(fetchMock.mock.calls.every(([, opts]) => !opts?.method || opts.method === 'GET')).toBe(true);
    } finally { window.removeEventListener('alltrue-open-bug-report', onReport); w.unmount(); }
  });

  it.each([false, true])('historical invoice/report links veto generic void anomalies (payment link=%s)', async (paymentLink) => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { id: 55, name: '合成學生' }, courses: [], receipts: [],
      invoices: [{ id: 8, student_class_id: 7, historical_course_missing: true, status: 'paid', total_amount: 4400, payments: paymentLink ? [{ report_id: 9, amount: 4400 }] : [] }],
      anomalies: ['receipt_without_payment', 'confirmed_receipt_without_payment'].map(code => ({ code, message: '已確認收據缺少收款紀錄。',
        invoice_id: paymentLink ? 999 : 8, student_class_id: 1, report_id: 9, action: { type: 'void_report', report_id: 9 } })) };
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ledger })));
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    try {
      await flushPromises();
      expect(w.findAll('button.ledger-action--danger')).toHaveLength(0);
      expect(w.findAll('[data-testid="ledger-exception-report"]')).toHaveLength(1);
      expect(w.text()).not.toContain('按「撤銷收據」');
      expect(w.text()).not.toContain('相關收據可撤銷');
    } finally { w.unmount(); }
  });

  it.each(['manual_only', 'manual_first', 'manual_last'])('in-app #576: %s keeps one HQ action and no impossible withdrawal', async (order) => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const manual = { code: 'receipt_without_invoice', severity: 'warning', message: '原課程不在本學生帳務中，請按「回報給總部」核對收據歸屬。',
      report_id: 9, student_class_id: 7, action: { type: 'manual_review', label: '回報給總部核對收據' } };
    const sibling = { ...manual, code: 'confirmed_receipt_without_payment' };
    const stale = { ...sibling, action: { type: 'void_report', report_id: 9 } };
    const ledger = { summary: {}, scope: { student_class_id: 1 }, student: { id: 55, name: '合成學生' }, invoices: [], receipts: [],
      anomalies: order === 'manual_only' ? [manual, sibling] : order === 'manual_first' ? [manual, stale] : [stale, manual] };
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ledger }));
    vi.stubGlobal('fetch', fetchMock);
    const sent = [];
    const onReport = (e) => sent.push(e.detail);
    window.addEventListener('alltrue-open-bug-report', onReport);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    try {
      await flushPromises();
      expect(w.findAll('button.ledger-action--danger')).toHaveLength(0);
      expect(w.text()).toContain(manual.message);
      expect(w.text()).not.toContain('按「撤銷收據」');
      expect(w.text()).not.toContain('相關收據可撤銷');
      expect(w.findAll('[data-testid="ledger-exception-report"]')).toHaveLength(1);
      await w.get('[data-testid="ledger-exception-report"]').trigger('click');
      expect(sent).toHaveLength(1);
      expect(sent[0].relatedReference).toContain('收據ID 9');
      expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
    } finally {
      window.removeEventListener('alltrue-open-bug-report', onReport);
      w.unmount();
    }
  });

  it('a receipt that does not match a bill offers 回報給總部 beside 撤銷收據, prefilled with the receipt id', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't', user: { role: 'director' } }));
    const ledger = {
      summary: {}, scope: { student_class_id: 1 }, student: { id: 55, name: '合成學生' }, invoices: [], receipts: [],
      anomalies: [{ code: 'receipt_without_invoice', severity: 'warning', message: 'm', report_id: 9, invoice_id: null, student_class_id: 1, action: { type: 'void_report', report_id: 9 } }],
    };
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ledger })));
    const sent = [];
    const onReport = (e) => sent.push(e.detail);
    window.addEventListener('alltrue-open-bug-report', onReport);
    const w = mount(AccountingLedgerModal, { props: { show: true, studentClassId: 1, branchId: 1 }, global: { stubs: { Transition: false } } });
    try {
      await flushPromises();
      expect(w.find('[data-testid="ledger-exception-hint"]').text()).toContain('按「回報給總部」');
      expect(w.text()).not.toContain('請聯絡總部');
      expect(w.findAll('button.ledger-action--danger')).toHaveLength(1); // 撤銷收據 stays
      await w.get('[data-testid="ledger-exception-report"]').trigger('click');
      expect(sent).toHaveLength(1);
      expect(sent[0].relatedReference).toContain('收據ID 9');
      expect(sent[0].description).not.toContain('沒有可撤銷的收款');
    } finally {
      window.removeEventListener('alltrue-open-bug-report', onReport);
      w.unmount();
    }
  });
});
