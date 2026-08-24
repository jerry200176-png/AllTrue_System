import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SplitContractWizard from '../SplitContractWizard.vue';

describe('SplitContractWizard', () => {
  const baseProps = {
    show: true,
    course: { student_name: '測試學生', subject_name: '理化', SessionCount: 10, Rate: 500 },
    sessions: [
      { id: 101, date: '2026-08-01', status: 'attended' },
      { id: 102, date: '2026-08-02', status: 'attended' },
      { id: 103, date: '2026-08-03', status: 'attended' },
    ],
    preview: {
      selected_session_count: 3,
      source_correction: { session_count: 5, charge: 2500 },
      settlement: { billable_session_count: 8, billable_charge: 4000, waived_session_count: 2, waived_charge: 1000 },
      new_course: { session_count: 3, charge: 1500, future_session_count: 0, transferred_session_count: 3 },
    },
    previewLoading: false,
    submitting: false,
    errorMessage: '',
  };

  function mountWizard(overrides = {}) {
    return mount(SplitContractWizard, { props: { ...baseProps, ...overrides } });
  }

  it('keeps the flow linear and defaults to carrying unused sessions forward', async () => {
    const wrapper = mountWizard({ preview: null });
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('#split-start-date').setValue('2026-09-01');
    await wrapper.find('button.primary').trigger('click');

    expect(wrapper.emitted('preview')[0][0]).toEqual({
      sessionIds: [101],
      startDate: '2026-09-01',
      carryForwardUnused: true,
    });
    expect(wrapper.text()).toContain('第 2 步');
  });

  it('shows server-calculated old and new contract values before confirmation', async () => {
    const wrapper = mountWizard();
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('#split-start-date').setValue('2026-09-01');
    await wrapper.find('button.primary').trigger('click');

    expect(wrapper.text()).toContain('舊合約更正後');
    expect(wrapper.text()).toContain('5 堂');
    expect(wrapper.text()).toContain('$2,500');
    expect(wrapper.text()).toContain('新合約');
  });

  it('requires a reason and emits one atomic submit payload on the final step', async () => {
    const wrapper = mountWizard();
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('#split-start-date').setValue('2026-09-01');
    await wrapper.find('button.primary').trigger('click');
    await wrapper.findAll('button.primary')[0].trigger('click');

    await wrapper.find('#split-reason').setValue('主任確認已上課紀錄歸入新合約');
    await wrapper.find('button.primary').trigger('click');

    expect(wrapper.emitted('submit')[0][0]).toEqual({
      sessionIds: [101],
      startDate: '2026-09-01',
      carryForwardUnused: true,
      reason: '主任確認已上課紀錄歸入新合約',
    });
  });

  it('switching to waived mode shows the settlement summary and emits carryForwardUnused: false', async () => {
    const wrapper = mountWizard({
      preview: {
        selected_session_count: 3,
        source_correction: { session_count: 5, charge: 2500 },
        settlement: { billable_session_count: 8, billable_charge: 4000, waived_session_count: 2, waived_charge: 1000 },
        new_course: { session_count: 3, charge: 1500, future_session_count: 0, transferred_session_count: 3 },
      },
    });
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('#split-start-date').setValue('2026-09-01');
    await wrapper.findAll('input[name="settlement-mode"]')[1].setValue(true);
    await wrapper.find('button.primary').trigger('click');

    expect(wrapper.emitted('preview')[0][0]).toEqual({
      sessionIds: [101],
      startDate: '2026-09-01',
      carryForwardUnused: false,
    });
    expect(wrapper.text()).toContain('本次應收 $4,000');
    expect(wrapper.text()).toContain('放棄未上 2 堂');
  });

  it('keeps API failures visible inside the wizard', () => {
    const wrapper = mountWizard({ errorMessage: '此課程已有有效收款紀錄，請先走帳務流程。' });
    expect(wrapper.find('[role="alert"]').text()).toContain('有效收款紀錄');
  });
});
