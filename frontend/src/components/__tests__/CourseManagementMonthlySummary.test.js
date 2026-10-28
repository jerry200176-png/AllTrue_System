import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import CourseManagement from '../../pages/CourseManagement.vue';

const fixture = vi.hoisted(() => ({ courses: [] }));

vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'test-token' } } })) } } }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/authedFetch', () => ({ getAccessToken: vi.fn(async () => 'test-token'), authedFetch: vi.fn(async (url) => ({ ok: true, json: async () => ({ data: String(url).startsWith('/api/v1/student-classes?') ? fixture.courses : [] }) })) }));

const row = (id, status, date = '2026-09-01') => ({ id, student_class_id: 700001, session_date: date, start_time: '10:00', end_time: '11:00', status });
const response = (n, last, total, data) => ({ ok: true, status: 200, json: async () => ({ materialized: { data, current_page: n, last_page: last, total } }) });
let wrapper;
let fetcher;
beforeEach(() => {
  fixture.courses = [];
  fetcher = vi.fn(async () => ({ ok: false, status: 503, json: async () => ({}) }));
  vi.stubGlobal('fetch', fetcher);
});
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });
async function openMonthly() {
  wrapper = shallowMount(CourseManagement, { props: { branchId: 7, userRole: 'director' } });
  await flushPromises();
  const state = wrapper.vm.$.setupState;
  state.courses = [{ id: 700001, student_id: 700001, student_name: '測試學生', status: 'active', subject: 'math', ScheduleMode: 'date', payment_type: 'monthly', SessionCount: 4, sessions_purchased: 4, sessions_used: 2, remaining_sessions: 0 }];
  state.expandedStudentGroups = new Set(['sid:700001']);
  state.expandedDates = new Set([700001]);
  await nextTick();
  return state;
}
describe('monthly attendance on the real course page', () => {
  it('shows loading until both pages succeed and then counts actual attendance', async () => {
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    fetcher.mockImplementation(async (url) => {
      if (!String(url).startsWith('/api/v1/class-sessions?')) return { ok: false, status: 404, json: async () => ({}) };
      const n = Number(new URL(url, 'http://test.invalid').searchParams.get('page'));
      return n === 1 ? pending : response(2, 2, 4, [row(3, 'scheduled'), row(4, 'scheduled')]);
    });
    await openMonthly();
    expect(wrapper.find('.dates-panel-title').text()).toContain('出席載入中');
    finish(response(1, 2, 4, [row(1, 'attended'), row(2, 'attended')]));
    await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 2 堂）');
  });
  it('refreshes after local session rows change and labels a failed refresh as stale', async () => {
    let calls = 0;
    fetcher.mockImplementation(async (url) => {
      if (!String(url).startsWith('/api/v1/class-sessions?')) return { ok: false, status: 404, json: async () => ({}) };
      calls++;
      if (calls === 1) return response(1, 1, 2, [row(1, 'attended'), row(2, 'attended')]);
      if (calls === 2) return response(1, 1, 3, [row(1, 'attended'), row(2, 'attended'), row(3, 'completed')]);
      return { ok: false, status: 503 };
    });
    const state = await openMonthly(); await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 2 堂）');
    state.updateLocalSessionRow(700001, { id: 3, date: '2026-09-03', startTime: '10:00', endTime: '11:00', status: 'completed' }); await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 3 堂）');
    state.updateLocalSessionRow(700001, { id: 3, date: '2026-09-03', startTime: '10:00', endTime: '11:00', status: 'cancelled' }); await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toContain('更新失敗（上次已上 3 堂）');
  });
  it('loads a real historical card and refreshes when the user expands its dates', async () => {
    fixture.courses = [{ id: 700001, student_id: 700001, student_name: '測試學生', status: 'inactive', history_reason: 'completed', subject: 'math', ScheduleMode: 'date', payment_type: 'monthly', SessionCount: 4, sessions_purchased: 4, remaining_sessions: 0 }];
    const scopedCalls = [];
    fetcher.mockImplementation(async (url) => {
      const u = new URL(String(url), 'http://test.invalid');
      if (u.pathname === '/api/v1/class-sessions') {
        scopedCalls.push({ page: u.searchParams.get('page'), start: u.searchParams.get('start') });
        const rows = u.searchParams.has('start') ? [row(1, 'attended'), row(2, 'attended')] : [row(1, 'attended'), row(2, 'attended'), row(3, 'completed')];
        return response(1, 1, rows.length, rows);
      }
      return { ok: true, status: 200, json: async () => ({}) };
    });
    wrapper = shallowMount(CourseManagement, { props: { branchId: 7, userRole: 'director' } });
    await flushPromises();
    await wrapper.get('.history-section__toggle').trigger('click');
    await wrapper.get('.history-course-card .btn-toggle').trigger('click');
    await flushPromises();
    expect(wrapper.get('.history-course-card .dates-panel-title').text()).toBe('上課日期（已上 3 堂）');
    expect(scopedCalls.some(call => !!call.start)).toBe(true);
    expect(scopedCalls.some(call => !call.start && call.page === '1')).toBe(true);
    expect(scopedCalls.filter(call => !call.start && call.page === '1').length).toBeLessThanOrEqual(2);
  });
  it('keeps the newest response when an older course refresh finishes late', async () => {
    let finishFirst, calls = 0;
    const first = new Promise(resolve => { finishFirst = resolve; });
    fetcher.mockImplementation(async (url) => {
      if (!String(url).startsWith('/api/v1/class-sessions?')) return { ok: false, status: 404, json: async () => ({}) };
      calls++;
      return calls === 1 ? first : response(1, 1, 1, [row(3, 'attended')]);
    });
    const state = await openMonthly();
    state.sessionsByCourse = { 700001: [] }; await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 1 堂）');
    finishFirst(response(1, 1, 2, [row(1, 'attended'), row(2, 'attended')]));
    await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 1 堂）');
  });
  it('uses the same complete read for the monthly cancellation suffix', async () => {
    fetcher.mockImplementation(async (url) => String(url).startsWith('/api/v1/class-sessions?')
      ? response(1, 1, 2, [row(1, 'attended'), row(2, 'cancelled')])
      : { ok: false, status: 404, json: async () => ({}) });
    await openMonthly(); await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 1 堂，1 堂已取消）');
  });
  it('does not show a previous branch response after switching branches', async () => {
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    fetcher.mockImplementation(async (url) => String(url).startsWith('/api/v1/class-sessions?')
      ? pending : { ok: false, status: 404, json: async () => ({}) });
    await openMonthly();
    await wrapper.setProps({ branchId: 8 }); await flushPromises();
    finish(response(1, 1, 1, [row(1, 'attended')])); await flushPromises();
    expect(wrapper.find('.dates-panel-title').exists()).toBe(false);
  });
  it('ignores an older monthly read when the course changes to count mode', async () => {
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    fetcher.mockImplementation(async (url) => String(url).startsWith('/api/v1/class-sessions?')
      ? pending : { ok: false, status: 404, json: async () => ({}) });
    const state = await openMonthly();
    state.courses = [{ ...state.courses[0], ScheduleMode: 'count', payment_type: 'session', remaining_sessions: 2 }];
    await nextTick();
    expect(wrapper.find('.dates-panel-title').text()).toContain('2 / 購買 4 堂');
    finish(response(1, 1, 1, [row(1, 'attended')]));
    await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toContain('2 / 購買 4 堂');
    expect(wrapper.find('.dates-panel-title').text()).not.toContain('已上 1 堂');
  });
  it('keeps count and shared-package summaries on their existing paths', async () => {
    wrapper = shallowMount(CourseManagement, { props: { branchId: 7, userRole: 'director' } });
    await flushPromises();
    const state = wrapper.vm.$.setupState;
    state.courses = [
      { id: 1, student_id: 7, student_name: '測試學生', status: 'active', subject: 'math', payment_type: 'session', ScheduleMode: 'count', SessionCount: 8, sessions_purchased: 8, remaining_sessions: 6 },
      { id: 2, student_id: 7, student_name: '測試學生', status: 'inactive', history_reason: 'completed', subject: 'math', payment_type: 'session', PackageID: 9, package_total_sessions: 12, package_remaining_sessions: 8 },
    ];
    state.expandedStudentGroups = new Set(['sid:7']); state.expandedHistoryGroups = new Set(['sid:7']);
    state.expandedDates = new Set([1, 2]); await flushPromises();
    const labels = wrapper.findAll('.dates-panel-title').map(node => node.text());
    expect(labels[0]).toContain('2 / 購買 8 堂');
    expect(labels[1]).toBe('上課日期（本科已上 0 堂｜方案共用 12 堂（已用 4 / 剩 8））');
    expect(fetcher.mock.calls.filter(([url]) => String(url).startsWith('/api/v1/class-sessions?'))).toHaveLength(0);
  });
  it('shows unavailable instead of a false zero on read failure and permits retry', async () => {
    await openMonthly();
    await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toContain('出席資料無法載入');
    expect(wrapper.find('.dates-panel-title').text()).not.toContain('已上 0 堂');
    fetcher.mockResolvedValue(response(1, 1, 0, []));
    await wrapper.get('.dates-panel-heading button').trigger('click');
    await flushPromises();
    expect(wrapper.find('.dates-panel-title').text()).toBe('上課日期（已上 0 堂）');
  });
});
