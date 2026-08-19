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

    await wrapper.find('input[type="text"]').setValue('1849');
    expect(wrapper.find('button.primary').attributes('disabled')).toBeUndefined();
  });

  it('emits submit with the selected session ids (as numbers) and target course id', async () => {
    const wrapper = mountModal();
    await wrapper.find('input[type="text"]').setValue('1849');
    const checkboxes = wrapper.findAll('input[type="checkbox"]');
    await checkboxes[0].setValue(true);
    await checkboxes[1].setValue(true);

    await wrapper.find('button.primary').trigger('click');

    const emitted = wrapper.emitted('submit');
    expect(emitted).toBeTruthy();
    expect(emitted[0][0]).toEqual({ targetCourseId: 1849, sessionIds: [101, 102] });
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
});
