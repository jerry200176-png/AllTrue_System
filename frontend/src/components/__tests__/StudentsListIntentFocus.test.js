import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';
afterEach(() => vi.unstubAllGlobals());

describe('intent chooser hands keyboard focus to renewal', () => {
  it.each(['session', 'monthly'])('focuses the actual %s renewal modal after closing the chooser', async (payment_type) => {
    vi.stubGlobal('scrollTo', vi.fn());
    const course = { id: 7001, student_id: 2001, subject: 'Math', status: 'active', class_type: 'one_on_one',
      payment_type, payment_status: 'paid', sessions_purchased: 8, sessions_used: 4, remaining_sessions: 4 };
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = String(input);
      const body = url.includes('/student-classes') ? { data: [course] }
        : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
          : url.includes('/class-sessions') ? { byClass: { 7001: [] } } : { data: [] };
      return { ok: true, json: async () => body };
    }));
    const wrapper = shallowMount(StudentsList, { attachTo: document.body, props: { branchId: 1 }, global: {
      renderStubDefaultSlot: true,
      stubs: { CourseIntentChooser: false, AtDialog: false, RenewMonthlyModal: false, teleport: true },
    } });
    try {
      await flushPromises();
      await wrapper.find('.student-row').trigger('click');
      await flushPromises();
      const opener = wrapper.find('[data-testid="student-course-entry"]');
      opener.element.focus();
      await opener.trigger('click');
      await flushPromises();
      await wrapper.find('[data-testid="course-intent-renew"]').trigger('click');
      await flushPromises();
      const destination = wrapper.find(`[data-testid="student-${payment_type === 'session' ? 'session' : 'monthly'}-renewal"]`);
      expect(destination.exists()).toBe(true);
      expect(destination.element.contains(document.activeElement)).toBe(true);
      expect(document.activeElement).not.toBe(opener.element);
    } finally { wrapper.unmount(); }
  });
});
