import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import ContractAmendmentModal from '../ContractAmendmentModal.vue';

describe('ContractAdjustmentChoiceModal', () => {
  it('explains the independent adjustment workflows by intent without exposing their APIs', () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        paymentStatus: 'unpaid',
        billingAvailable: true,
      },
    });

    expect(wrapper.text()).toContain('課還要繼續，只是堂數／金額開錯');
    expect(wrapper.text()).toContain('上課紀錄掛錯合約，要搬到另一份');
    expect(wrapper.text()).toContain('學生不上了，這期合約要結束');
    expect(wrapper.text()).toContain('不會自動改金額或退費');
    expect(wrapper.text()).toContain('不改任何課程堂數或金額');
    expect(wrapper.text()).not.toContain('billing-correction');
    expect(wrapper.text()).not.toContain('transfer-sessions');
  });

  it('routes each choice to the existing workflow when unpaid billing is available', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        paymentStatus: 'unpaid',
        billingAvailable: true,
      },
    });
    const choices = wrapper.findAll('.choice-card');

    await choices[0].trigger('click');
    await choices[1].trigger('click');
    await choices[2].trigger('click');

    expect(wrapper.emitted('choose')).toEqual([['billing'], ['amendment'], ['transfer']]);
  });

  it('disables unpaid billing correction when the course is already paid', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        paymentStatus: 'paid',
        billingAvailable: true,
      },
    });

    const billingChoice = wrapper.findAll('.choice-card')[0];
    expect(billingChoice.attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('已繳費不可用此流程');
    expect(wrapper.text()).toContain('這期合約要結束');

    await billingChoice.trigger('click');
    expect(wrapper.emitted('choose')).toBeUndefined();

    await wrapper.findAll('.choice-card')[1].trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['amendment']]);
  });

  it('disables unpaid billing correction when a payment report is pending', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        paymentStatus: 'pending_report',
        billingAvailable: true,
      },
    });

    expect(wrapper.findAll('.choice-card')[0].attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('尚有待對帳繳費回報');

    await wrapper.findAll('.choice-card')[0].trigger('click');
    expect(wrapper.emitted('choose')).toBeUndefined();
  });

  it('can be dismissed without selecting an adjustment', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, paymentStatus: 'unpaid', billingAvailable: true },
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

});
