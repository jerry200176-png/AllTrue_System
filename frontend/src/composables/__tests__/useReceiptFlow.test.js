import { describe, it, expect, vi } from 'vitest';
import { useReceiptFlow } from '../useReceiptFlow.js';

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

describe('useReceiptFlow', () => {
  it('openReceiptByReport closes the latest-payment modal before opening the receipt (blocker 1)', () => {
    const flow = useReceiptFlow({});
    flow.openLatestPaymentInfo({ id: 1, student_name: '王小明', subject: '英文' });
    expect(flow.latestPaymentOpen.value).toBe(true);

    flow.openReceiptByReport(42);

    expect(flow.latestPaymentOpen.value).toBe(false);
    expect(flow.receiptOpen.value).toBe(true);
    expect(flow.receiptReportId.value).toBe(42);
  });

  it('post-payment: opens the receipt synchronously on result.report_id, before the course refresh resolves (blocker 5)', async () => {
    let resolveRefresh;
    const refreshCourses = vi.fn(() => new Promise((r) => { resolveRefresh = r; }));
    const toast = vi.fn();
    const flow = useReceiptFlow({ refreshCourses, toast });

    flow.openPaymentEntry({ id: 1, student_name: '王小明' }, 99);
    const confirmPromise = flow.onPaymentEntryConfirmed({ report_id: 7 });

    // Receipt must already be open before the (still-pending) refresh resolves.
    expect(flow.receiptOpen.value).toBe(true);
    expect(flow.receiptReportId.value).toBe(7);
    expect(flow.paymentEntryOpen.value).toBe(false);
    expect(toast).toHaveBeenCalledWith('已完成核帳登記');
    expect(refreshCourses).toHaveBeenCalledWith(99);

    resolveRefresh();
    await confirmPromise;
    expect(flow.receiptOpen.value).toBe(true);
  });

  it('a failed course refresh does not close the receipt, does not re-toast as a failure, and does not retry the payment (blocker 5)', async () => {
    const refreshCourses = vi.fn(() => Promise.reject(new Error('network down')));
    const toast = vi.fn();
    const flow = useReceiptFlow({ refreshCourses, toast });

    flow.openPaymentEntry({ id: 1 }, 99);
    await flow.onPaymentEntryConfirmed({ report_id: 7 });

    expect(flow.receiptOpen.value).toBe(true);
    expect(flow.receiptReportId.value).toBe(7);
    expect(refreshCourses).toHaveBeenCalledTimes(1);
    // Only the one success toast — no second "failed" toast, no thrown error.
    expect(toast).toHaveBeenCalledTimes(1);
    expect(toast).toHaveBeenCalledWith('已完成核帳登記');
  });

  it('no report_id (e.g. NT$0 settle with no receipt-eligible report) does not open the receipt', async () => {
    const flow = useReceiptFlow({});
    flow.openPaymentEntry({ id: 1 });
    await flow.onPaymentEntryConfirmed({ message: 'ok' });
    expect(flow.receiptOpen.value).toBe(false);
  });

  it('payment API failure never reaches onPaymentEntryConfirmed, so no navigation happens', () => {
    // PaymentEntryModal only emits 'confirmed' on resp.ok; a failed submit()
    // sets submitError and returns without emitting. Simulate that contract:
    // the flow is simply never invoked, so nothing about its state changes.
    const flow = useReceiptFlow({});
    flow.openPaymentEntry({ id: 1 }, 99);
    // (no call to onPaymentEntryConfirmed — this is the failure path)
    expect(flow.receiptOpen.value).toBe(false);
    expect(flow.paymentEntryOpen.value).toBe(true);
  });

  it('repeated confirm calls for the same result stay idempotent (no duplicate refresh side effects beyond intent)', async () => {
    const refreshCourses = vi.fn(() => Promise.resolve());
    const flow = useReceiptFlow({ refreshCourses, toast: vi.fn() });

    flow.openPaymentEntry({ id: 1 }, 99);
    await flow.onPaymentEntryConfirmed({ report_id: 7 });
    // paymentEntryStudentId is consumed (nulled) after the first confirm, so a
    // stray second call — which the PaymentEntryModal submit-guard should
    // prevent from ever happening — would not trigger a second refresh.
    await flow.onPaymentEntryConfirmed({ report_id: 7 });

    expect(refreshCourses).toHaveBeenCalledTimes(1);
    await flush();
  });
});
