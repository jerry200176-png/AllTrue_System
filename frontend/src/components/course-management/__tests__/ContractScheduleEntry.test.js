import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';

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
});
