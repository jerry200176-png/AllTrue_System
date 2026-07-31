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
      { id: 1, paid_at: '2026-06-01', amount: 3000, method: 'cash', note: '舊', is_void: false, report_id: 10, status: 'confirmed', account_last5: null },
      { id: 3, paid_at: '2026-07-01', amount: 5000, method: 'cash', note: '同日較新id', is_void: false, report_id: 12, status: 'confirmed', account_last5: null },
      { id: 2, paid_at: '2026-07-01', amount: 4000, method: 'cash', note: '同日較舊id', is_void: false, report_id: 11, status: 'confirmed', account_last5: null },
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('同日較新id');
    expect(wrapper.text()).toContain('NT$ 5,000');
  });

  it('excludes voided payments from the latest-payment computation', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      { id: 1, paid_at: '2026-07-01', amount: 3000, method: 'cash', note: '有效', is_void: false, report_id: 10, status: 'confirmed', account_last5: null },
      { id: 2, paid_at: '2026-07-15', amount: -3000, method: 'void', note: '作廢', is_void: true, report_id: null, status: null, account_last5: null },
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).toContain('有效');
    expect(wrapper.text()).not.toContain('作廢');
  });

  it('shows a receipt entry point for a confirmed payment with a report_id', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      { id: 1, paid_at: '2026-07-01', amount: 3000, method: 'transfer', note: '匯款', is_void: false, report_id: 10, status: 'confirmed', account_last5: '45688' },
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
      { id: 1, paid_at: '2026-07-01', amount: 3000, method: 'cash', note: '舊資料', is_void: false, report_id: null, status: null, account_last5: null },
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).not.toContain('查看收據');
    expect(wrapper.text()).toContain('此筆繳費無電子收據記錄');
    expect(wrapper.find('button.primary').exists()).toBe(false);
  });

  it('does not offer a receipt entry point while a report is only pending confirmation', async () => {
    global.fetch.mockResolvedValueOnce(ok(invoicesResponse([
      { id: 1, paid_at: '2026-07-01', amount: 3000, method: 'cash', note: '待核帳', is_void: false, report_id: 10, status: 'pending', account_last5: null },
    ])));
    const wrapper = mount(LatestPaymentInfoModal, { props: { show: true, course } });
    await tick();
    expect(wrapper.text()).not.toContain('查看收據');
    expect(wrapper.text()).toContain('尚未核帳確認');
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
        { id: 1, paid_at: '2026-07-01', amount: 3000, method: 'cash', note: '新繳費', is_void: false, report_id: 10, status: 'confirmed', account_last5: null },
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
});
