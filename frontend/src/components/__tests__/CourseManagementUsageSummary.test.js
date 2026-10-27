import { afterEach, describe, expect, it, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { nextTick } from 'vue';
import CourseManagement from '../../pages/CourseManagement.vue';

vi.mock('../../supabase', () => ({ supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: null } })) } } }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));

describe('course usage summary in actual course cards', () => {
  let wrapper;
  afterEach(() => wrapper?.unmount());

  it('labels backend entitlement usage consistently on active and historical cards', async () => {
    wrapper = shallowMount(CourseManagement, { props: { branchId: null, userRole: 'director' } });
    await nextTick();
    const state = wrapper.vm.$.setupState;
    const common = { student_id: 700001, student_name: '測試學生', payment_type: 'session', ScheduleMode: 'count', sessions_purchased: 8, SessionCount: 8, remaining_sessions: 2, subject: 'math' };
    state.courses = [{ ...common, id: 700001, status: 'active' }, { ...common, id: 700002, status: 'inactive', history_reason: 'completed' }];
    state.expandedStudentGroups = new Set(['sid:700001']);
    state.expandedHistoryGroups = new Set(['sid:700001']);
    state.expandedDates = new Set([700001, 700002]);
    await nextTick();
    const summaries = wrapper.findAll('.dates-panel-title').map((node) => node.text());
    expect(summaries).toHaveLength(2);
    expect(summaries).toEqual(['上課日期（已用 6 / 購買 8 堂）', '上課日期（已用 6 / 購買 8 堂）']);
  });
});
