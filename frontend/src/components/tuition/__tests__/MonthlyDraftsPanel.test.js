import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MonthlyDraftsPanel from '../MonthlyDraftsPanel.vue';

const mockFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  getAccessToken: async () => 'tok',
  authedFetch: (...a) => mockFetch(...a),
}));

const row = (id, over = {}) => ({
  student_class_id: id, student_name: `學生${id}`, subject: '數學', current_end_date: '2026-10-01',
  proposed_start_date: '2026-10-02', proposed_end_date: '2099-11-01', period_sessions: 4, amount: 4000,
  due_date: '2026-10-10', status: 'ready', blocker_code: null, blocker_message: null, ...over,
});
const data = [
  row(1), row(2), row(3, { proposed_end_date: '2020-01-01' }),
  row(4, { status: 'blocked', blocker_message: '有 3 堂已上課沒有帳單' }),
  row(5, { status: 'lapsed_no_lessons' }),
];
const res = (body, ok = true, status = 200) => ({ ok, status, json: async () => body });
let wrapper;
const mountIt = () => mount(MonthlyDraftsPanel, { global: { stubs: { teleport: true } } });
const btn = (t) => wrapper.findAll('button').find((b) => b.text().includes(t));

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockImplementation(async (url) => (String(url).includes('monthly-drafts') ? res({ data }) : res({})));
});
afterEach(() => wrapper?.unmount());

it('renders three sections', async () => {
  wrapper = mountIt();
  await flushPromises();
  const t = wrapper.text();
  expect(t).toContain('可開立（3）');
  expect(t).toContain('有 3 堂已上課沒有帳單');
  expect(t).toContain('請聯絡總部補開');
  expect(t).toContain('請到課程管理結案');
});

it('batch confirm renews each selected row with proposed_end_date; failure shows message', async () => {
  mockFetch.mockImplementation(async (url, opts) => {
    if (String(url).includes('monthly-drafts')) return res({ data });
    if (String(url).includes('/student-classes/2/')) return res({ message: '已有重複續約' }, false, 409);
    return res({});
  });
  wrapper = mountIt();
  await flushPromises();
  await wrapper.find('thead input[type=checkbox]').setValue(true);
  expect(btn('確認開立 2 筆（共 NT$ 8,000）')).toBeTruthy();
  await btn('確認開立 2 筆').trigger('click');
  await btn('確定開立').trigger('click');
  await flushPromises();
  const renews = mockFetch.mock.calls.filter(([u]) => String(u).includes('renew-monthly'));
  expect(renews.map(([u]) => u)).toEqual([
    '/api/v1/student-classes/1/renew-monthly', '/api/v1/student-classes/2/renew-monthly',
  ]);
  expect(JSON.parse(renews[0][1].body)).toEqual({ end_date: '2099-11-01' });
  expect(wrapper.text()).toContain('已有重複續約');
});

it('disables confirm for a past proposed end date', async () => {
  wrapper = mountIt();
  await flushPromises();
  const past = wrapper.findAll('tbody tr')[2];
  expect(past.find('input[type=checkbox]').attributes('disabled')).toBeDefined();
  expect(past.text()).toContain('無法在此開立');
  expect(past.findAll('button')).toHaveLength(0);
});
