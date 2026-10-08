import { afterEach, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: {
    auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) },
    from: vi.fn(),
  },
}));
vi.mock('../../lib/classSessionsApi.js', () => ({
  fetchClassSessions: async () => ({ byClass: {} }),
}));
import StudentsList from '../../pages/StudentsList.vue';

afterEach(() => vi.unstubAllGlobals());

it('In-App #345: a settled monthly course with Stop=0 is labelled history, never as a current 月結 subject', async () => {
  const courses = [
    // Shape of the report: monthly, not stopped, but the server closed it as settled.
    { id: 8001, subject: 'Biology', subject_name: '生物', payment_type: 'monthly', status: 'active',
      closed_reason: 'settled', payment_status: 'paid' },
    { id: 8002, subject: 'Math', subject_name: '數學', payment_type: 'monthly', status: 'active',
      payment_status: 'paid' },
  ];
  vi.stubGlobal('fetch', vi.fn(async (input) => {
    const url = String(input);
    const body = url.includes('/student-classes') ? { data: courses }
      : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
        : { data: [] };
    return { ok: true, json: async () => body };
  }));
  const wrapper = shallowMount(StudentsList, { props: { branchId: 1 } });
  try {
    await flushPromises();
    await wrapper.find('.student-row').trigger('click');
    await flushPromises();
    const chips = wrapper.findAll('.student-row .subject-pill').map((c) => c.text());
    // #3731 keeps closed rows visible as chips, but they must say 歷史 (the report saw a bare 「生物 月結」).
    expect(chips).toHaveLength(2);
    expect(chips[0]).toContain('生物');
    expect(chips[0]).toContain('歷史 · 已結算');
    expect(chips[1]).toContain('數學');
    expect(chips[1]).not.toContain('歷史');
  } finally {
    wrapper.unmount();
  }
});
