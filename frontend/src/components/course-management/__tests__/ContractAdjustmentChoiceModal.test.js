import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import ContractAmendmentModal from '../ContractAmendmentModal.vue';

describe('ContractAdjustmentChoiceModal', () => {
  it('explains the independent adjustment workflows without exposing their APIs', () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, studentName: '測試學生', subject: 'math' },
    });

    expect(wrapper.text()).toContain('未付款，堂數改少');
    expect(wrapper.text()).toContain('把已上課紀錄轉到另一份合約');
    expect(wrapper.text()).toContain('提前結束／調整合約總堂數');
    expect(wrapper.text()).toContain('不需要目標課程');
    expect(wrapper.text()).toContain('不改任何課程堂數或金額');
    expect(wrapper.text()).not.toContain('billing-correction');
    expect(wrapper.text()).not.toContain('transfer-sessions');
  });

  it('routes each choice to the existing workflow', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, studentName: '測試學生', subject: 'math' },
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

});
