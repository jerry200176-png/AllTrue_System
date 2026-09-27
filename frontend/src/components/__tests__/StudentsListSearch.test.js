import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AtEmpty from '../design-system/AtEmpty.vue';

const { getSession, from } = vi.hoisted(() => ({ getSession: vi.fn(), from: vi.fn() }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession }, from } }));
import StudentsList from '../../pages/StudentsList.vue';

const response = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const student = { id: 313, name: '學校學生', school: '光華國中', grade: 'J1', status: 'active' };

const makeQuery = () => {
  const query = { select: vi.fn(() => query), eq: vi.fn(() => query), ilike: vi.fn(() => query), order: vi.fn(() => Promise.resolve({ data: [], error: null })) };
  return query;
};
const mountStudents = () => shallowMount(StudentsList, { props: { branchId: 11 }, global: { stubs: {
  AtEmpty,
  AtFilterBar: { template: '<div><slot /></div>' },
  AtPageHeader: { template: '<div><slot name="meta" /><slot name="actions" /></div>' },
} } });

describe('StudentsList school search contract (#313)', () => {
  beforeEach(() => getSession.mockResolvedValue({ data: { session: { access_token: 'test-token' } } }));
  beforeEach(() => from.mockImplementation(() => makeQuery()));
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); delete globalThis.fetch; });

  it('labels the search as name or attended school and sends canonical search', async () => {
    const calls = [];
    globalThis.fetch = vi.fn((input) => {
      calls.push(String(input));
      if (String(input).includes('per_page=500')) return Promise.resolve(response({ data: [student], total: 1 }));
      return Promise.resolve(response({ data: [] }));
    });
    const wrapper = mountStudents();
    await flushPromises();
    const input = wrapper.find('#students-name-filter');
    expect(wrapper.find('label[for="students-name-filter"]').text()).toContain('姓名');
    expect(wrapper.find('label[for="students-name-filter"]').text()).toContain('就讀學校');
    expect(input.attributes('placeholder')).toContain('學校');
    vi.useFakeTimers();
    await input.setValue('光華國中');
    await input.trigger('input');
    vi.advanceTimersByTime(300);
    await vi.runAllTimersAsync();
    await flushPromises();
    const listCall = calls.filter((url) => url.includes('per_page=500')).at(-1);
    expect(listCall).toContain('search=%E5%85%89%E8%8F%AF%E5%9C%8B%E4%B8%AD');
    expect(listCall).not.toContain('name=');
    expect(wrapper.text()).toContain('學校學生');
    wrapper.unmount();
  });

  it('uses canonical fallback filters and class id when Laravel is unavailable', async () => {
    let query;
    const eqCalls = [];
    query = { select: vi.fn(() => query), eq: vi.fn((field, value) => { eqCalls.push([field, value]); return query; }), order: vi.fn(() => Promise.resolve({ data: [student], error: null })) };
    from.mockImplementation(() => query);
    globalThis.fetch = vi.fn((input) => String(input).includes('per_page=500')
      ? Promise.resolve(response({}, 503))
      : Promise.resolve(response({ total: 1 })));
    const wrapper = mountStudents();
    await flushPromises();
    const input = wrapper.find('#students-name-filter');
    await input.setValue('光華');
    const grade = wrapper.find('#students-grade-filter');
    await grade.setValue('J1');
    await nextTick();
    await flushPromises();
    expect(eqCalls).toEqual(expect.arrayContaining([
      ['branch_id', 11], ['search', '光華'], ['class_id', 7], ['status', 'active'],
    ]));
    expect(query.order).toHaveBeenCalledWith('name');
    wrapper.unmount();
  });
});
