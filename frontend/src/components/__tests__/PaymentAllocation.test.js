// Billing redesign PRD v2 D18/D19 (Founder GO 2026-10-08, #3797): one amount box per contract,
// oldest filled first, blocked above what is owed, per-contract result (the batch is not all-or-nothing).
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const authedFetch = vi.fn();
vi.mock('../../lib/authedFetch.js', () => ({ authedFetch: (...a) => authedFetch(...a) }));
import PaymentAllocation from '../tuition/PaymentAllocation.vue';

const respond = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const CONTRACTS = [
  { id: 2, subject: '數學', start_date: '2026-09-01', end_date: '2026-12-31', owed: 8000, invoice_id: 11 },
  { id: 3, subject: '英文', start_date: '2026-10-01', end_date: '2026-10-31', owed: 4800, invoice_id: 12 },
  { id: 4, subject: '國文', start_date: '2026-10-01', end_date: '2026-12-31', owed: 0, invoice_id: null },
];
const mountIt = (contracts = CONTRACTS) => mount(PaymentAllocation, { props: { studentName: '王小明', contracts } });
const amount = (w, id) => w.find(`[data-testid="alloc-amount-${id}"]`);

beforeEach(() => authedFetch.mockReset());

describe('PaymentAllocation', () => {
  it('defaults the oldest contract to its full balance and the others to nothing', () => {
    const w = mountIt();
    expect(amount(w, 2).element.value).toBe('8000');
    expect(amount(w, 3).element.value).toBe('');
    expect(w.find('[data-testid="alloc-sum"]').text()).toContain('NT$ 8,000');
    expect(w.find('[data-testid="alloc-row-4"]').text()).toContain('還沒開帳單');
    expect(w.text()).not.toMatch(/待查帳|對帳/);
  });

  it('blocks an amount above what is owed and says how to fix it (D19)', async () => {
    const w = mountIt();
    await amount(w, 3).setValue('5000');
    expect(w.find('[data-testid="alloc-error-3"]').text()).toBe('超過這份合約未繳的 NT$ 4,800，請改成 4,800 以下');
    expect(w.find('[data-testid="alloc-submit"]').attributes('disabled')).toBeDefined();
    await amount(w, 3).setValue('4800');
    expect(w.find('[data-testid="alloc-error-3"]').exists()).toBe(false);
    expect(w.find('[data-testid="alloc-submit"]').attributes('disabled')).toBeUndefined();
  });

  it('redistributes 實收總額 oldest-first and refuses more than the combined balance', async () => {
    const w = mountIt();
    await w.find('[data-testid="alloc-total"]').setValue('10000');
    expect([amount(w, 2).element.value, amount(w, 3).element.value]).toEqual(['8000', '2000']);
    await w.find('[data-testid="alloc-total"]').setValue('13000');
    expect(w.find('[data-testid="alloc-total-error"]').text()).toBe('超過全部合約未繳的合計 NT$ 12,800，請改成 12,800 以下');
    expect(w.find('[data-testid="alloc-submit"]').attributes('disabled')).toBeDefined();
  });

  it('sends one director-record-batch with only the contracts that have an amount, oldest invoices attached', async () => {
    authedFetch.mockResolvedValueOnce(respond({ results: [
      { student_class_id: 2, http_status: 201 }, { student_class_id: 3, http_status: 201 },
    ] }));
    const w = mountIt();
    await amount(w, 3).setValue('2000');
    await w.find('[data-testid="alloc-last5"]').setValue('12345');
    await w.find('[data-testid="alloc-note"]').setValue(' 現金一起收 ');
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(authedFetch).toHaveBeenCalledTimes(1);
    const [url, init] = authedFetch.mock.calls[0];
    expect(url).toBe('/api/v1/payment-reports/director-record-batch');
    const body = JSON.parse(init.body);
    expect(body.payment_method).toBe('transfer');
    expect(body.note).toBe('現金一起收');
    expect(body.entries).toEqual([
      { student_class_id: 2, amount: 8000, invoice_id: 11, account_last5: '12345' },
      { student_class_id: 3, amount: 2000, invoice_id: 12, account_last5: '12345' },
    ]);
    expect(w.find('[data-testid="alloc-verdict"]').text()).toBe('已登記 2 份合約，等你確認。');
    await w.find('[data-testid="alloc-done"]').trigger('click');
    expect(w.emitted('done')).toHaveLength(1);
  });

  it('shows per-contract results for a partial batch and retries only the failed rows', async () => {
    authedFetch
      .mockResolvedValueOnce(respond({ results: [
        { student_class_id: 2, http_status: 201 },
        { student_class_id: 3, http_status: 422, code: 'amount_exceeds_outstanding', message: '金額超過此課程未繳餘額 NT$1800，請重新核對後再登記。' },
      ] }, 207))
      .mockResolvedValueOnce(respond({ results: [{ student_class_id: 3, http_status: 201 }] }));
    const w = mountIt();
    await amount(w, 3).setValue('2000');
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(w.find('[data-testid="alloc-verdict"]').text()).toContain('只有部分登記成功');
    expect(w.find('[data-testid="alloc-result-2"]').text()).toBe('數學 已登記 NT$ 8,000');
    expect(w.find('[data-testid="alloc-result-3"]').text()).toContain('英文 沒有登記：');
    await w.find('[data-testid="alloc-retry"]').trigger('click');
    await flushPromises();
    const retry = JSON.parse(authedFetch.mock.calls[1][1].body);
    expect(retry.entries.map((e) => e.student_class_id)).toEqual([3]);
    expect(w.find('[data-testid="alloc-verdict"]').text()).toBe('已登記 2 份合約，等你確認。');
  });

  it('never claims success when nothing was recorded, and keeps the form on a request error', async () => {
    authedFetch.mockResolvedValueOnce(respond({ results: [{ student_class_id: 2, http_status: 409, code: 'pending_report_exists', message: 'x' }] }, 207));
    const w = mountIt([CONTRACTS[0]]);
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(w.find('[data-testid="alloc-verdict"]').text()).toBe('都沒有登記成功，沒有任何金額被記下。');
    expect(w.find('[data-testid="alloc-result-2"]').text()).toContain('請先確認或退回');
    expect(w.find('[data-testid="alloc-done"]').exists()).toBe(false);
    await w.find('[data-testid="alloc-edit"]').trigger('click');
    expect(w.find('form').exists()).toBe(true);

    authedFetch.mockResolvedValueOnce(respond({ message: '付款日期不可晚於今天' }, 422));
    await w.find('form').trigger('submit');
    await flushPromises();
    expect(w.find('[data-testid="alloc-submit-error"]').exists()).toBe(true);
    expect(w.find('form').exists()).toBe(true);
  });
});
