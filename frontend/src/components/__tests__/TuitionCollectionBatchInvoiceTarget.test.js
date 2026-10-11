import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TuitionCollectionPage from '../../pages/TuitionCollectionPage.vue';

// #3875 item 4 (frontend half): mount the real page, select the queue row, go through the batch preview and capture the
// director-record-batch request it actually sends. Backend half (same row shape → intake → confirm → ledger):
// backend/tests/Feature/Billing/CollectionQueueRequestShapeTest.php test_item4_*.

// Two historical open bills, no current-period match: the queue (BillingPayableResolver) shows the NEWER one (id 902),
// while the backend fallback without an invoice id takes the OLDER one (id 901).
const queueRow = (over = {}) => ({
  id: 41, student_id: 7, student_name: '測試生', subject: '數學', schedule_mode: 'count', alert_type: 'unpaid',
  payment_status: 'unpaid', charge: 4000, outstanding: 4000, remaining_sessions: 5,
  payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000, payable_invoice_id: 902,
  ...over,
});

function json(status, body) {
  return { ok: status < 300, status, json: () => Promise.resolve(body) };
}

let batchBodies;
function routeFetch(rows) {
  global.fetch = vi.fn((url, init = {}) => {
    const u = String(url);
    if (u.includes('/api/v1/alerts/tuition')) return Promise.resolve(json(200, rows));
    if (u.includes('/api/v1/payment-reports/director-record-batch')) {
      batchBodies.push(JSON.parse(init.body));
      return Promise.resolve(json(200, { message: '已送出 1 / 1 筆，等你確認', accepted: 1, results: [] }));
    }
    return Promise.resolve(json(200, []));
  });
}

async function sendBatch(rows) {
  routeFetch(rows);
  const wrapper = mount(TuitionCollectionPage, {
    props: { branchId: 1, initialTab: 'unpaid' },
    global: { stubs: { MonthlyBillingReview: true, MonthlyDraftsPanel: true, PaymentSlipModal: true, ReceiptModal: true,
      AccountingLedgerModal: true, StudentBillingList: true, OperationsQuickStart: true, teleport: true } },
  });
  await flushPromises();
  for (const box of wrapper.findAll('input[type="checkbox"][aria-label^="選取"]')) {
    await box.setValue(true);
  }
  await wrapper.find('select').setValue('cash');
  const open = wrapper.findAll('button').find((b) => b.text().includes('送出已回報'));
  expect(open, 'batch report button').toBeTruthy();
  await open.trigger('click');
  await flushPromises();
  const send = wrapper.findAll('button').find((b) => b.text().includes('確認送出回報'));
  expect(send, 'preview confirm button').toBeTruthy();
  await send.trigger('click');
  await flushPromises();
  wrapper.unmount();
  return batchBodies;
}

beforeEach(() => {
  batchBodies = [];
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
});

describe('TuitionCollectionPage batch report — real request carries the row\'s target invoice (#3875)', () => {
  it('sends the displayed bill (newer, NT$3000) so the fallback cannot cap it at the older bill and 207', async () => {
    const [body] = await sendBatch([queueRow()]);
    expect(body.entries).toEqual([{ student_class_id: 41, amount: 3000, invoice_id: 902 }]);
  });

  it('sends the displayed bill when the smaller amount (NT$800) would also fit the older bill', async () => {
    const [body] = await sendBatch([queueRow({ payable_amount: 800, payable_outstanding: 800, outstanding: 1800 })]);
    expect(body.entries).toEqual([{ student_class_id: 41, amount: 800, invoice_id: 902 }]);
  });

  it('omits invoice_id only for a row with no bill', async () => {
    const [body] = await sendBatch([queueRow({ payable_status: 'invoiced', payable_invoice_id: null })]);
    expect(body.entries[0]).not.toHaveProperty('invoice_id');
  });
});
