import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import { hasUniformFixedSlotDuration } from '../../../lib/courseEditability.js';

describe('in-app #561/#562 date/time intent', () => {
  it.each(['paid', 'partial', 'pending_report', 'unpaid'])('offers the existing lesson scheduling path for %s without changing billing eligibility', async (paymentStatus) => {
    const wrapper = mount(ContractAdjustmentChoiceModal, { props: { show: true, paymentStatus, fixedScheduleAvailable: true } });
    const schedule = wrapper.find('[data-testid="contract-adjust-schedule"]');
    expect(schedule.exists()).toBe(true);
    await schedule.trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['schedule']]);
    expect(wrapper.findAll('.choice-card')[0].attributes('disabled') !== undefined).toBe(paymentStatus !== 'unpaid');
  });
  it('offers a paid recurring-slot edit separately from purchased lessons and renewal', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, { props: { show: true, paymentStatus: 'paid', fixedScheduleAvailable: true } });
    const fixed = wrapper.find('[data-testid="contract-adjust-fixed-schedule"]');
    expect(fixed.exists()).toBe(true);
    await fixed.trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['schedule-settings']]);
    expect(wrapper.findAll('.choice-card')).toHaveLength(3);
  });
  it('does not offer a schedule-only save where the existing backend is unsupported', () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, { props: { show: true, paymentStatus: 'paid', fixedScheduleAvailable: false } });
    expect(wrapper.find('[data-testid="contract-adjust-fixed-schedule"]').exists()).toBe(false);
  });
  it.each([
    { duration_hours: 2, day_time_slots: [{ day: 1, duration_hours: 2 }, { day: 3, duration_hours: 1 }] },
    { duration_hours: 2, day_time_slots: [{ day: 1, duration_hours: 2, duration_minutes: 60 }] },
    { duration_hours: null, day_time_slots: [] },
    { duration_hours: 2, day_time_slots: null },
    { duration_hours: 2, day_time_slots: [null] },
  ])('rejects mixed or unverified fixed-slot lengths: %j', (course) => {
    expect(hasUniformFixedSlotDuration(course)).toBe(false);
  });
  it('accepts a verified uniform cadence and its legacy empty slot list', () => {
    expect(hasUniformFixedSlotDuration({ duration_hours: 2, day_time_slots: [
      { day: 1, duration_hours: 2, duration_minutes: 120 }, { day: 3, duration_hours: 2 },
    ] })).toBe(true);
    expect(hasUniformFixedSlotDuration({ duration_hours: 2, day_time_slots: [] })).toBe(true);
  });
});
