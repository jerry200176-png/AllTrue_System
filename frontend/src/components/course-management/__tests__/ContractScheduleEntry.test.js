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
    { SessionDuration: 120, duration_hours: 2, day_time_slots: [{ day: 1, duration_hours: 2 }, { day: 3, duration_hours: 1 }] },
    { SessionDuration: 120, duration_hours: 2, day_time_slots: [{ day: 1, duration_hours: 2, duration_minutes: 60 }] },
    { duration_hours: null, day_time_slots: [] },
    { duration_hours: 2, day_time_slots: null },
    { duration_hours: 2, day_time_slots: [null] },
    { SessionDuration: 75, duration_hours: 1.3, day_time_slots: [{ day: 1, duration_hours: 1.3 }] },
    { SessionDuration: 31, duration_hours: 0.5, day_time_slots: [{ day: 1, duration_hours: 0.5 }] },
    { SessionDuration: 91, duration_hours: 1.5, day_time_slots: [{ day: 1, duration_hours: 1.5 }] },
    { SessionDuration: 90, duration_hours: 1.5, week1: 3, time1: '20:00', duration1: 91,
      day_time_slots: [{ day: 1, duration_hours: 1.5 }, { day: 3, duration_hours: 1.5 }] },
    { SessionDuration: 90, duration_hours: 1.5, week1: 3, time1: null, time: '20:00', duration1: 91,
      day_time_slots: [{ day: 1, duration_hours: 1.5 }, { day: 3, duration_hours: 1.5 }] },
    { duration_hours: 2, day_time_slots: [{ day: 1, duration_hours: 2 }] },
  ])('rejects mixed or unverified fixed-slot lengths: %j', (course) => {
    expect(hasUniformFixedSlotDuration(course)).toBe(false);
  });
  it('accepts a verified uniform cadence and its legacy empty slot list', () => {
    expect(hasUniformFixedSlotDuration({ SessionDuration: 120, duration_hours: 2, day_time_slots: [
      { day: 1, duration_hours: 2, duration_minutes: 120 }, { day: 3, duration_hours: 2 },
    ] })).toBe(true);
    expect(hasUniformFixedSlotDuration({ SessionDuration: 120, duration_hours: 2, day_time_slots: [] })).toBe(true);
    expect(hasUniformFixedSlotDuration({ SessionDuration: 90, duration_hours: 1.5, week1: 3, time1: '20:00', duration1: 90,
      day_time_slots: [{ day: 1, duration_hours: 1.5 }, { day: 3, duration_hours: 1.5 }] })).toBe(true);
  });
});
