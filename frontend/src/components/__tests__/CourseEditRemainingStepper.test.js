import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import CourseEditForm from '../CourseEditForm.vue';

vi.mock('../../lib/substituteApi.js', () => ({ fetchTeacherAvailability: vi.fn(async () => []) }));

const mountForm = (remaining) => mount(CourseEditForm, {
  props: { modelValue: { subject: 'Math', teacher_id: '', payment_type: 'session', remaining_sessions: remaining }, showRemaining: true },
  global: { stubs: { TeacherAvailabilityPlanner: true, CoursePaymentDateField: true } },
});

describe('剩餘堂數 −/+ stepper', () => {
  it('+ and − change remaining_sessions by exactly 1 per tap', async () => {
    const w = mountForm(5);
    await flushPromises();
    await w.get('[data-testid="remaining-plus"]').trigger('click');
    expect(w.vm.form.remaining_sessions).toBe(6);
    await w.get('[data-testid="remaining-minus"]').trigger('click');
    await w.get('[data-testid="remaining-minus"]').trigger('click');
    expect(w.vm.form.remaining_sessions).toBe(4);
  });

  it('never goes below 0 and − is disabled at 0', async () => {
    const w = mountForm(1);
    await flushPromises();
    await w.get('[data-testid="remaining-minus"]').trigger('click');
    expect(w.vm.form.remaining_sessions).toBe(0);
    expect(w.get('[data-testid="remaining-minus"]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid="remaining-minus"]').trigger('click');
    expect(w.vm.form.remaining_sessions).toBe(0);
  });
});
