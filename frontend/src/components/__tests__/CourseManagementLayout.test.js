import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import CourseManagement from '../../pages/CourseManagement.vue';

const fixture = vi.hoisted(() => ({ courses: [] }));
vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'synthetic-layout' } } })) } } }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/authedFetch', () => ({
  getAccessToken: vi.fn(async () => 'synthetic-layout'),
  authedFetch: vi.fn(async (url) => ({ ok: true, json: async () => String(url).startsWith('/api/v1/student-classes?')
    ? { data: fixture.courses, total: fixture.courses.length, current_page: 1, last_page: 1 }
    : { data: [] } })),
}));
const course = (id, extra = {}) => ({
  id, student_id: 700, student_name: '合成學生', teacher_name: `合成老師${id}`, subject: 'english',
  first_class_date: '2026-09-01', status: 'active', ScheduleMode: 'count', payment_type: 'session',
  sessions_purchased: 8, SessionCount: 8, sessions_used: 3, remaining_sessions: 5,
  payment_status: 'unpaid', ...extra,
});
let wrapper;
async function open() {
  wrapper = shallowMount(CourseManagement, { props: { branchId: 15, userRole: 'director' },
    global: { stubs: { Card: false, Badge: false, Button: false, Primitive: false, ActionMenu: false, TextSizeToggle: false } } });
  await flushPromises();
  return wrapper.vm.$.setupState;
}
beforeEach(() => {
  fixture.courses = [course(30, { usage_balance_status: 'review_required' })];
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: [], by_class: {} }) })));
});
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });

describe('#527 course workbench layout', () => {
  it('uses the shared workbench heading and text-size control with a compact primary search', async () => {
    await open();
    expect(wrapper.get('h1').text()).toBe('課程查找');
    expect(wrapper.get('[data-testid="text-size"]').attributes('role')).toBe('radiogroup');
    expect(wrapper.get('#course-filter-student').element.closest('details')).toBeNull();
    expect(wrapper.get('#course-filter-status').element.closest('details')).toBeNull();
    expect(wrapper.get('#course-filter-teacher').element.closest('details')).not.toBeNull();
    expect(wrapper.get('[data-testid="course-header-create"]').text()).toContain('新增課程');
  });

  it('shows active filters and distinguishes cancelling student focus from clearing filters', async () => {
    const state = await open();
    state.filters = { name: '合成', class_type: 'one_on_one', teacher_name: '', teacher_id: '', course_status: 'active' };
    state.focusedStudentKey = 'sid:700';
    await nextTick();
    expect(wrapper.get('[data-testid="course-active-filters"]').text()).toContain('進行中');
    const cancelFocus = wrapper.get('.focus-mode-banner button');
    expect(cancelFocus.text()).toContain('取消專注');
    await cancelFocus.trigger('click');
    expect(state.focusedStudentKey).toBeNull();
    expect(state.filters.course_status).toBe('active');
    await wrapper.get('[data-testid="course-filter-clear"]').trigger('click');
    await flushPromises();
    expect(state.filters).toEqual({ name: '', class_type: '', teacher_name: '', teacher_id: '', course_status: '' });
    expect(wrapper.find('[data-testid="course-active-filters"]').exists()).toBe(false);
  });

  it('keeps every contract fact and existing reconciliation action within its semantic list item', async () => {
    const state = await open();
    const row = wrapper.findAll('.course-row').find(node => node.find('[data-course-id="30"]').exists());
    expect(row.element.closest('[role="listitem"]')).not.toBeNull();
    expect(row.findAll('.contract-field-label').map(node => node.text())).toEqual(['科目・老師', '下一堂', '堂數', '繳費']);
    expect(row.text()).toContain('開課 2026-09-01');
    expect(row.get('.contract-count-source').text()).toContain('購買 8 堂');
    expect(row.find('[data-testid="course-row-primary"]').exists()).toBe(true);
    expect(row.find('button[aria-haspopup="menu"]').exists()).toBe(true);
    await row.get('button[aria-label="堂數待對帳"]').trigger('click');
    expect(state.ledgerOpen).toBe(true);
    expect(wrapper.emitted('navigate')).toBeUndefined();
  });
});
