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
    expect(w.text()).toContain('繳費單是依課程估算，尚未建立帳單；登記並確認入帳後才會出現在這裡。');
    expect(w.text()).not.toContain('此學生尚無帳單');
  });

  it('judges emptiness for the scoped course, not the whole student', async () => {
    const other = { id: 9, student_class_id: 2, total_amount: 100, status: 'unpaid', payments: [] };
    const w = await mountWith({ summary: {}, scope: { student_class_id: 1 }, invoices: [other], receipts: [], anomalies: [] });
    expect(w.text()).toContain('繳費單是依課程估算');
    const w2 = await mountWith({ summary: {}, scope: { student_class_id: 2 }, invoices: [other], receipts: [], anomalies: [] });
    expect(w2.text()).not.toContain('繳費單是依課程估算');
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
