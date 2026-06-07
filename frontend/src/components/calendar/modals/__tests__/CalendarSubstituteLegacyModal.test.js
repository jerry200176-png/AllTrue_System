import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CalendarSubstituteLegacyModal from '../CalendarSubstituteLegacyModal.vue';

const form = { substitute_teacher_id: '', reason: '', original_teacher_name: '王老師', session_date: '2026-06-07', start_time: '16:00', end_time: '18:00' };

describe('CalendarSubstituteLegacyModal', () => {
  it('renders teacher list and disables submit without selection', () => {
    const w = mount(CalendarSubstituteLegacyModal, {
      props: { show: true, form, teachers: [{ id: 1, username: '李老師' }], submitting: false },
    });
    expect(w.find('option[value="1"]').text()).toBe('李老師');
    expect(w.find('.primary').attributes('disabled')).toBeDefined();
  });

  it('enables submit when substitute selected', async () => {
    const w = mount(CalendarSubstituteLegacyModal, {
      props: { show: true, form: { ...form, substitute_teacher_id: '1' }, teachers: [{ id: 1, username: '李老師' }] },
    });
    expect(w.find('.primary').attributes('disabled')).toBeUndefined();
  });

  it('shows submitting label', () => {
    const w = mount(CalendarSubstituteLegacyModal, {
      props: { show: true, form: { ...form, substitute_teacher_id: '1' }, submitting: true },
    });
    expect(w.find('.primary').text()).toContain('處理中');
  });
});
