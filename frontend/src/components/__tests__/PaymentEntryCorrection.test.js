// Founder D3/B1 (#4132): 登記收款 can settle a new bill that corrects a voided one. The form sends the voided bill,
// a reason and one idempotency key per opened form, so a retry never issues a second bill.
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

const ok = (json) => ({ ok: true, status: 200, json: () => Promise.resolve(json) });
const fail = (status, message) => ({ ok: false, status, json: () => Promise.resolve({ message }) });
const tick = async () => { await new Promise((r) => setTimeout(r, 10)); await nextTick(); };
const row = {
  id: 5, student_name: '王小明', subject: '英文', charge: 7200, payable_status: 'unbilled', payable_amount: null,
  voided_invoices: [{ id: 41, label: 'INV-202609-000041', amount: 8800 }, { id: 40, label: 'INV-202608-000040', amount: 7200 }],
};
const bodyOf = (call) => JSON.parse(call[1].body);

async function open(over = {}) {
  const w = mount(PaymentEntryModal, { props: { show: false, row: { ...row, ...over } } });
  await w.setProps({ show: true });
  await nextTick();
  return w;
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  global.fetch = vi.fn();
});

describe('PaymentEntryModal correction of a voided bill', () => {
  it('sends the voided bill, the reason and a key, and a retry after an error reuses the same key', async () => {
    const w = await open();
    await w.find('input[type="number"]').setValue(7200);
    await w.find('[data-testid="pe-correction-toggle"]').setValue(true);
    expect(w.find('[data-testid="pe-correction-invoice"]').element.value).toBe('41');
    await w.find('[data-testid="pe-correction-reason"]').setValue('原約定 7200');
    global.fetch.mockResolvedValueOnce(fail(500, '連線失敗'));
    await w.find('form').trigger('submit');
    await tick();
    global.fetch.mockResolvedValueOnce(ok({ report_id: 9, invoice_id: 50, status: 'pending' }));
    await w.find('form').trigger('submit');
    await tick();
    const [first, second] = global.fetch.mock.calls.map(bodyOf);
    expect(first).toMatchObject({ student_class_id: 5, amount: 7200, replaces_invoice_id: 41, correction_reason: '原約定 7200' });
    expect(first.invoice_id).toBeUndefined();
    expect(first.idempotency_key).toMatch(/^[0-9a-f-]{36}$/);
    expect(second.idempotency_key).toBe(first.idempotency_key);
    expect(w.emitted('confirmed')?.[0]?.[0]).toMatchObject({ invoice_id: 50 });
  });

  it('a newly opened form gets a new key', async () => {
    const w = await open();
    const keys = [];
    for (let i = 0; i < 2; i += 1) {
      await w.find('input[type="number"]').setValue(7200);
      await w.find('[data-testid="pe-correction-toggle"]').setValue(true);
      await w.find('[data-testid="pe-correction-reason"]').setValue('原約定 7200');
      global.fetch.mockResolvedValueOnce(ok({ report_id: 9 }));
      await w.find('form').trigger('submit');
      await tick();
      keys.push(bodyOf(global.fetch.mock.calls[i]).idempotency_key);
      await w.setProps({ show: false });
      await w.setProps({ show: true });
      await nextTick();
    }
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('requires a reason, and is not offered without voided bills', async () => {
    const w = await open();
    await w.find('input[type="number"]').setValue(7200);
    await w.find('[data-testid="pe-correction-toggle"]').setValue(true);
    await w.find('form').trigger('submit');
    await tick();
    expect(global.fetch).not.toHaveBeenCalled();
    expect(w.text()).toContain('請選擇要更正的帳單，並填寫更正原因');
    const plain = await open({ voided_invoices: [] });
    expect(plain.find('[data-testid="pe-correction"]').exists()).toBe(false);
  });
});
