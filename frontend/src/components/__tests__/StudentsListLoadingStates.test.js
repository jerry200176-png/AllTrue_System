import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AtEmpty from '../design-system/AtEmpty.vue';

const { getSession, from } = vi.hoisted(() => ({
  getSession: vi.fn(),
  from: vi.fn(),
}));

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession }, from },
}));

import StudentsList from '../../pages/StudentsList.vue';

const response = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
});

const student = {
  id: 2001,
  name: '測試學生',
  grade: 'J1',
  status: 'active',
};

const makeSupabaseQuery = (result = { data: [], error: null }) => {
  const query = {
    select: vi.fn(() => query),
    eq: vi.fn(() => query),
    ilike: vi.fn(() => query),
    order: vi.fn(() => Promise.resolve(result)),
  };
  return query;
};

const mountStudents = () => shallowMount(StudentsList, {
  props: { branchId: 1 },
  global: { stubs: { AtEmpty } },
});

describe('StudentsList loading and recovery states', () => {
  beforeEach(() => {
    getSession.mockResolvedValue({ data: { session: { access_token: 'test-token' } } });
    from.mockImplementation(() => makeSupabaseQuery());
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
  });

  it('shows a loading state before the first student response and then renders the list', async () => {
    let resolveStudents;
    const pendingStudents = new Promise((resolve) => { resolveStudents = resolve; });
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/students?') && url.includes('per_page=500')) return pendingStudents;
      if (url.includes('/students?') && url.includes('per_page=1')) return Promise.resolve(response({ total: 1 }));
      if (url.includes('/student-classes')) return Promise.resolve(response({ data: [] }));
      if (url.includes('/teachers') || url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
      return Promise.resolve(response({ data: [] }));
    });

    const wrapper = mountStudents();
    await nextTick();
    expect(wrapper.find('.students-list-state--loading').text()).toContain('正在載入學生清單');
    expect(wrapper.find('.table-scroll-wrap').exists()).toBe(false);

    resolveStudents(response({ data: [student], total: 1 }));
    await flushPromises();

    expect(wrapper.find('.students-list-state--loading').exists()).toBe(false);
    expect(wrapper.find('.student-row').exists()).toBe(true);
    wrapper.unmount();
  });

  it('surfaces a recoverable error and retries the failed list request', async () => {
    let studentListAttempt = 0;
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/students?') && url.includes('per_page=500')) {
        studentListAttempt += 1;
        return Promise.resolve(studentListAttempt === 1
          ? response({ message: 'temporary outage' }, 503)
          : response({ data: [student], total: 1 }));
      }
      if (url.includes('/students?') && url.includes('per_page=1')) return Promise.resolve(response({ total: 1 }));
      if (url.includes('/student-classes')) return Promise.resolve(response({ data: [] }));
      if (url.includes('/teachers') || url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
      return Promise.resolve(response({ data: [] }));
    });
    from.mockImplementation(() => makeSupabaseQuery({ data: [], error: new Error('fallback unavailable') }));

    const wrapper = mountStudents();
    await flushPromises();

    expect(wrapper.find('.students-list-state--error').text()).toContain('學生清單暫時無法載入');
    await wrapper.find('.students-list-state__action').trigger('click');
    await flushPromises();

    expect(studentListAttempt).toBe(2);
    expect(wrapper.find('.student-row').exists()).toBe(true);
    expect(wrapper.find('.students-list-state--error').exists()).toBe(false);
    wrapper.unmount();
  });
});
