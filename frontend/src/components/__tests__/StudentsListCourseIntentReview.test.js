import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';
import CourseIntentChooser from '../course-management/CourseIntentChooser.vue';

afterEach(() => vi.unstubAllGlobals());

describe('StudentsList actual intent options (#4358 review)', () => {
  it('offers exhausted renewal, no monthly zero, and only a usable transfer', async () => {
    const base = { student_id: 2001, subject: 'Math', status: 'active', class_type: 'one_on_one', payment_type: 'session', payment_status: 'paid', sessions_purchased: 8, sessions_used: 4, remaining_sessions: 4 };
    const courses = [
      { ...base, id: 7001, sessions_used: 8, remaining_sessions: 0 },
      { ...base, id: 7002 },
      { ...base, id: 7003, payment_type: 'monthly', sessions_used: 0, remaining_sessions: 0 },
      { ...base, id: 7004, PackageID: 9, package_remaining_sessions: 4 },
      { ...base, id: 7005, sessions_used: 0, remaining_sessions: 8 },
      { ...base, id: 7006, rate_unit: 'hour' },
      { ...base, id: 7007, latest_payment_report_id: 81 },
      { ...base, id: 7008, payment_status: 'pending_report' },
      { ...base, id: 7009, payment_type: 'monthly', PackageID: 9 },
    ];
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = String(input);
      const body = url.includes('/student-classes') ? { data: courses }
        : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
          : url.includes('/class-sessions') ? { byClass: Object.fromEntries(courses.map(c => [c.id, []])) }
            : { data: [] };
      return { ok: true, json: async () => body };
    }));
    const w = shallowMount(StudentsList, { props: { branchId: 1 }, global: {
      renderStubDefaultSlot: true, stubs: { CourseIntentChooser: false },
    } });
    try {
      await flushPromises();
      await w.find('.student-row').trigger('click');
      await flushPromises();
      await w.find('[data-testid="student-course-entry"]').trigger('click');
      const chooser = w.findComponent(CourseIntentChooser);
      const options = chooser.props('courses');
      expect(options.find(c => c.id === 7001)?.renewable).toBe(true);
      expect(options.find(c => c.id === 7009)).toBeUndefined();
      expect(options.find(c => c.id === 7003)?.detail).not.toContain('剩 0 堂');
      expect(options.filter(c => c.transferable).map(c => c.id)).toEqual([7002]);
      await chooser.find('[data-testid="course-intent-renew"]').trigger('click');
      expect(chooser.find('[data-testid="course-intent-course-7001"]').exists()).toBe(true);
      expect(chooser.find('[data-testid="course-intent-course-7003"]').text()).not.toContain('剩 0 堂');
    } finally { w.unmount(); }
  });
});
