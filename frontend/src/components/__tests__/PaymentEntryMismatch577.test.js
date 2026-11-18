import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

let wrapper;
const row = { id: 5, student_name: '合成學生', subject: '英文', charge: 3000,
  payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000, payable_invoice_id: 77 };
const response = data => ({ ok: true, status: 200, json: async () => data });
async function open(overrides = {}, props = {}) {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token' }));
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ report_id: 7, status: 'pending' })));
  wrapper = mount(PaymentEntryModal, { props: { show: false, row: reactive({ ...row, ...overrides }), ...props } });
  await wrapper.setProps({ show: true });
}
async function submit() { await wrapper.get('form').trigger('submit'); await flushPromises(); }
const amount = value => wrapper.get('input[type="number"]').setValue(value);
const acknowledge = () => wrapper.get('[data-testid="pe-amount-ack"]').setValue(true);
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); localStorage.clear(); });

describe('in-app #577: acknowledge a different receipt amount', () => {
  it('does not send a smaller receipt before the director explicitly checks the amount', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-token' }));
    const fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ report_id: 7 }) });
    vi.stubGlobal('fetch', fetch);
    wrapper = mount(PaymentEntryModal, { props: { show: false, row: {
      id: 5, student_name: '合成學生', subject: '英文', charge: 3000,
      payable_status: 'invoiced', payable_amount: 3000, payable_outstanding: 3000,
    } } });
    await wrapper.setProps({ show: true });
    await wrapper.get('input[type="number"]').setValue(2000);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(fetch).not.toHaveBeenCalled();
    expect(wrapper.get('[data-testid="pe-amount-mismatch"]').text()).toContain('3,000');
  });
});

