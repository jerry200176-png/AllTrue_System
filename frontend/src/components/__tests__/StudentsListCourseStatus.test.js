import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';

afterEach(() => vi.unstubAllGlobals());

const ordinary = {
  id: 7001, subject: 'Math', subject_name: '數學', status: 'active',
  payment_type: 'session', payment_status: 'unpaid', class_type: 'one_on_one',
  sessions_purchased: 8, remaining_sessions: 0,
};

async function withCourses(courses, assertions) {
  const fetchMock = vi.fn(async (input) => {
    const url = String(input);
    const body = url.includes('/student-classes') ? { data: courses }
      : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
        : url.includes('/class-sessions') ? { byClass: Object.fromEntries(courses.map(c => [c.id, []])) }
          : { data: [] };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  const wrapper = shallowMount(StudentsList, { props: { branchId: 1 }, global: { renderStubDefaultSlot: true } });
  try {
    await flushPromises();
    await wrapper.find('.student-row').trigger('click');
    await flushPromises();
    await assertions(wrapper);
    expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
  } finally {
    wrapper.unmount();
  }
}

describe('In-App #353: existing course history stays distinct from current balances', () => {
  it.each([
    ['completed', '歷史 · 已完課'],
    ['settled', '歷史 · 已結算'],
    ['settled_pending', '歷史 · 課已結束，等你確認收款'],
  ])('labels an existing %s row without hiding its recorded balance or reconciliation task', async (closed_reason, label) => {
    await withCourses([{ ...ordinary, closed_reason }], async (wrapper) => {
      if (closed_reason === 'settled_pending') {
        expect(wrapper.find('.subject-pill').exists()).toBe(false);
        wrapper.findAllComponents({ name: 'AtButton' }).find(b => b.text().includes('顯示已結業')).vm.$emit('click');
        await flushPromises();
      }
      const pill = wrapper.find('.subject-pill');
      expect(pill.exists()).toBe(true);
      expect(pill.find('strong').text()).toBe('0堂');
      expect(pill.text()).toContain(label);
      expect(wrapper.findAll('.student-course-card')).toHaveLength(0);
      await wrapper.find('.sl-history-toggle').trigger('click');
      const history = wrapper.find('.sl-history-card');
      expect(history.exists()).toBe(true);
      expect(history.text()).toContain(label.replace('歷史 · ', ''));
    });
  });

  it('does not treat an active unpaid zero as completed, or allocate a shared pool to a member', async () => {
    await withCourses([
      ordinary,
      { ...ordinary, id: 7002, subject: 'English', PackageID: 9001, package_total_sessions: 8, package_remaining_sessions: 6 },
      { ...ordinary, id: 7003, subject: 'Science', payment_type: 'monthly', monthly_sessions: 4 },
    ], (wrapper) => {
      const pills = wrapper.findAll('.subject-pill');
      expect(pills).toHaveLength(3);
      expect(pills.map(p => p.find('strong').text())).toEqual(['0堂', '共用方案', '每月4堂']);
      expect(pills.every(p => !p.text().includes('歷史'))).toBe(true);
      expect(wrapper.findAll('.student-course-card')).toHaveLength(3);
      expect(wrapper.find('.student-course-card__next-step').text()).toContain('先處理課程續報');
    });
  });
});
