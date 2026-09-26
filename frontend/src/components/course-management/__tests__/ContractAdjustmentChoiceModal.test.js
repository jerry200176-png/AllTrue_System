import { describe, it, expect } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import ContractAmendmentModal from '../ContractAmendmentModal.vue';

describe('ContractAdjustmentChoiceModal', () => {
  it('explains the independent adjustment workflows without exposing their APIs', () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, studentName: '測試學生', subject: 'math', paymentStatus: 'unpaid' },
    });

    expect(wrapper.text()).toContain('未付款，堂數改少');
    expect(wrapper.text()).toContain('把已上課紀錄轉到另一份合約');
    expect(wrapper.text()).toContain('提前結束／調整合約總堂數');
    expect(wrapper.text()).toContain('不需要目標課程');
    expect(wrapper.text()).toContain('不改任何課程堂數或金額');
    expect(wrapper.text()).not.toContain('billing-correction');
    expect(wrapper.text()).not.toContain('transfer-sessions');
  });

  it.each(['paid', 'partial', 'pending_report', ''])('blocks billing correction for %s payment state', async (paymentStatus) => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, paymentStatus, billingAvailable: true },
    });
    const billing = wrapper.findAll('.choice-card')[0];
    expect(billing.attributes('disabled')).toBeDefined();
    await billing.trigger('click');
    expect(wrapper.emitted('choose')).toBeUndefined();
  });

  it('fails closed when tutoring or anomaly policy marks billing unavailable', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, paymentStatus: 'unpaid', billingAvailable: false },
    });
    expect(wrapper.findAll('.choice-card')[0].attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('不適用未付款堂數更正');
  });

  it('routes each choice to the existing workflow', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, studentName: '測試學生', subject: 'math', paymentStatus: 'unpaid' },
    });
    const choices = wrapper.findAll('.choice-card');

    await choices[0].trigger('click');
    await choices[1].trigger('click');
    await choices[2].trigger('click');

    expect(wrapper.emitted('choose')).toEqual([['billing'], ['amendment'], ['transfer']]);
  });

  it('can be dismissed without selecting an adjustment', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true },
    });

    await wrapper.find('button.ghost').trigger('click');

    expect(wrapper.emitted('close')).toHaveLength(1);
  });
});

describe('ContractAmendmentModal', () => {
  const course = {
    id: 77, student_name: '測試學生', subject_name: '數學',
    sessions_purchased: 8, remaining_sessions: 5,
  };

  it('requires a preview before confirming and shows the independent no-target flow', async () => {
    const wrapper = mount(ContractAmendmentModal, { props: { show: true, course } });
    expect(wrapper.text()).toContain('不需要目標課程');
    expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined();

    await wrapper.find('#amendment-new-count').setValue(3);
    await wrapper.find('button.secondary').trigger('click');
    expect(wrapper.emitted('preview')).toEqual([[3]]);
  });

  it('defaults new session count below the current total and above completed usage', async () => {
    const wrapper = mount(ContractAmendmentModal, {
      props: {
        show: true,
        course: { id: 88, student_name: '沈柏宇', subject_name: '英文', sessions_purchased: 4, remaining_sessions: 2 },
      },
    });
    await flushPromises();

    expect(Number(wrapper.find('#amendment-new-count').element.value)).toBe(3);
  });

  it('shows forfeiture warning when preview closes the contract', async () => {
    const wrapper = mount(ContractAmendmentModal, {
      props: {
        show: true,
        course,
        preview: {
          original_session_count: 4,
          new_session_count: 2,
          original_remaining_sessions: 2,
          new_remaining_sessions: 0,
          forfeited_sessions: 2,
          closes_contract: true,
          affected_future_scheduled: [],
          financial: { invoice_count: 0, payment_count: 0, payment_report_count: 0 },
          financial_note: '帳務不變',
        },
      },
    });

    expect(wrapper.text()).toContain('放棄 2 堂未使用額度');
    expect(wrapper.text()).toContain('合約會提前結束');
  });

});
