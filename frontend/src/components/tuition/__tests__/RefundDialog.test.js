import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import RefundDialog from '../RefundDialog.vue';

const mockFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({ authedFetch: (...a) => mockFetch(...a) }));
vi.mock('../../../lib/authedFetch.js', () => ({ authedFetch: (...a) => mockFetch(...a) }));

const res = (body, ok = true) => ({ ok, status: ok ? 200 : 422, json: async () => body });
const preview = { paid: 4000, used_lessons: 3, rate: 500, refundable: 2500 };

async function open() {
  mockFetch.mockResolvedValueOnce(res(preview));
  const w = mount(RefundDialog, { props: { open: true, courseId: 9 }, global: { stubs: { teleport: true } } });
  await flushPromises();
  return w;
}
const t = (w, id) => w.find(`[data-testid="${id}"]`);

beforeEach(() => mockFetch.mockReset());

it('shows the server numbers and suggests the refundable amount', async () => {
  const w = await open();
  expect(t(w, 'refund-paid').text()).toContain('4,000');
  expect(t(w, 'refund-used').text()).toContain('已上 3 堂');
  expect(t(w, 'refund-suggested').text()).toContain('2,500');
  expect(t(w, 'refund-amount').element.value).toBe('2500');
});

it('refuses an amount above the maximum', async () => {
  const w = await open();
  await t(w, 'refund-reason').setValue('搬家');
  await t(w, 'refund-amount').setValue('2501');
  expect(t(w, 'refund-problem').text()).toContain('不能超過');
  expect(t(w, 'refund-submit').attributes('disabled')).toBeDefined();
});

it('requires a reason', async () => {
  const w = await open();
  expect(t(w, 'refund-problem').text()).toContain('原因');
  expect(t(w, 'refund-submit').attributes('disabled')).toBeDefined();
});

it('submits and emits done', async () => {
  const w = await open();
  await t(w, 'refund-reason').setValue('搬家');
  mockFetch.mockResolvedValueOnce(res({ message: 'ok' }));
  await t(w, 'refund-submit').trigger('click');
  await flushPromises();
  const body = JSON.parse(mockFetch.mock.calls[1][1].body);
  expect(body).toMatchObject({ amount: 2500, method: 'cash', reason: '搬家' });
  expect(w.emitted('done')).toHaveLength(1);
});

afterEach(() => vi.useRealTimers());

it('defaults and caps the date at today in Taipei, not UTC', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-04T17:30:00Z')); // Taipei 2026-11-05 01:30
  const w = await open();
  expect(t(w, 'refund-date').element.value).toBe('2026-11-05');
  expect(t(w, 'refund-date').attributes('max')).toBe('2026-11-05');
});
