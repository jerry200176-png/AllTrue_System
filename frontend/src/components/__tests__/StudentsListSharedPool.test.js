import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: {
    auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) },
    from: vi.fn(),
  },
}));
vi.mock('../../lib/classSessionsApi.js', () => ({
  fetchClassSessions: async () => ({ byClass: { 7001: [], 7002: [], 7003: [] } }),
}));
import StudentsList from '../../pages/StudentsList.vue';

afterEach(() => vi.unstubAllGlobals());

it('In-App #367: shows shared balance once at package level, never on member cards, and preserves ordinary courses', async () => {
  const courses = [
    ...['English', 'Science'].map((subject, index) => ({
      id: 7001 + index, subject, subject_name: index ? '自然' : '英文',
      payment_type: 'session', status: 'active', class_type: 'one_on_three',
      payment_status: 'paid', PackageID: 9000, package_total_sessions: 25,
      package_remaining_sessions: 17, package_used_sessions: 8,
      sessions_purchased: 99, remaining_sessions: 99,
    })),
    { id: 7003, subject: 'Math', payment_type: 'session', status: 'active',
      payment_status: 'paid', sessions_purchased: 8, remaining_sessions: 3, sessions_used: 5 },
  ];
  const fetchMock = vi.fn(async (input) => {
    const url = String(input);
    const body = url.includes('/student-classes') ? { data: courses }
      : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
        : { data: [] };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = shallowMount(StudentsList, { props: { branchId: 1 } });
  try {
    await flushPromises();
    await wrapper.find('.student-row').trigger('click');
    await flushPromises();
    const cards = wrapper.findAll('.student-course-card');
    expect(cards).toHaveLength(3);
    const pools = wrapper.findAll('.student-package-summary');
    expect(pools).toHaveLength(1);
    expect(pools[0].text()).toContain('共用方案');
    expect(pools[0].find('[data-testid="package-remaining"]').text()).toBe('17');
    expect(pools[0].find('[data-testid="package-total"]').text()).toBe('25');
    for (const card of cards.slice(0, 2)) {
      expect(card.find('.student-course-card__progress').exists()).toBe(false);
      expect(card.find('[role="progressbar"]').exists()).toBe(false);
      expect(card.text()).not.toContain('堂剩餘');
      expect(card.find('.student-course-dates').exists()).toBe(true);
      expect(card.text()).toContain('共用方案');
    }
    expect(cards[2].find('.student-course-card__progress-head').text()).toBe('課程進度3 / 8 堂剩餘');
    expect(cards[2].text()).not.toContain('各科共用同一堂數池');
    expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
  } finally {
    wrapper.unmount();
  }
});
