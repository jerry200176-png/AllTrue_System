import { describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

vi.mock('../../lib/universalSchedulerApi', () => ({ createUniversalClassSchedule: vi.fn() }));
vi.mock('../../lib/coursePackagesApi', () => ({ createMultiSubjectPackage: vi.fn() }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));

import UniversalClassScheduler from '../UniversalClassScheduler.vue';
import { buildCreateTeacherOptions, resolveCreateTeacherId } from '../../lib/courseTeacherOptions.js';

// Mirrors SmartCalendar quick-create (#4157 / in-app #411): the calendar seeds the
// create scheduler with the clicked column's teacher, validated against the create
// options only once the teacher list has loaded.
const calendarTeachers = [
  { id: 7, username: '王老師', status: 'active', branch_ids: [1], branch_id: 1 },
  { id: 9, username: '停用老師', status: 'inactive', branch_ids: [1], branch_id: 1 },
];
const loadedOptions = buildCreateTeacherOptions(calendarTeachers);

const mountWith = ({ seed, options, loaded }) => shallowMount(UniversalClassScheduler, {
  props: {
    branchId: 1,
    mode: 'create',
    initialTeacherId: resolveCreateTeacherId(seed, options, { loaded }),
    initialDaysOfWeek: [2],
    students: [{ id: 1, name: '學生' }],
    teachers: options,
    rooms: [],
  },
  global: {
    stubs: {
      SearchableSelect: { template: '<select><slot /></select>' },
      TeacherAvailabilityPlanner: { template: '<div />' },
      CoursePaymentDateField: { template: '<input />' },
    },
  },
});

describe('SmartCalendar create teacher seed (#4157 review)', () => {
  it('keeps an active seed while teachers load and selects it once loaded', async () => {
    const wrapper = mountWith({ seed: 7, options: [], loaded: false });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe(7);

    await wrapper.setProps({ teachers: loadedOptions, initialTeacherId: resolveCreateTeacherId(7, loadedOptions) });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe(7);
    wrapper.unmount();
  });

  it('clears a deactivated seed when teachers are already loaded', async () => {
    const wrapper = mountWith({ seed: 9, options: loadedOptions, loaded: true });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe('');
    wrapper.unmount();
  });

  it('clears a deactivated seed once teachers finish loading', async () => {
    const wrapper = mountWith({ seed: 9, options: [], loaded: false });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe(9);

    await wrapper.setProps({ teachers: loadedOptions, initialTeacherId: resolveCreateTeacherId(9, loadedOptions) });
    await flushPromises();
    expect(wrapper.vm.form.teacher_id).toBe('');
    wrapper.unmount();
  });
});
