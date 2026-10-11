import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

// #3875 cross-stack link. Each case mounts the REAL component with the queue row the backend serves for that synthetic
// scenario and captures the request body it ACTUALLY sends. The captured bodies (with the input rows) are the shared
// fixture backend/tests/Fixtures/collection_requests_3875.json: this test fails when what the frontend sends drifts from
// it (regenerate with `npx vitest run -u <this file>`), and CollectionRequestFixtureTest.php checks the backend queue
// rows still equal these rows, then posts the captured bodies through intake → confirm → Payment ledger.
// Ids are placeholders the PHP test maps to the rows it creates.
const FIXTURE = '../../../../backend/tests/Fixtures/collection_requests_3875.json';
const TODAY = '2026-04-28';

const row = (over) => ({
  id: '{{course}}', student_name: '測試生', subject: '數學', schedule_mode: 'count', alert_type: 'unpaid',
  payment_status: 'unpaid', payable_status: 'invoiced', remaining_sessions: 5, ...over,
});

// Rows as GET /api/v1/alerts/tuition returns them for each scenario (fields the components read; PHP re-checks them).
const CASES = {
  item1_modal_owing_bill: { kind: 'modal', row: row({ charge: 8000, payable_amount: 3000, payable_outstanding: 3000, payable_invoice_id: '{{invoice:older}}' }) },
  item2_modal_typed_amount_on_zero_bill: { kind: 'modal', typed: 500, row: row({ charge: 6000, payable_amount: 0, payable_outstanding: 0, payable_invoice_id: '{{invoice:zero}}' }) },
  item2_modal_zero_amount_blocked: { kind: 'modal', row: row({ charge: 6000, payable_amount: 0, payable_outstanding: 0, payable_invoice_id: '{{invoice:zero}}' }) },
  item3_modal_stored_paid_owing: { kind: 'modal', row: row({ payment_status: 'partial', charge: 6000, payable_amount: 6000, payable_outstanding: 4000, payable_invoice_id: '{{invoice:paid}}' }) },
  item4_batch_newer_exceeds_older: { kind: 'batch', row: row({ charge: 4000, payable_amount: 3000, payable_outstanding: 3000, payable_invoice_id: '{{invoice:newer}}' }) },
  item4_batch_newer_smaller_amount: { kind: 'batch', row: row({ charge: 1800, payable_amount: 800, payable_outstanding: 800, payable_invoice_id: '{{invoice:newer}}' }) },
};

function json(status, body) {
  return { ok: status < 300, status, json: () => Promise.resolve(body) };
}

async function captureModal({ row: r, typed }) {
  const sent = [];
  global.fetch = vi.fn((url, init = {}) => { sent.push(JSON.parse(init.body)); return Promise.resolve(json(200, { report_id: 1 })); });
  const wrapper = mount(PaymentEntryModal, { props: { show: false, row: r } });
  await wrapper.setProps({ show: true });
  await nextTick();
  await wrapper.find('input[type="radio"][value="cash"]').setValue(true);
  if (typed !== undefined) await wrapper.find('input[type="number"]').setValue(typed);
  await wrapper.find('form').trigger('submit');
  await flushPromises();
  const blocked = wrapper.find('.pe-error').exists() ? wrapper.find('.pe-error').text() : null;
  wrapper.unmount();
  return { endpoint: 'director-record', request: sent[0] ?? null, blocked_message: sent.length ? null : blocked };
}

async function captureBatch({ row: r }) {
  const sent = [];
  global.fetch = vi.fn((url, init = {}) => {
    const u = String(url);
    if (u.includes('/api/v1/alerts/tuition')) return Promise.resolve(json(200, [r]));
    if (u.includes('/director-record-batch')) { sent.push(JSON.parse(init.body)); return Promise.resolve(json(200, { message: 'ok', accepted: 1, results: [] })); }
    return Promise.resolve(json(200, []));
  });
  const wrapper = mount(TuitionCollectionPage, {
    props: { branchId: 1, initialTab: 'unpaid' },
    global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true,
      AccountingLedgerModal: true, StudentBillingList: true, OperationsQuickStart: true, teleport: true } },
  });
  await flushPromises();
  for (const box of wrapper.findAll('input[type="checkbox"][aria-label^="選取"]')) await box.setValue(true);
  await wrapper.find('select').setValue('cash');
  await wrapper.findAll('button').find((b) => b.text().includes('送出已回報')).trigger('click');
  await flushPromises();
  await wrapper.findAll('button').find((b) => b.text().includes('確認送出回報')).trigger('click');
  await flushPromises();
  wrapper.unmount();
  return { endpoint: 'director-record-batch', request: sent[0] ?? null, blocked_message: null };
}

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(`${TODAY}T10:00:00+08:00`));
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
});
afterAll(() => vi.useRealTimers());

describe('collection request fixture shared with the backend (#3875)', () => {
  it('captures what the real modal and page send for each synthetic queue row', async () => {
    const out = { _comment: 'GENERATED by frontend/src/components/__tests__/TuitionCollectionBatchInvoiceTarget.test.js — do not edit by hand (#3875).' };
    for (const [name, c] of Object.entries(CASES)) {
      out[name] = { kind: c.kind, row: c.row, typed_amount: c.typed ?? null, ...(c.kind === 'modal' ? await captureModal(c) : await captureBatch(c)) };
    }

    // Spot checks that make the intent readable here; the file snapshot is the full contract.
    expect(out.item1_modal_owing_bill.request).toMatchObject({ amount: 3000, invoice_id: '{{invoice:older}}' });
    expect(out.item2_modal_zero_amount_blocked.request).toBeNull();
    expect(out.item4_batch_newer_exceeds_older.request.entries).toEqual([{ student_class_id: '{{course}}', amount: 3000, invoice_id: '{{invoice:newer}}' }]);

    // One case per line keeps the reviewed diff small and each case's drift on its own line.
    const text = `{\n${Object.entries(out).map(([k, v]) => `  ${JSON.stringify(k)}: ${JSON.stringify(v)}`).join(',\n')}\n}\n`;
    await expect(text).toMatchFileSnapshot(FIXTURE);
  });
});
