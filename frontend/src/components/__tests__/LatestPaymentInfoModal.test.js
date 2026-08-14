import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import LatestPaymentInfoModal from '../LatestPaymentInfoModal.vue';

const mockToken = 'test-token';

function ok(json) {
  return { ok: true, status: 200, json: () => Promise.resolve(json) };
}
function fail(status, message) {
  return { ok: false, status, json: () => Promise.resolve(message ? { message } : {}) };
}
async function tick() {
  await new Promise((r) => setTimeout(r, 30));
  await nextTick();
}

const course = { id: 42, student_name: '王小明', subject: '英文' };

function invoicesResponse(payments) {
  return { invoices: [{ id: 1, payments }] };
}

function payment(overrides = {}) {
  return {
    id: 1,
    paid_at: '2026-07-01',
    amount: 3000,
    method: 'cash',
    note: '',
    is_void: false,
    report_id: null,
    status: null,
    account_last5: null,
    can_view_receipt: false,
    ...overrides,
  };
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('LatestPaymentInfoModal — student page authoritative payment summary', () => {
  it('renders nothing and fetches nothing when show is false', () => {
    mount(LatestPaymentInfoModal, { props: { show: false, course } });
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('fetches the existing invoices endpoint only (read-only, never creates a receipt)', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([])));
    mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/student-classes/42/invoices');
    expect(global.fetch.mock.calls[0][1].method).toBeUndefined(); // default GET, no POST/PUT
  });

  it('shows empty state when the course has no payment records', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('尚無繳費紀錄');
  });

  it('picks the latest payment by paid_at desc with id as a stable tie-breaker', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, paid_at: '2026-06-01', amount: 3000, note: '舊', report_id: 10, status: 'confirmed' }),
      payment({ id: 3, paid_at: '2026-07-01', amount: 5000, note: '同日較新id', report_id: 12, status: 'confirmed' }),
      payment({ id: 2, paid_at: '2026-07-01', amount: 4000, note: '同日較舊id', report_id: 11, status: 'confirmed' }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('同日較新id');
    expect(wrapper.text()).toContain('NT$ 5,000');
  });

  it('excludes voided-correction payments (is_void) from the latest-payment computation', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, paid_at: '2026-07-01', amount: 3000, note: '有效', report_id: 10, status: 'confirmed' }),
      payment({ id: 2, paid_at: '2026-07-15', amount: -3000, method: 'void', note: '作廢副記錄', is_void: true, report_id: null, status: null }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('有效');
    expect(wrapper.text()).not.toContain('作廢副記錄');
  });

  it('shows a receipt entry point for a confirmed payment the caller may view', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, method: 'transfer', note: '匯款', report_id: 10, status: 'confirmed', account_last5: '45688', can_view_receipt: true }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('查看收據');
    expect(wrapper.text()).toContain('45688');

    await wrapper.find('button.primary').trigger('click');
    expect(wrapper.emitted('view-receipt')).toEqual([[10]]);
  });

  it('does not offer a receipt entry point when no report_id exists (no fabricated receipt)', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, note: '舊資料', report_id: null, status: null }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).not.toContain('查看收據');
    expect(wrapper.text()).toContain('此筆繳費無電子收據記錄');
    expect(wrapper.find('button.primary').exists()).toBe(false);
  });

  it('does not offer a receipt entry point while a report is only pending confirmation', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, note: '待核帳', report_id: 10, status: 'pending', can_view_receipt: true }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).not.toContain('查看收據');
    expect(wrapper.text()).toContain('尚未核帳確認');
  });

  // ── Blocker 4: status copy must be correct per status, voided must not say "pending" ──
  it('shows "已作廢" copy for a voided report, never the pending-confirmation text', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, note: '曾核帳後作廢', report_id: 10, status: 'voided', can_view_receipt: true }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.find('.lpi-status-chip').text()).toBe('已作廢');
    expect(wrapper.text()).not.toContain('尚未核帳確認');
    expect(wrapper.text()).toContain('此筆繳費已作廢，無法查看收據');
    expect(wrapper.find('button.primary').exists()).toBe(false);
  });

  it('shows "已退回" copy for a rejected report and no receipt CTA', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, note: '被退回', report_id: 10, status: 'rejected', can_view_receipt: false }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.find('.lpi-status-chip').text()).toBe('已退回');
    expect(wrapper.text()).toContain('此筆繳費已退回，無收據');
    expect(wrapper.find('button.primary').exists()).toBe(false);
  });

  it('shows "已收款" copy and no receipt CTA for a payment with no linked report at all (null status)', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, note: '無收據關聯的舊付款', report_id: null, status: null }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.find('.lpi-status-chip').text()).toBe('已收款');
    expect(wrapper.find('button.primary').exists()).toBe(false);
  });

  // ── Blocker 2: authoritative can_view_receipt, never inferred from account_last5 ──
  it('teacher without receipt permission (can_view_receipt=false) gets no clickable CTA and no receipt request is ever triggered', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, method: 'transfer', note: '匯款', report_id: 10, status: 'confirmed', account_last5: null, can_view_receipt: false }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();

    expect(wrapper.find('button.primary').exists()).toBe(false);
    expect(wrapper.text()).toContain('您沒有查看收據的權限');
    // No CTA exists to click, so 'view-receipt' can never be emitted — the
    // parent never sets a receiptReportId, so ReceiptModal's GET
    // /payment-reports/{id}/receipt is never reached from this component.
    expect(wrapper.emitted('view-receipt')).toBeUndefined();
    // Only the one invoices() call — no separate receipt-permission probe.
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('does not use account_last5 presence/absence to decide the receipt CTA', async () => {
    // A confirmed, permitted payment with no account_last5 (e.g. cash) must
    // still show the CTA — proving the gate is can_view_receipt, not account_last5.
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      payment({ id: 1, method: 'cash', report_id: 10, status: 'confirmed', account_last5: null, can_view_receipt: true }),
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.find('button.primary').exists()).toBe(true);
  });

  it('shows a permission fallback message on 403 instead of bypassing or crashing', async () => {
    global.fetch.mockResolvedValueOnce(fail(403));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('沒有權限查看');
    expect(wrapper.find('.lpi-body').exists()).toBe(false);
  });

  it('re-opening the modal for the same course refetches (no stale cache) but issues one GET per open', async () => {
    global.fetch
      .mockResolvedValueOnce(ok(invoicesResponse([])))
      .mockResolvedValueOnce(ok(invoicesResponse([
        payment({ id: 1, note: '新繳費', report_id: 10, status: 'confirmed' }),
      ])));

    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('尚無繳費紀錄');

    await wrapper.setProps({ show: false });
    await wrapper.setProps({ show: true });
    await tick();

    expect(global.fetch).toHaveBeenCalledTimes(2);
    expect(wrapper.text()).toContain('新繳費');
  });

  // ── Blocker 3: stale response must never overwrite the current course ──
  it('switching from course A to course B discards a late-arriving response for A', async () => {
    let resolveA;
    let resolveB;
    global.fetch.mockImplementationOnce(() => new Promise((resolve) => { resolveA = resolve; }));

    const wrapper = mount(LatestPaymentInfoModal, {
      props: { show: true, course: { id: 1, student_name: 'A' } },
    });
    await tick();

    global.fetch.mockImplementationOnce(() => new Promise((resolve) => { resolveB = resolve; }));
    await wrapper.setProps({ course: { id: 2, student_name: 'B' } });
    await tick();

    // B resolves first (it's the current course)...
    resolveB(ok(invoicesResponse([
      payment({ id: 1, note: 'B的最新繳費', report_id: 20, status: 'confirmed' }),
    ])));
    await tick();
    expect(wrapper.text()).toContain('B的最新繳費');

    // ...then A's slow response finally arrives. It must not overwrite B's data.
    resolveA(ok(invoicesResponse([
      payment({ id: 1, note: 'A的過期繳費', amount: 999999, report_id: 21, status: 'confirmed' }),
    ])));
    await tick();

    expect(wrapper.text()).toContain('B的最新繳費');
    expect(wrapper.text()).not.toContain('A的過期繳費');
  });

  it('switching from course A to course B while A is still pending shows loading/B state, not a stuck spinner from A', async () => {
    let resolveA;
    global.fetch.mockImplementationOnce(() => new Promise((resolve) => { resolveA = resolve; }));
    const wrapper = mount(LatestPaymentInfoModal, {
      props: { show: true, course: { id: 1, student_name: 'A' } },
    });
    await tick();
    expect(wrapper.find('.lpi-loading').exists()).toBe(true); // A's request is still in flight

    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([])));
    await wrapper.setProps({ course: { id: 2, student_name: 'B' } });
    await tick();

    expect(wrapper.text()).toContain('尚無繳費紀錄');

    // A's very late response must not flip the view back into a loading/error state.
    resolveA(fail(500));
    await tick();
    expect(wrapper.text()).toContain('尚無繳費紀錄');
    expect(wrapper.find('.lpi-error').exists()).toBe(false);
  });
});
