import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, shallowMount } from '@vue/test-utils';
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: async () => [] }));
import UniversalClassScheduler from '../UniversalClassScheduler.vue';
import CourseTransferModal from '../course-management/CourseTransferModal.vue';
import CourseManagement from '../../pages/CourseManagement.vue';
let opener;
beforeEach(() => { vi.stubGlobal('scrollTo', vi.fn()); opener = document.createElement('button'); document.body.append(opener); opener.focus(); });
afterEach(() => { opener.remove(); vi.unstubAllGlobals(); });

describe('course intent destination handoffs', () => {
  it('moves focus into the newly mounted course scheduler', async () => {
    const w = shallowMount(UniversalClassScheduler, { attachTo: document.body, props: { branchId: 1 } });
    try {
      await flushPromises();
      expect(w.find('[role="dialog"]').element.contains(document.activeElement)).toBe(true);
    } finally { w.unmount(); expect(document.activeElement).toBe(opener); }
  });

  it('moves focus into transfer when the destination opens', async () => {
    const w = mount(CourseTransferModal, { attachTo: document.body, props: { show: false, courseId: 7001 } });
    try {
      await w.setProps({ show: true });
      await flushPromises();
      expect(w.find('[role="dialog"]').element.contains(document.activeElement)).toBe(true);
      await w.setProps({ show: false });
      expect(document.activeElement).toBe(opener);
    } finally { w.unmount(); }
  });

  it('keeps a missing pinned transfer target available for an explicit reload', async () => {
    let present = false;
    const classRequests = [];
    vi.stubGlobal('fetch', vi.fn(async (input) => {
      const url = String(input);
      let body = { data: [] };
      if (url.includes('/student-classes?')) {
        classRequests.push(url);
        body = { data: present ? [{ id: 7001, student_id: 2001, student_name: '合成學生', subject: 'Math',
          payment_type: 'session', class_type: 'one_on_one', status: 'active', teacher_id: 7,
          sessions_purchased: 8, remaining_sessions: 4, sessions_used: 4 }] : [], current_page: 1, last_page: 1, total: present ? 1 : 0 };
      } else if (url.includes('/class-sessions')) body = { byClass: { 7001: [] } };
      return { ok: true, json: async () => body };
    }));
    const w = shallowMount(CourseManagement, { attachTo: document.body, props: { branchId: 1, initialStudentId: 2001,
      initialCourseId: 7001, initialTeacherId: 7, initialCourseIntent: 'transfer' }, global: { renderStubDefaultSlot: true } });
    try {
      await flushPromises();
      expect(w.find('[data-testid="transfer-handoff-retry"]').exists()).toBe(true);
      expect(w.findComponent(CourseTransferModal).props('show')).toBe(false);
      w.findComponent({ name: 'CourseIntentChooser' }).vm.$emit('browse', 'renew');
      await flushPromises();
      expect(document.activeElement).toBe(w.find('[data-guide="course-mgmt-table"]').element);
      present = true;
      await w.find('[data-testid="transfer-handoff-retry"]').trigger('click');
      await flushPromises();
      expect(w.findComponent(CourseTransferModal).props('show')).toBe(true);
      expect(w.find('[data-testid="transfer-handoff-error"]').exists()).toBe(false);
      expect(new URL(classRequests.at(-1), 'http://test.local').searchParams.get('student_id')).toBe('2001');
      w.findComponent(CourseTransferModal).vm.$emit('done');
      await flushPromises();
      expect(new URL(classRequests.at(-1), 'http://test.local').searchParams.has('teacher_id')).toBe(false);
    } finally { w.unmount(); }
  });
});
