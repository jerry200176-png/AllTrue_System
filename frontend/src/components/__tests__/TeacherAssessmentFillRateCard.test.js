import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TeacherAssessmentFillRateCard from '../TeacherAssessmentFillRateCard.vue';

describe('TeacherAssessmentFillRateCard', () => {
  it('loads the selected branch and presents follow-up rows before stable rows', async () => {
    const fetchReport = vi.fn().mockResolvedValue({
      teachers: [
        { teacher_id: 1, teacher_name: '穩定老師', sessions_attended: 10, learning_records_filled: 10, fill_rate_pct: 100 },
        { teacher_id: 2, teacher_name: '待跟進老師', sessions_attended: 10, learning_records_filled: 2, fill_rate_pct: 20 },
      ],
    });
    const wrapper = mount(TeacherAssessmentFillRateCard, {
      props: { branchId: 9, fetchReport },
    });

    await flushPromises();

    expect(fetchReport).toHaveBeenCalledWith({ branch_id: 9, days: 14 });
    expect(wrapper.text()).toContain('待跟進老師');
    expect(wrapper.text()).toContain('需要跟進');
    expect(wrapper.find('tbody tr:first-child th').text()).toContain('待跟進老師');
    expect(wrapper.text()).toContain('穩定完成');
  });

  it('shows a retry state when the report endpoint fails', async () => {
    const fetchReport = vi.fn().mockRejectedValue(new Error('服務暫時忙碌'));
    const wrapper = mount(TeacherAssessmentFillRateCard, {
      props: { branchId: 9, fetchReport },
    });

    await flushPromises();

    expect(wrapper.get('[role="alert"]').text()).toContain('服務暫時忙碌');
    expect(wrapper.get('button').text()).toContain('再試一次');
  });
});
