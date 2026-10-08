import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import TransferSessionsModal from '../TransferSessionsModal.vue';

describe('TransferSessionsModal', () => {
  function mountModal(overrides = {}) {
    return mount(TransferSessionsModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        sessions: [
          { id: 101, date: '2026-08-02', status: 'attended' },
          { id: 102, date: '2026-08-09', status: 'attended' },
          { id: 103, date: '2026-08-16', status: 'scheduled' },
        ],
        targetCourses: [
          { id: 1849, subject_name: '數學', teacher_name: '王老師', start_date: '2026-08-02' },
          { id: 1850, subject_name: '英文', teacher_name: '林老師', start_date: '2026-08-03' },
        ],
        targetCoursesLoading: false,
        submitting: false,
        errorMessage: '',
        ...overrides,
      },
    });
  }

  it('disables submit until a target course id and at least one session are chosen', async () => {
    const wrapper = mountModal();
    const submitBtn = wrapper.find('button.primary');
    expect(submitBtn.attributes('disabled')).toBeDefined();

    await wrapper.find('input[type="checkbox"]').setValue(true);
    expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined(); // still no target id

    await wrapper.findAll('.target-course-option')[0].trigger('click');
    expect(wrapper.find('button.primary').attributes('disabled')).toBeUndefined();
  });

  it('emits submit with the selected session ids (as numbers) and target course id', async () => {
    const wrapper = mountModal();
    await wrapper.findAll('.target-course-option')[0].trigger('click');
    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    await checkboxes[0].setValue(true);
    await checkboxes[1].setValue(true);

    await wrapper.find('button.primary').trigger('click');

    const emitted = wrapper.emitted('submit');
    expect(emitted).toBeTruthy();
    expect(emitted[0][0]).toEqual({ targetCourseId: 1849, sessionIds: [101, 102] });
  });

  it('allows selecting a target course instead of memorizing its id', async () => {
    const wrapper = mountModal();
    await wrapper.findAll('.target-course-option')[0].trigger('click');
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);

    await wrapper.find('button.primary').trigger('click');

    expect(wrapper.emitted('submit')[0][0]).toEqual({ targetCourseId: 1849, sessionIds: [101] });
    expect(wrapper.text()).toContain('數學｜王老師');
  });

  it('filters target courses by the search text', async () => {
    const wrapper = mountModal();
    await wrapper.find('#transfer-target-course').setValue('林老師');

    expect(wrapper.findAll('.target-course-option')).toHaveLength(1);
    expect(wrapper.text()).toContain('英文｜林老師');
    expect(wrapper.text()).not.toContain('數學｜王老師');
  });

  it('resets the form every time it reopens', async () => {
    const wrapper = mountModal();
    await wrapper.find('input[type="text"]').setValue('1849');
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);

    await wrapper.setProps({ show: false });
    await wrapper.setProps({ show: true });

    expect(wrapper.find('input[type="text"]').element.value).toBe('');
    expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(false);
  });

  it('shows the error message when provided', () => {
    const wrapper = mountModal({ errorMessage: '轉移失敗：目標課程與來源課程的學生不一致，拒絕轉移。' });
    expect(wrapper.text()).toContain('目標課程與來源課程的學生不一致');
  });

  it('requires a reason and identifies evidence-backed cancelled sessions', async () => {
    const wrapper = mountModal({
      sessions: [
        { id: 201, date: '2026-08-08', status: 'cancelled_recoverable', recoverableCancelled: true },
      ],
    });
    await wrapper.find('.target-course-option').trigger('click');
    await wrapper.find('input[type="checkbox"]').setValue(true);

    expect(wrapper.text()).toContain('已取消（可恢復）');
    expect(wrapper.text()).toContain('仍保留評量／點名證據');
    expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined();

    await wrapper.find('#transfer-recovery-reason').setValue('原合約誤標取消，依歷史紀錄恢復');
    expect(wrapper.find('button.primary').attributes('disabled')).toBeUndefined();
    await wrapper.find('button.primary').trigger('click');
    expect(wrapper.emitted('submit')[0][0]).toEqual({
      targetCourseId: 1849,
      sessionIds: [201],
      reason: '原合約誤標取消，依歷史紀錄恢復',
    });
  });

  it('offers a button to the full target course and emits its id (in-app #379)', async () => {
    const wrapper = mountModal({
      errorMessage: '目標課程堂數已滿（8/8）。請先在目標課程加買堂數，再轉課。',
      errorNextActions: [{ code: 'open_target_purchase', label: '前往目標課程加購', available: true, student_class_id: 1849 }],
    });
    const btn = wrapper.find('[data-testid="transfer-open-target-purchase"]');
    expect(btn.text()).toBe('前往目標課程加購');
    await btn.trigger('click');
    expect(wrapper.emitted('open-target-purchase')?.[0]).toEqual([1849]);
  });

  it('shows no purchase button without that next action', () => {
    const wrapper = mountModal({ errorMessage: '轉移失敗', errorNextActions: [] });
    expect(wrapper.find('[data-testid="transfer-open-target-purchase"]').exists()).toBe(false);
  });
});
