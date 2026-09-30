import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RenewMonthlyModal from '../course-management/RenewMonthlyModal.vue';

const form = () => ({ student_name: '學生', subject: 'Math', current_end_date: '2026-09-10', end_date: '2026-09-30', months: 1,
  settlement_day: 31, original_amount: 4500, discount: { type: 'NONE', value: '0', reason: '' }, preview_status: 'ready',
  preview_end_date: '2026-09-30', preview_start_date: '2026-09-11', preview_billing_period: '2026-09', preview_due_date: '2026-09-30', preview_blocked: false });

describe('monthly renewal period review', () => {
  it('shows the actual new start, billing month and due date and labels the fee as an estimate', () => {
    const wrapper = mount(RenewMonthlyModal, { props: { show: true, form: form() } });
    expect(wrapper.text()).toContain('新期開始日'); expect(wrapper.text()).toContain('2026-09-11');
    expect(wrapper.text()).toContain('帳單月份'); expect(wrapper.text()).toContain('2026-09');
    expect(wrapper.text()).toContain('繳費到期日'); expect(wrapper.text()).toContain('2026-09-30');
    expect(wrapper.text()).toContain('預估'); expect(wrapper.text()).not.toContain('實收');
    wrapper.unmount();
  });

  it.each(['loading', 'error'])('prevents submitting while preview is %s', async (status) => {
    const wrapper = mount(RenewMonthlyModal, { props: { show: true, form: { ...form(), preview_status: status } } });
    expect(wrapper.get('button.primary').attributes('disabled')).toBeDefined();
    await wrapper.get('button.primary').trigger('click'); expect(wrapper.emitted('submit')).toBeUndefined();
    wrapper.unmount();
  });

  it('prevents submitting a blocked or different-date preview', async () => {
    const wrapper = mount(RenewMonthlyModal, { props: { show: true, form: { ...form(), preview_blocked: true }, warnings: [{ code: 'outside', message: '請先核對已上堂次' }] } });
    expect(wrapper.get('button.primary').attributes('disabled')).toBeDefined();
    expect(wrapper.get('[role="alert"]').text()).toContain('請先核對已上堂次');
    await wrapper.setProps({ form: { ...form(), preview_end_date: '2026-10-31' } });
    expect(wrapper.get('button.primary').attributes('disabled')).toBeDefined();
    await wrapper.setProps({ form: form() });
    expect(wrapper.get('button.primary').attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });
});
