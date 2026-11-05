// Billing v3 step 3: 儲存並看下一筆未繳 on the payment sheet; plain 登記收款 stays; D19 overpay is refused by the server answer.
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PaymentEntryModal from '../PaymentEntryModal.vue';

const ROW = { id: 7, student_name: '學生甲', subject: '數學', charge: 8800, payable_status: 'invoiced', payable_amount: 8800, payable_outstanding: 3000, payable_invoice_id: 5 };
const json = (status, body) => ({ ok: status < 300, status, json: () => Promise.resolve(body) });
let sent;
const open = async (props = {}, answer = json(200, { report_id: 1, status: 'pending' })) => {
  sent = [];
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 't' }));
  global.fetch = vi.fn((url, init = {}) => { sent.push(JSON.parse(init.body)); return Promise.resolve(answer); });
  const w = mount(PaymentEntryModal, { props: { show: false, row: ROW, placement: 'right', ...props }, global: { stubs: { teleport: true } } });
  await w.setProps({ show: true });
  await nextTick();
  return w;
};

describe('PaymentEntryModal save and next', () => {
  beforeEach(() => { sent = []; });

  it('defaults the amount to what is left and sends it for that contract', async () => {
    const w = await open({ nextOffer: true });
    expect(w.get('input[type="number"]').element.value).toBe('3000');
    await w.get('[data-testid="pe-save-next"]').trigger('click');
    await flushPromises();
    expect(sent[0]).toMatchObject({ student_class_id: 7, amount: 3000, invoice_id: 5 });
    expect(w.emitted('confirmed')[0][0]).toMatchObject({ save_and_next: true });
  });

  it('plain 登記收款 does not ask for the next row', async () => {
    const w = await open({ nextOffer: true });
    await w.get('form').trigger('submit');
    await flushPromises();
    expect(w.emitted('confirmed')[0][0].save_and_next).toBeUndefined();
  });

  it('only offers it when the owner asks for it', async () => {
    const w = await open();
    expect(w.find('[data-testid="pe-save-next"]').exists()).toBe(false);
  });

  it('refuses an overpay (D19): the error shows, nothing moves on', async () => {
    const w = await open({ nextOffer: true }, json(422, { code: 'amount_exceeds_outstanding', message: '超過', expected_amount: 3000 }));
    await w.get('input[type="number"]').setValue(5000);
    await w.get('[data-testid="pe-save-next"]').trigger('click');
    await flushPromises();
    expect(w.get('.pe-error').text()).toContain('超過帳單未收餘額');
    expect(w.emitted('confirmed')).toBeUndefined();
  });
});
