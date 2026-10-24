import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import AtEmpty from '../design-system/AtEmpty.vue';

const { getSession, from } = vi.hoisted(() => ({ getSession: vi.fn(), from: vi.fn() }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession }, from } }));
import StudentsList from '../../pages/StudentsList.vue';

const response = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const students = (count, offset = 0) => Array.from({ length: count }, (_, index) => ({
  id: offset + index + 1, name: `學生${offset + index + 1}`, grade: 'J1', status: 'active', RFID: `card-${offset + index + 1}`,
}));
const query = (data) => {
  const result = { select: vi.fn(() => result), eq: vi.fn(() => result), order: vi.fn(() => Promise.resolve({ data, error: null })) };
  return result;
};
const mountStudents = () => shallowMount(StudentsList, { props: { branchId: 11 }, global: { stubs: {
  AtEmpty, AtFilterBar: { template: '<div><slot /></div>' },
} } });

describe('StudentsList complete paginated loading (#4490)', () => {
  let wrapper;
  beforeEach(() => {
    vi.clearAllMocks();
    getSession.mockResolvedValue({ data: { session: { access_token: 'synthetic-token' } } });
    from.mockImplementation(() => query(students(1, 9000)));
    vi.spyOn(console, 'warn').mockImplementation(() => {});
  });
  afterEach(() => { wrapper?.unmount(); vi.useRealTimers(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

  it.each([0, 1, 500, 501, 1001])('loads every matching student for %i rows with bounded, authenticated pages', async (count) => {
    const fixture = students(count);
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (input, init) => {
      const url = new URL(String(input), 'https://fixture.invalid');
      if (url.pathname === '/api/v1/students') {
        if (url.searchParams.get('per_page') === '1') return response({ total: count });
        calls.push({ url, headers: new Headers(init.headers) });
        const page = Number(url.searchParams.get('page') || 1);
        return response({ data: fixture.slice((page - 1) * 500, page * 500), total: count, last_page: Math.max(1, Math.ceil(count / 500)) });
      }
      return response({ data: [] });
    }));
    wrapper = mountStudents();
    await flushPromises();
    const rows = wrapper.findAll('.student-row');
    expect(rows).toHaveLength(count);
    if (count) expect(rows.at(-1).text()).toContain(`學生${count}`);
    expect(calls).toHaveLength(Math.max(1, Math.ceil(count / 500)));
    for (const { url, headers } of calls) {
      expect(url.searchParams.get('branch_id')).toBe('11');
      expect(url.searchParams.get('status')).toBe('active');
      expect(url.searchParams.get('per_page')).toBe('500');
      expect(headers.get('Authorization')).toBe('Bearer synthetic-token');
    }
    expect(from).not.toHaveBeenCalledWith('students');
  });

  it.each(['later page unavailable', 'page budget exceeded', 'malformed first page'])('shows a recoverable error for %s without accepting fallback rows', async (failure) => {
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = new URL(String(input), 'https://fixture.invalid');
      if (url.pathname !== '/api/v1/students') return response({ data: [] });
      if (url.searchParams.get('per_page') === '1') return response({ total: 501 });
      if (failure === 'malformed first page') return response({ data: {} });
      if (url.searchParams.get('page') === '2') return response({}, 503);
      return response({ data: students(500), total: failure === 'page budget exceeded' ? 25001 : 501, last_page: failure === 'page budget exceeded' ? 51 : 2 });
    }));
    wrapper = mountStudents();
    await flushPromises();
    expect(wrapper.find('.students-list-state--error').text()).toContain('學生清單暫時無法載入');
    expect(wrapper.findAll('.student-row')).toHaveLength(0);
    expect(from).not.toHaveBeenCalledWith('students');
  });

  it('refuses an ambiguous full fallback page instead of presenting its first 500 rows as complete', async () => {
    const fallback = query(students(500));
    from.mockReturnValue(fallback);
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = new URL(String(input), 'https://fixture.invalid');
      if (url.pathname === '/api/v1/students' && url.searchParams.get('per_page') === '1') return response({ total: 501 });
      return url.pathname === '/api/v1/students' ? response({}, 503) : response({ data: [] });
    }));
    wrapper = mountStudents();
    await flushPromises();
    expect(fallback.eq).toHaveBeenCalledWith('per_page', 500);
    expect(wrapper.find('.students-list-state--error').exists()).toBe(true);
    expect(wrapper.findAll('.student-row')).toHaveLength(0);
  });

  it('retains school search and grade filters on every page after changing filters', async () => {
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = new URL(String(input), 'https://fixture.invalid');
      if (url.pathname !== '/api/v1/students') return response({ data: [] });
      if (url.searchParams.get('per_page') === '1') return response({ total: 501 });
      calls.push(url);
      const page = Number(url.searchParams.get('page') || 1);
      return response({ data: students(page === 1 ? 500 : 1, (page - 1) * 500), total: 501, last_page: 2 });
    }));
    wrapper = mountStudents();
    await flushPromises();
    vi.useFakeTimers();
    await wrapper.find('#students-name-filter').setValue('光華');
    await wrapper.find('#students-grade-filter').setValue('J1');
    await flushPromises();
    let filteredCalls = calls.filter(url => url.searchParams.get('class_id') === '7');
    expect(filteredCalls.map(url => url.searchParams.get('page'))).toEqual(['1', '2']);
    // Grade refreshes immediately; search also schedules its existing 300 ms
    // refresh. Control that clock so coverage/slow rendering cannot race this assertion.
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
    filteredCalls = calls.filter(url => url.searchParams.get('class_id') === '7');
    expect(filteredCalls.map(url => url.searchParams.get('page'))).toEqual(['1', '2', '1', '2']);
    expect(wrapper.findAll('.student-row')).toHaveLength(501);
    for (const url of filteredCalls) {
      expect(url.searchParams.get('search')).toBe('光華');
      expect(url.searchParams.get('branch_id')).toBe('11');
      expect(url.searchParams.get('status')).toBe('active');
    }
  });

  it('keeps the new branch list when an old branch later finishes its remaining page', async () => {
    let finishOldPage;
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = new URL(String(input), 'https://fixture.invalid');
      if (url.pathname !== '/api/v1/students') return response({ data: [] });
      if (url.searchParams.get('per_page') === '1') return response({ total: 501 });
      if (url.searchParams.get('branch_id') === '12') return response({ data: [{ id: 7001, name: '新分校學生', status: 'active' }], total: 1, last_page: 1 });
      if (url.searchParams.get('page') === '2') return new Promise(resolve => { finishOldPage = resolve; });
      return response({ data: students(500), total: 501, last_page: 2 });
    }));
    wrapper = mountStudents();
    await flushPromises();
    expect(finishOldPage).toBeTypeOf('function');
    await wrapper.setProps({ branchId: 12 });
    await flushPromises();
    expect(wrapper.findAll('.student-row')).toHaveLength(1);
    expect(wrapper.find('.student-row').text()).toContain('新分校學生');
    finishOldPage(response({ data: students(1, 500), total: 501, last_page: 2 }));
    await flushPromises();
    expect(wrapper.findAll('.student-row')).toHaveLength(1);
    expect(wrapper.find('.student-row').text()).toContain('新分校學生');
  });
});
