import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';

const { getSession } = vi.hoisted(() => ({
  getSession: vi.fn(),
}));

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession } },
}));

import TeachersList from '../../pages/TeachersList.vue';

const response = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  statusText: status === 503 ? 'Service Unavailable' : 'OK',
  json: async () => body,
});

const teacher = {
  id: 3001,
  username: '測試老師',
  account: 'teacher@example.test',
  status: 'active',
};

const mountTeachers = () => shallowMount(TeachersList, {
  props: { branchId: 1 },
  global: { stubs: { AtFilterBar: false, AtSkeleton: false } },
});

const defaultFetch = (teachersResponse) => vi.fn((input) => {
  const url = String(input);
  if (url.includes('/teachers?')) return Promise.resolve(teachersResponse);
  if (url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
  if (url.includes('/branches')) return Promise.resolve(response([]));
  if (url.includes('/engagement/ranks-for')) return Promise.resolve(response({ data: [] }));
  return Promise.resolve(response({ data: [] }));
});

describe('TeachersList loading and recovery states', () => {
  beforeEach(() => {
    getSession.mockResolvedValue({ data: { session: { access_token: 'test-token' } } });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
  });

  it('shows an initial loading state before the first teacher response', async () => {
    let resolveTeachers;
    const pendingTeachers = new Promise((resolve) => { resolveTeachers = resolve; });
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/teachers?')) return pendingTeachers;
      if (url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
      if (url.includes('/branches')) return Promise.resolve(response([]));
      return Promise.resolve(response({ data: [] }));
    });

    const wrapper = mountTeachers();
    await nextTick();
    expect(wrapper.find('[aria-label="老師資料載入中"]').exists()).toBe(true);
    expect(wrapper.find('.teacher-profile-card').exists()).toBe(false);

    resolveTeachers(response({ data: [teacher] }));
    await flushPromises();

    expect(wrapper.find('[aria-label="老師資料載入中"]').exists()).toBe(false);
    expect(wrapper.find('.teacher-profile-card').text()).toContain(teacher.username);
    wrapper.unmount();
  });

  it('shows an initial recoverable error and retries successfully', async () => {
    let teacherAttempt = 0;
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/teachers?')) {
        teacherAttempt += 1;
        return Promise.resolve(teacherAttempt === 1
          ? response({ message: 'temporary outage' }, 503)
          : response({ data: [teacher] }));
      }
      if (url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
      if (url.includes('/branches')) return Promise.resolve(response([]));
      if (url.includes('/engagement/ranks-for')) return Promise.resolve(response({ data: [] }));
      return Promise.resolve(response({ data: [] }));
    });

    const wrapper = mountTeachers();
    await flushPromises();

    expect(wrapper.find('.teachers-list-state--error').text()).toContain('老師清單暫時無法載入');
    await wrapper.find('.teachers-list-state__action').trigger('click');
    await flushPromises();

    expect(teacherAttempt).toBe(2);
    expect(wrapper.find('.teacher-profile-card').text()).toContain(teacher.username);
    expect(wrapper.find('.teachers-list-state--error').exists()).toBe(false);
    wrapper.unmount();
  });

  it('keeps the last successful rows visible when a refresh fails', async () => {
    let teacherAttempt = 0;
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/teachers?')) {
        teacherAttempt += 1;
        return Promise.resolve(teacherAttempt === 1
          ? response({ data: [teacher] })
          : response({ message: 'temporary refresh outage' }, 503));
      }
      if (url.includes('/subjects')) return Promise.resolve(response({ data: [] }));
      if (url.includes('/branches')) return Promise.resolve(response([]));
      if (url.includes('/engagement/ranks-for')) return Promise.resolve(response({ data: [] }));
      return Promise.resolve(response({ data: [] }));
    });

    const wrapper = mountTeachers();
    await flushPromises();
    await wrapper.find('#teachers-status-filter').setValue('active');
    await flushPromises();

    expect(wrapper.find('.teachers-refresh-state--error').text()).toContain('仍顯示上次成功載入');
    expect(wrapper.find('.teacher-profile-card').text()).toContain(teacher.username);
    expect(teacherAttempt).toBe(2);
    wrapper.unmount();
  });

  it('distinguishes true-empty from filtered-empty and clears filters', async () => {
    let teacherAttempt = 0;
    globalThis.fetch = defaultFetch(response({ data: [] }));
    const originalFetch = globalThis.fetch;
    globalThis.fetch = vi.fn((input) => {
      const url = String(input);
      if (url.includes('/teachers?')) {
        teacherAttempt += 1;
        return Promise.resolve(teacherAttempt < 3
          ? response({ data: [] })
          : response({ data: [teacher] }));
      }
      return originalFetch(input);
    });

    const wrapper = mountTeachers();
    await flushPromises();
    expect(wrapper.find('.teachers-list-state--true-empty').exists()).toBe(true);

    await wrapper.find('#teachers-status-filter').setValue('pending');
    await flushPromises();
    expect(wrapper.find('.teachers-list-state--filtered-empty').exists()).toBe(true);
    expect(wrapper.find('.teachers-list-state--filtered-empty').text()).toContain('找不到符合條件的老師');

    await wrapper.find('.teachers-list-state--filtered-empty .teachers-list-state__action').trigger('click');
    await flushPromises();
    expect(wrapper.find('.teacher-profile-card').text()).toContain(teacher.username);
    expect(wrapper.find('#teachers-status-filter').element.value).toBe('');
    wrapper.unmount();
  });
});
