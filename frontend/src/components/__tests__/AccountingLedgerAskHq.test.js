// 帳務重整 §3: a 要先處理 step that needs HQ has its own button; the copy never says just 「請聯絡總部」.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

afterEach(() => vi.unstubAllGlobals());

describe('學生帳務 · 要總部處理的項目', () => {
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
