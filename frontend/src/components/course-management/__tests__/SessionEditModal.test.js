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

describe('SessionEditModal tutoring fee (in-app #443)', () => {
  const base = { show: true, mode: 'edit-note-time', submitting: false, timeOptions: [], makeupLoading: false, computeEndTime: () => '' };
  const mk = (classType) => mount(SessionEditModal, {
    props: { ...base, form: { student_name: 's', session_date: '2026-08-08', start_time: '13:00', end_time: '15:00', edit_start_time: '13:00', edit_end_time: '15:00', current_status: 'scheduled', contract_rate: 500, contract_session_duration: 120, contract_rate_unit: 'session', course: { class_type: classType } } },
  });
  it('shows no per-session fee for tutoring', () => {
    expect(mk('tutoring').text()).toContain('輔導課不另收費');
    expect(mk('tutoring').text()).not.toContain('NT$ 500');
  });
  it('still shows the fee for a one-on-one course', () => {
    expect(mk('one_on_one').text()).toContain('NT$ 500');
  });
});

describe('SessionEditModal blocked reschedule (#4991 line 3)', () => {
  const blockers = [
    { source: 'class_session', source_id: 7, course_id: 31, student_name: '甲', subject_name: '數學', start_time: '16:00:00', end_time: '17:00:00' },
    { source: 'schedule', source_id: 9, course_id: 0, student_name: '', start_time: '16:00', end_time: '17:00' },
  ];
  const mountBlocked = (mode) => mount(SessionEditModal, {
    props: {
      show: true, mode, submitting: false, timeOptions: [], makeupLoading: false, computeEndTime: () => '',
      form: { student_name: '測試學生', session_date: '2026-08-08', start_time: '13:00', end_time: '15:00', current_status: 'scheduled',
        blocked: { message: '調課失敗：此時段已有其他課', blockers, date: '2026-08-10' } },
    },
  });

  it('lists each blocking lesson and opens its course', async () => {
    const wrapper = mountBlocked('reschedule');
    const items = wrapper.findAll('.se-blocked-list li');
    expect(items).toHaveLength(2);
    expect(items[0].text()).toContain('甲・數學，2026-08-10 16:00–17:00');
    expect(items[1].text()).toContain('另一位學生');
    expect(items[1].find('.se-open-blocker').exists()).toBe(false);
    await items[0].find('.se-open-blocker').trigger('click');
    expect(wrapper.emitted('open-course')).toEqual([[blockers[0]]]);
  });

  it('stays hidden outside the reschedule and substitute steps', () => {
    expect(mountBlocked('menu').find('.se-blocked').exists()).toBe(false);
    expect(mountBlocked('substitute').find('.se-blocked').exists()).toBe(true);
  });
});