describe('in-app #577: preserve payment rules and invalidate stale acknowledgement', () => {
  it('keeps equal-amount entry one step, using outstanding rather than the original bill total', async () => {
    await open({ payable_amount: 6000 }); await submit();
    expect(wrapper.find('[data-testid="pe-amount-ack"]').exists()).toBe(false);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ amount: 3000, invoice_id: 77 });
    expect(wrapper.emitted('confirmed')).toEqual([[{ report_id: 7, status: 'pending' }]]);
    expect(wrapper.get('[data-testid="pe-workflow-hint"]').text()).toContain('按「確認入帳」才算已收');
  });

  it.each([2000, 4000])('requires acknowledgement for %i, then sends the actual amount without changing it', async value => {
    await open(); await amount(value); await submit(); expect(fetch).not.toHaveBeenCalled();
    const notice = wrapper.get('[data-testid="pe-amount-mismatch"]').text();
    expect(notice).toContain('3,000'); expect(notice).toContain('1,000');
    if (value < 3000) expect(notice).toContain('剩餘金額仍會列為未繳');
    await acknowledge(); await submit();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ student_class_id: 5, amount: value, invoice_id: 77 });
  });

  it('save-and-next cannot bypass acknowledgement and keeps its existing result flag', async () => {
    await open({}, { nextOffer: true }); await amount(2000);
    await wrapper.get('[data-testid="pe-save-next"]').trigger('click'); await flushPromises();
    expect(fetch).not.toHaveBeenCalled();
    await acknowledge(); await wrapper.get('[data-testid="pe-save-next"]').trigger('click'); await flushPromises();
    expect(wrapper.emitted('confirmed')).toEqual([[{ report_id: 7, status: 'pending', save_and_next: true }]]);
  });

  it('editing the amount invalidates its previous acknowledgement', async () => {
    await open(); await amount(2000); await acknowledge(); await amount(2500); await submit();
    expect(fetch).not.toHaveBeenCalled(); expect(wrapper.get('[data-testid="pe-amount-ack"]').element.checked).toBe(false);
    await acknowledge(); await submit(); expect(JSON.parse(fetch.mock.calls[0][1].body).amount).toBe(2500);
  });

  it.each([{ id: 6 }, { payable_invoice_id: 78 }, { payable_outstanding: 2500 }])('invalidates acknowledgement when target/balance changes: %o', async change => {
    await open(); await amount(2000); await acknowledge();
    await wrapper.setProps({ row: { ...row, ...change } }); await submit();
    expect(fetch).not.toHaveBeenCalled(); expect(wrapper.get('[data-testid="pe-amount-ack"]').element.checked).toBe(false);
  });

  it.each([['id', 6], ['payable_invoice_id', 78], ['payable_outstanding', 2500]])('also invalidates an in-place reactive update to %s', async (key, value) => {
    await open(); await amount(2000); await acknowledge();
    wrapper.props('row')[key] = value; await nextTick(); await submit();
    expect(fetch).not.toHaveBeenCalled(); expect(wrapper.get('[data-testid="pe-amount-ack"]').element.checked).toBe(false);
  });

  it('invalidates the target synchronously, before a submit in the same event turn', async () => {
    await open(); await amount(2000); await acknowledge();
    wrapper.props('row').payable_invoice_id = 78;
    await submit(); expect(fetch).not.toHaveBeenCalled();
  });

  it('requires a new acknowledgement when the same number changes from a bill to an estimate', async () => {
    await open(); await amount(2000); await acknowledge();
    wrapper.props('row').payable_status = 'unbilled';
    await submit(); expect(fetch).not.toHaveBeenCalled();
    expect(wrapper.get('[data-testid="pe-amount-mismatch"]').text()).toContain('估算不是已開帳單');
  });

  it('labels an unbilled estimate honestly and keeps it separate from the actual amount sent', async () => {
    await open({ payable_status: 'unbilled', estimated_amount: 1500, payable_amount: null,
      payable_outstanding: null, payable_invoice_id: null });
    await amount(2000); await submit(); expect(fetch).not.toHaveBeenCalled();
    expect(wrapper.get('[data-testid="pe-amount-mismatch"]').text()).toContain('估算不是已開帳單');
    expect(wrapper.get('[data-testid="pe-amount-mismatch"]').text()).toContain('1,500');
    await acknowledge(); await submit();
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ amount: 2000 });
    expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty('invoice_id');
  });

  it.each([null, undefined, '', false, 'unknown', -1])('does not invent a zero comparison from unknown metadata %s', async unknown => {
    await open({ payable_status: 'unbilled', estimated_amount: unknown, payable_amount: null,
      payable_outstanding: null, payable_invoice_id: null });
    await amount(2000); await submit();
    expect(wrapper.find('[data-testid="pe-amount-ack"]').exists()).toBe(false); expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('protects legacy backfill and preserves its idempotency key and separate mode', async () => {
    await open({}, { mode: 'legacy-backfill' }); await amount(2000); await submit();
    expect(fetch).not.toHaveBeenCalled(); await acknowledge(); await submit();
    const body = JSON.parse(fetch.mock.calls[0][1].body);
    expect(body).toMatchObject({ amount: 2000, invoice_id: 77, legacy_flag_backfill: true });
    expect(body.idempotency_key).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('preserves server over-entry refusal, correction pointer and diagnostics', async () => {
    await open(); fetch.mockResolvedValue({ ok: false, status: 422, json: async () => ({ code: 'amount_exceeds_outstanding',
      expected_amount: 3000, correctable_invoice_id: 77, message: '核對金額' }) });
    await amount(4000); await acknowledge(); await submit();
    expect(wrapper.emitted('confirmed')).toBeUndefined(); expect(wrapper.get('[data-testid="pe-correct-bill"]').exists()).toBe(true);
    expect(wrapper.find('.pe-error').text()).toContain('不要為了送出而改小實收金額'); expect(wrapper.emitted('conflict')).toHaveLength(1);
  });

  it('does not duplicate an acknowledged submission while its request is in flight', async () => {
    await open(); await amount(2000); await acknowledge(); let finish;
    fetch.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    await wrapper.get('form').trigger('submit'); await wrapper.get('form').trigger('submit'); expect(fetch).toHaveBeenCalledTimes(1);
    finish(response({ report_id: 7, status: 'pending' })); await flushPromises(); expect(wrapper.emitted('confirmed')).toHaveLength(1);
  });

  it('does not carry acknowledgement into a reopened form or a different mode', async () => {
    await open(); await amount(2000); await acknowledge();
    await wrapper.setProps({ mode: 'legacy-backfill' }); await submit(); expect(fetch).not.toHaveBeenCalled();
    await wrapper.setProps({ show: false }); await wrapper.setProps({ show: true });
    await amount(2000); await submit(); expect(fetch).not.toHaveBeenCalled();
  });

  it('reopening an estimated legacy form invalidates acknowledgement even when its prefilled amount stays the same', async () => {
    await open({ payable_status: 'unbilled', estimated_amount: 1500, payable_amount: null,
      payable_outstanding: null, payable_invoice_id: null }, { mode: 'legacy-backfill' });
    await acknowledge(); await wrapper.setProps({ show: false }); await wrapper.setProps({ show: true }); await submit();
    expect(fetch).not.toHaveBeenCalled(); expect(wrapper.get('[data-testid="pe-amount-ack"]').element.checked).toBe(false);
  });
});
