import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SessionEditModal from '../SessionEditModal.vue';

/**
 * Regression coverage for the "調課" vs "備註 / 時段" button confusion (director-
 * reported: 興隆分校, tried to move a Saturday session to Thursday, couldn't get
 * it to go through). Root cause: two adjacent, similarly-styled buttons -- one
 * moves the session to a different date+time ("調課"), the other only edits the
 * time on the SAME day and has no date field at all ("備註 / 時段") -- with
 * nothing distinguishing which is which. This test pins the clarified labels
 * and tooltips so the distinction can't silently regress back to ambiguous text.
 */
describe('SessionEditModal menu actions', () => {
  function mountMenu(overrides = {}) {
    return mount(SessionEditModal, {
      props: {
        show: true,
        mode: 'menu',
        submitting: false,
        timeOptions: [],
        makeupLoading: false,
        computeEndTime: () => '',
        form: {
          student_name: '測試學生',
          session_date: '2026-08-08',
          start_time: '13:00',
          end_time: '15:00',
          current_status: 'scheduled',
          ...overrides,
        },
      },
    });
  }

  it('labels the reschedule (change-date) action distinctly from the same-day time/note action', () => {
    const wrapper = mountMenu();
    const rescheduleBtn = wrapper.find('.se-btn-reschedule');
    const editNoteBtn = wrapper.find('.se-btn-edit-note');

    expect(rescheduleBtn.exists()).toBe(true);
    expect(editNoteBtn.exists()).toBe(true);
    expect(rescheduleBtn.text()).toContain('換日期');
    expect(editNoteBtn.text()).not.toContain('換日期');
  });

  it('gives each action a tooltip explaining whether it can change the date', () => {
    const wrapper = mountMenu();
    const rescheduleBtn = wrapper.find('.se-btn-reschedule');
    const editNoteBtn = wrapper.find('.se-btn-edit-note');

    expect(rescheduleBtn.attributes('title')).toMatch(/不同日期/);
    expect(editNoteBtn.attributes('title')).toMatch(/無法換到別的日期/);
  });

  it('shows an inline hint pointing users to the right action', () => {
    const wrapper = mountMenu();
    expect(wrapper.find('.se-action-hint').text()).toContain('調課');
  });

  it('emits start-reschedule and start-edit-note-time from their respective buttons', async () => {
    const wrapper = mountMenu();
    await wrapper.find('.se-btn-reschedule').trigger('click');
    expect(wrapper.emitted('start-reschedule')).toBeTruthy();

    await wrapper.find('.se-btn-edit-note').trigger('click');
    expect(wrapper.emitted('start-edit-note-time')).toBeTruthy();
  });
});
