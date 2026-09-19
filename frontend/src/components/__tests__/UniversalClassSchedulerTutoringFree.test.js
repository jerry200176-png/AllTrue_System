import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';

const { createUniversalClassSchedule } = vi.hoisted(() => ({
  createUniversalClassSchedule: vi.fn(),
}));

vi.mock('../../lib/universalSchedulerApi', () => ({ createUniversalClassSchedule }));
vi.mock('../../lib/coursePackagesApi', () => ({ createMultiSubjectPackage: vi.fn() }));
vi.mock('../../lib/subjectsApi', () => ({ fetchSubjectOptions: vi.fn(async () => []) }));
vi.mock('../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));

import UniversalClassScheduler from '../UniversalClassScheduler.vue';

const mountScheduler = () => shallowMount(UniversalClassScheduler, {
  props: {
    branchId: 1,
    initialStudentId: 1,
    initialTeacherId: 2,
    initialDaysOfWeek: [2],
    students: [{ id: 1, name: '學生' }],
    teachers: [{ id: 2, name: '老師' }],
    rooms: [],
  },
  global: {
    stubs: {
      SearchableSelect: { template: '<select><slot /></select>' },
      TeacherAvailabilityPlanner: { template: '<div />' },
      CoursePaymentDateField: { template: '<input data-payment-date />' },
    },
  },
});

describe('UniversalClassScheduler tutoring free contract (#325)', () => {
  afterEach(() => {
    createUniversalClassSchedule.mockReset();
    vi.restoreAllMocks();
  });

  it('hides payable controls and shows the free-course contract', async () => {
    const wrapper = mountScheduler();
    await flushPromises();
    const type = wrapper.findAll('select').find((node) => node.text().includes('輔導'));
    await type.setValue('tutoring');
    await flushPromises();

    expect(wrapper.text()).toContain('輔導課免費，不需填金額，也不會產生應收帳款。');
    expect(wrapper.find('input[step="50"]').exists()).toBe(false);
    expect(wrapper.find('[data-payment-date]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('預估費用');
    expect(wrapper.text()).toContain('課程期間計算方式');
    wrapper.unmount();
  });

  it('keeps paid-course amount controls and sends payable fields for paid classes', async () => {
    const wrapper = mountScheduler();
    await flushPromises();
    const type = wrapper.findAll('select').find((node) => node.text().includes('輔導'));
    await type.setValue('one_on_one');
    expect(wrapper.find('input[step="50"]').exists()).toBe(true);
    expect(wrapper.find('[data-payment-date]').exists()).toBe(true);
    expect(wrapper.text()).toContain('繳費方式');
    wrapper.unmount();
  });

  it('omits amount and paid_at from tutoring create payload', async () => {
    vi.spyOn(window, 'alert').mockImplementation(() => {});
    createUniversalClassSchedule.mockResolvedValue({ student_class_id: 1 });
    const wrapper = mountScheduler();
    await flushPromises();
    const vm = wrapper.vm;
    vm.form.class_type = 'tutoring';
    vm.form.scheduling_policy = 'manual_occurrence';
    vm.form.total_classes = 1;
    vm.form.paid_at = '2026-09-01';
    vm.form.price_per_session = 9999;
    await vm.submit();

    expect(createUniversalClassSchedule).toHaveBeenCalledTimes(1);
    const payload = createUniversalClassSchedule.mock.calls[0][0];
    expect(payload).not.toHaveProperty('price_per_session');
    expect(payload).not.toHaveProperty('paid_at');
    wrapper.unmount();
  });
});
