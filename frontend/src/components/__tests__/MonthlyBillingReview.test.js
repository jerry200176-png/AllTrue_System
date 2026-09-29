import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MonthlyBillingReview from '../MonthlyBillingReview.vue';
const course = { ID: 3304, StudentID: 172, student_name: '月結學生', subject_name: '數學', teacher_name: '老師',
  monthly_payment: { review_required: true, contract_start: '2026-07-27', contract_end: '2026-09-10', registered_paid_amount: 7500,
    session_review: [{ calendar_month: '2026-09', completed_sessions: 4, estimated_charge: 6000, uncovered_sessions: 4, outside_contract_sessions: 2 }] } };
let wrapper;
beforeEach(() => localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' })));
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); });
const response = (data, last_page = 1) => ({ ok: true, json: async () => ({ data, last_page }) });
it('finds missing-invoice courses independently of tuition alerts and navigates with exact identity', async () => {
  const fetch = vi.fn().mockResolvedValue(response([course, { ...course, ID: 10, monthly_payment: { review_required: false } }], 2));
  vi.stubGlobal('fetch', fetch);
  wrapper = mount(MonthlyBillingReview, { props: { branchId: 16 } });
  await flushPromises();
  const url = new URL(fetch.mock.calls[0][0], 'http://fixture');
  expect(url.searchParams.get('schedule_mode')).toBe('date');
  expect(url.searchParams.get('branch_id')).toBe('16');
  expect(wrapper.findAll('tbody tr')).toHaveLength(1);
  expect(wrapper.text()).toContain('2026-09 · 已上 4 堂 · 試算 NT$ 6,000');
  expect(wrapper.text()).toContain('4 堂沒有對應帳單服務期間');
  expect(wrapper.text()).toContain('實收待核對');
  await wrapper.findAll('button').find(b => b.text() === '前往課程核對').trigger('click');
  expect(wrapper.emitted('navigate')[0][0]).toEqual({ target: 'course-mgmt', studentId: 172, courseId: 3304, studentName: '月結學生' });
  await wrapper.findAll('button').find(b => b.text() === '下一頁').trigger('click');
  await flushPromises();
  expect(new URL(fetch.mock.calls[1][0], 'http://fixture').searchParams.get('page')).toBe('2');
  expect(fetch.mock.calls.every(([, options]) => !options.method && !options.body)).toBe(true);
});
it('discards a previous branch response after switching campuses', async () => {
  let finishOld;
  vi.stubGlobal('fetch', vi.fn().mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve; }))
    .mockResolvedValueOnce(response([])));
  wrapper = mount(MonthlyBillingReview, { props: { branchId: 16 } });
  await wrapper.setProps({ branchId: 9 });
  await flushPromises();
  finishOld(response([course]));
  await flushPromises();
  expect(wrapper.text()).not.toContain('月結學生');
  expect(wrapper.text()).toContain('本頁沒有待核對');
});
it('shows an explicit retry on failure instead of an empty successful result', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 503 }));
  wrapper = mount(MonthlyBillingReview);
  await flushPromises();
  expect(wrapper.text()).toContain('載入失敗（503）');
  expect(wrapper.text()).toContain('重新載入');
  expect(wrapper.text()).not.toContain('本頁沒有');
});
