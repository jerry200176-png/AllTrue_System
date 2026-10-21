import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
import StudentsList from '../../pages/StudentsList.vue';
import { settleConfirm } from '../../composables/useConfirmDialog';
afterEach(() => { settleConfirm(false); vi.unstubAllGlobals(); });

describe('teacher precheck without hidden course disclosure', () => {
  it.each([false, true])('warns before the form and respects continue=%s', async (proceed) => {
    const fetchMock = vi.fn(async (input) => {
      const url = String(input);
      const body = url.includes('/active-courses') ? { courses: [], precheck_scope: 'teacher_courses' }
        : url.includes('/student-classes') ? { data: [{ id: 7001, student_id: 2001, subject: 'Math', status: 'active', payment_type: 'session', payment_status: 'unpaid', sessions_purchased: 8, remaining_sessions: 4 }] }
          : url.includes('/students?') ? { data: [{ id: 2001, name: '合成學生', status: 'active' }], total: 1 }
          : { data: [] };
      return { ok: true, json: async () => body };
    });
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = shallowMount(StudentsList, { props: { branchId: 1 }, global: { renderStubDefaultSlot: true, stubs: { ConfirmDialogHost: false, AtDialog: false, teleport: true } } });
    try {
      await flushPromises();
      await wrapper.find('.student-row').trigger('click');
      await flushPromises();
      await wrapper.findAll('button').find(b => b.text().includes('新增課程')).trigger('click');
      await flushPromises();
      expect(wrapper.find('[data-testid="confirm-dialog-message"]').text()).toContain('這不是強制建立');
      expect(wrapper.findComponent({ name: 'UniversalClassScheduler' }).exists()).toBe(false);
      await wrapper.find(proceed ? '[data-testid="confirm-dialog-confirm"]' : '[data-testid="confirm-dialog-cancel"]').trigger('click');
      await flushPromises();
      expect(wrapper.findComponent({ name: 'UniversalClassScheduler' }).exists()).toBe(proceed);
      expect(fetchMock.mock.calls.every(([, options]) => !options?.method || options.method === 'GET')).toBe(true);
    } finally { wrapper.unmount(); }
  });
});
