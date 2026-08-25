import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

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

const row = { id: 5, student_name: '王小明', subject: '英文', charge: 3000 };

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

  it('explains pending accounting and routes duplicate reports to the pending flow', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 422,
      json: () => Promise.resolve({ code: 'pending_report_exists', message: '此課程已有待對帳回報', report_id: 42 }),
    });
    const wrapper = mount(PaymentEntryModal, { props: { show: false, row } });
    await wrapper.setProps({ show: true });
    await nextTick();

    expect(wrapper.text()).toContain('現金也不會立刻變成已繳費');
    expect(wrapper.text()).toContain('送出待對帳回報');
    await wrapper.find('form').trigger('submit');
    await tick();

    expect(wrapper.emitted('confirmed')).toBeUndefined();
    expect(wrapper.emitted('pending')).toEqual([[
      { code: 'pending_report_exists', message: '此課程已有待對帳回報', report_id: 42 },
    ]]);
    expect(wrapper.text()).toContain('不要重複送出');
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
