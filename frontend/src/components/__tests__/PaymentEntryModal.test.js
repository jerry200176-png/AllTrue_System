import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';
import { FREE_GIFT_LABEL } from '../../lib/courseMoneyState.js';

const mockToken = 'test-token';

function ok(json) {
  return { ok: true, status: 200, json: () => Promise.resolve(json) };
}
function fail(status, message) {
  return { ok: false, status, json: () => Promise.resolve(message ? { message } : {}) };
}
async function tick() {
  await new Promise((r) => setTimeout(r, 10));
  await nextTick();
}

const row = { id: 5, student_name: '王小明', subject: '英文', charge: 3000, payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000 };
async function submitRow(over) { // open the modal on a queue row (overrides merged in) and submit it as shown
  global.fetch.mockResolvedValue(ok({ report_id: 1 }));
  const w = mount(PaymentEntryModal, { props: { show: false, row: { ...row, ...over } } });
  await w.setProps({ show: true }); await nextTick(); await w.find('form').trigger('submit'); await tick();
  return w;
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('PaymentEntryModal — director-record submission contract', () => {
  it('emits confirmed with the API result on success (this is what report_id flows through)', async () => {
    global.fetch.mockResolvedValueOnce(ok({ message: '已送出待對帳', report_id: 42, payment_id: null, invoice_id: 2, status: 'pending' }));
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    await wrapper.find('form').trigger('submit');
    await tick();

    expect(wrapper.emitted('confirmed')).toEqual([[{ message: '已送出待對帳', report_id: 42, payment_id: null, invoice_id: 2, status: 'pending' }]]);
  });

  it('pays the queue row\'s payable invoice (the bill whose outstanding prefilled the amount)', async () => {
    global.fetch.mockResolvedValue(ok({ report_id: 1 }));
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row: { ...row, payable_invoice_id: 77 } } });
    await wrapper.setProps({ show: true });
    await nextTick();
    await wrapper.find('form').trigger('submit');
    await tick();
    expect(JSON.parse(global.fetch.mock.calls[0][1].body).invoice_id).toBe(77);

  });

  // #3875 item 1: a covered payable bill (outstanding 0) on a charging course never becomes a silent NT$0 report.
  it('blocks a NT$0 report on a course that charges instead of dropping the invoice id', async () => {
    const covered = await submitRow({ payable_invoice_id: 78, payable_outstanding: 0 });
    expect(global.fetch).not.toHaveBeenCalled();
    expect(covered.text()).toContain('金額要大於 0');
    // Typing a real amount sends it against the displayed bill; the backend refuses a covered bill, it never re-targets.
    await covered.find('input[type="number"]').setValue(500);
    await covered.find('form').trigger('submit');
    await tick();
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toMatchObject({ student_class_id: 5, amount: 500, invoice_id: 78 });
  });

  it('sends the payable invoice, not the display invoice, when they differ (#3888 r4245816749)', async () => {
    await submitRow({ invoice_id: 90, payable_invoice_id: 91, payable_outstanding: 4000 });
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toMatchObject({ amount: 4000, invoice_id: 91 });
  });

  // #3991 (Founder 2026-10-13): a missing or 0 fee is never free by itself; NT$0 needs the explicit 免費／贈送 mark.
  it('blocks NT$0 on a no-fee course until it is marked free/gift', async () => {
    const w = await submitRow({ charge: 0, payable_amount: 0, payable_outstanding: 0 });
    expect(global.fetch).not.toHaveBeenCalled();
    expect(w.text()).toContain(FREE_GIFT_LABEL);
    await w.find('[data-testid="pe-free-gift"]').setValue(true);
    // Free/gift mode is a bill, not a payment: no 待核對入帳 step is promised.
    expect(w.find('[data-testid="pe-workflow-hint"]').text()).toContain(FREE_GIFT_LABEL);
    expect(w.find('button[type="submit"]').text()).toContain(FREE_GIFT_LABEL);
    await w.find('form').trigger('submit');
    await tick();
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toMatchObject({ amount: 0, free_gift: true });
  });

  it('never sends the free/gift mark with a positive amount', async () => {
    const w = await submitRow({ charge: 0, payable_amount: 0, payable_outstanding: 0 });
    await w.find('[data-testid="pe-free-gift"]').setValue(true);
    await w.find('input[type="number"]').setValue(500);
    await w.find('form').trigger('submit');
    await tick();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('offers no free/gift mark on a course that charges', async () => {
    const w = mount(PaymentEntryModal, { props: { show: false, row } });
    await w.setProps({ show: true }); await nextTick();
    expect(w.find('[data-testid="pe-free-gift"]').exists()).toBe(false);
  });

  // ── Blocker 5 precondition: a failed payment API call must never emit
  // 'confirmed', so the parent never navigates to a receipt or treats it as success. ──
  it('does not emit confirmed when the API call fails (no navigation on payment failure)', async () => {
    global.fetch.mockResolvedValueOnce(fail(422, '此課程已標記為已繳費，請勿重複入帳'));
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    await wrapper.find('form').trigger('submit');
    await tick();

    expect(wrapper.emitted('confirmed')).toBeUndefined();
    expect(wrapper.text()).toContain('此課程已標記為已繳費');
  });

  it('keeps duplicate reports in the form without notifying navigation consumers', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 422,
      json: () => Promise.resolve({ code: 'pending_report_exists', message: '此課程已有待對帳回報', report_id: 42 }),
    });
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    expect(wrapper.text()).toContain('按「確認入帳」才算已收');
    expect(wrapper.text()).toContain('登記收款');
    await wrapper.find('form').trigger('submit');
    await tick();

    expect(wrapper.emitted('confirmed')).toBeUndefined();
    expect(wrapper.emitted('pending')).toBeUndefined();
    expect(wrapper.text()).toContain('請先「確認入帳」或「退回」');
  });

  it('does not emit confirmed on a network error either', async () => {
    global.fetch.mockRejectedValueOnce(new Error('network down'));
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    await wrapper.find('form').trigger('submit');
    await tick();

    expect(wrapper.emitted('confirmed')).toBeUndefined();
    expect(wrapper.text()).toContain('network down');
  });

  // ── Blocker 6: repeated submit must not fire a second director-record call ──
  it('disables the submit button while a request is in flight, preventing a duplicate director-record call', async () => {
    let resolveFetch;
    global.fetch.mockImplementationOnce(() => new Promise((resolve) => { resolveFetch = resolve; }));
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    const button = wrapper.find('button[type="submit"]');
    expect(button.attributes('disabled')).toBeUndefined();

    await wrapper.find('form').trigger('submit');
    await nextTick();

    // Button must already be disabled before the in-flight request resolves —
    // this is what stops a second click from firing a second POST.
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    expect(global.fetch).toHaveBeenCalledTimes(1);

    resolveFetch(ok({ report_id: 1 }));
    await tick();

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });
});

describe('PaymentEntryModal — monthly month by month (in-app #432)', () => {
  it('an unbilled monthly row shows the month being collected and its amount', async () => {
    const w = mount(PaymentEntryModal, { props: { show: false, row: { ...row, payable_status: 'unbilled', payable_amount: null, billing_period: '2026-08', estimated_amount: 1650 } } });
    await w.setProps({ show: true }); await nextTick();
    expect(w.find('.pe-info').text()).toContain('期別2026-08');
    expect(w.find('.pe-info').text()).toContain('NT$ 1,650');
  });
});
