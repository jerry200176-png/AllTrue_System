import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import AccountingLedgerModal from '../AccountingLedgerModal.vue';

const here = dirname(fileURLToPath(import.meta.url));

async function mountWith(payload) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => payload }));
  const w = mount(AccountingLedgerModal, {
    props: { show: true, studentClassId: 1, branchId: 1 },
    global: { stubs: { Transition: false } },
  });
  await flushPromises();
  return w;
}

afterEach(() => vi.unstubAllGlobals());

describe('AccountingLedgerModal empty state, 後5碼 and 備註', () => {
  it('explains estimated slips when there are no invoices or receipts', async () => {
    const w = await mountWith({ summary: {}, invoices: [], receipts: [], anomalies: [] });
    expect(w.text()).toContain('目前只有繳費單（依課程估算），還沒有帳單和收款。登記收款並確認入帳後，才會出現在這裡。');
    expect(w.text()).not.toContain('此學生尚無帳單');
  });

  it('tells tutoring courses there is no payment, never to register one', async () => {
    const w = await mountWith({ summary: {}, scope: { student_class_id: 1, no_payment_obligation: true }, invoices: [], receipts: [], anomalies: [] });
    expect(w.text()).toContain('輔導課不需繳費');
    expect(w.text()).not.toContain('登記並確認入帳');
  });

  it('a combined bill with a line for the opened course is not 其他課程 and the course is not empty (#3912)', async () => {
    const combined = { id: 9, student_class_id: 2, covered_student_class_ids: [2, 1], total_amount: 100, status: 'unpaid', payments: [] };
    const w = await mountWith({ summary: {}, scope: { student_class_id: 1 }, invoices: [combined], receipts: [], anomalies: [] });
    expect(w.find('[data-testid="ledger-other-course"]').exists()).toBe(false);
    expect(w.find('[data-testid="ledger-target-empty"]').exists()).toBe(false);
    // Without the field (older payload) the anchor alone decides, as before.
    const legacy = await mountWith({ summary: {}, scope: { student_class_id: 1 }, invoices: [{ ...combined, covered_student_class_ids: undefined }], receipts: [], anomalies: [] });
    expect(legacy.get('[data-testid="ledger-other-course"]').text()).toBe('其他課程');
  });

  it('judges emptiness for the scoped course, not the whole student', async () => {
    const other = { id: 9, student_class_id: 2, total_amount: 100, status: 'unpaid', payments: [] };
    const w = await mountWith({ summary: {}, scope: { student_class_id: 1 }, invoices: [other], receipts: [], anomalies: [] });
    // #3894: no contradictory "no invoices" copy while another course's invoice is listed.
    expect(w.text()).not.toContain('目前只有繳費單');
    expect(w.get('[data-testid="ledger-target-empty"]').text()).toContain('這門課目前沒有帳單和收款');
    expect(w.get('[data-testid="ledger-other-course"]').text()).toBe('其他課程');
    const w2 = await mountWith({ summary: {}, scope: { student_class_id: 2 }, invoices: [other], receipts: [], anomalies: [] });
    expect(w2.text()).not.toContain('目前只有繳費單');
    expect(w2.find('[data-testid="ledger-target-empty"]').exists()).toBe(false);
    expect(w2.find('[data-testid="ledger-other-course"]').exists()).toBe(false);
  });

  it('#3894 owed header: legacy-only course is not red; other-course debt is labelled as the whole student', async () => {
    const legacyOnly = await mountWith({ summary: {}, scope: { student_class_id: 1 }, courses: [{ id: 1, paid: true }], invoices: [], receipts: [], anomalies: [] });
    expect(legacyOnly.get('[data-testid="ledger-owed-now"]').classes()).not.toContain('due');
    const other = { id: 9, student_class_id: 2, total_amount: 100, outstanding_amount: 100, due_date: '2020-01-01', status: 'unpaid', payments: [] };
    const w = await mountWith({ summary: {}, scope: { student_class_id: 1 }, courses: [{ id: 1, paid: true }], invoices: [other], receipts: [], anomalies: [] });
    expect(w.get('.ledger-owed__label').text()).toBe('此學生到今天未繳（含其他課程）');
    const later = await mountWith({ summary: {}, scope: { student_class_id: 1 }, courses: [{ id: 1, paid: true }], invoices: [{ ...other, due_date: '2999-01-01' }], receipts: [], anomalies: [] });
    expect(later.get('.ledger-owed__label').text()).toBe('到今天未繳'); // future-dated other-course balance is not today's debt
  });

  it('shows 已退回 without a receipt number', async () => {
    const w = await mountWith({ summary: {}, invoices: [], anomalies: [], receipts: [
      { report_id: 2, amount: 100, status: 'rejected', payment_method: 'transfer', receipt_no: '' },
    ] });
    expect(w.text()).toContain('已退回');
    expect(w.find('.ledger-ref').exists()).toBe(false);
  });

  it('shows 後5碼, 備註 as text and the 已退回 label on receipts', async () => {
    const w = await mountWith({
      summary: {}, invoices: [], anomalies: [],
      receipts: [
        { report_id: 1, amount: 100, status: 'pending', payment_method: 'transfer', receipt_no: 'RCPT-202604-000001', account_last5: '12345', note: '<b>x</b>' },
        { report_id: 2, amount: 100, status: 'rejected', payment_method: 'transfer', receipt_no: 'RCPT-202604-000002' },
      ],
    });
    expect(w.text()).toContain('後5碼 12345');
    expect(w.text()).toContain('備註：<b>x</b>');
    expect(w.find('.ledger-receipt-extra b').exists()).toBe(false);
    expect(w.text()).toContain('已退回');
  });

  it('TuitionCollectionPage 收據紀錄 rows render 後5碼 and 備註 (source check; no mount harness)', () => {
    const src = readFileSync(resolve(here, '../../pages/TuitionCollectionPage.vue'), 'utf8');
    expect(src).toContain('後5碼 {{ row.account_last5 }}');
    expect(src).toContain('備註：{{ row.note }}');
    expect(src).toContain('acct-sub--note');
    expect(src).toContain('overflow-wrap: anywhere');
  });
});
