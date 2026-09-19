import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const api = vi.hoisted(() => ({
  courses: [{ id: 1, student_id: 2, student_name: '王小明', teacher_id: 9, teacher_name: '林老師', subject: '數學', class_type: '一對一', room_id: 3, branch_id: 11 }],
  schedules: [
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'leave' },
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'extra' },
  ],
  sessions: [{ id: 4, student_class_id: 1, session_date: '2026-09-15', start_time: '16:00', end_time: '18:00', status: 'scheduled', teacher_id: 9, student_name: '王小明', branch_id: 11 }],
}));

vi.mock('../../../lib/calendarCourseLoad', () => ({
  fetchCalendarCoursesAndSchedulesParallel: vi.fn(async () => ({
    courses: { list: api.courses, apiSucceeded: true },
    schedules: { list: api.schedules, apiSucceeded: true },
  })),
  fetchCalendarStudentClassesApi: vi.fn(),
  fetchCalendarSchedulesApi: vi.fn(),
}));
vi.mock('../../../lib/classSessionsApi', () => ({
  fetchClassSessionsProjection: vi.fn(async () => ({ byClass: { 1: api.sessions } })),
}));
vi.mock('../../../lib/pagedFetchAll', () => ({ fetchAllPages: vi.fn() }));

import CalendarPrintDialog from '../CalendarPrintDialog.vue';

describe('CalendarPrintDialog acceptance contract', () => {
  let print;
  beforeEach(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
    print = vi.spyOn(window, 'print').mockImplementation(() => {});
    document.body.innerHTML = '';
  });
  afterEach(() => { print.mockRestore(); vi.useRealTimers(); localStorage.clear(); });

  it('renders week summary and complete daily detail with all statuses selected', async () => {
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const wrapper = mount(CalendarPrintDialog, {
      attachTo: document.body,
      props: { open: false, branchId: 11, branchName: '台北分校', initialDate: '2026-09-15', rooms: [{ id: 3, name: 'A101' }], teachers: [{ id: 9, username: '林老師' }] },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    expect(document.body.textContent).toContain('週總覽');
    expect(document.body.textContent).toContain('王小明');
    expect(document.body.textContent).toContain('請假');
    expect(document.body.textContent).toContain('補課');
    expect(document.body.textContent).toContain('台北分校');
    expect(document.body.querySelectorAll('.calendar-print-statuses input:checked')).toHaveLength(5);
    expect(document.body.textContent).not.toMatch(/電話|地址|帳務|備註/);
    wrapper.unmount();
  });

  it('switches to month overview and keeps daily details', async () => {
    const wrapper = mount(CalendarPrintDialog, { attachTo: document.body, props: { open: false, branchId: 11, initialDate: '2026-09-15' } });
    await wrapper.setProps({ open: true });
    await flushPromises();
    const periodSelect = document.body.querySelector('.calendar-print-toolbar select');
    periodSelect.value = 'month';
    periodSelect.dispatchEvent(new Event('change', { bubbles: true }));
    await wrapper.vm.$nextTick();
    await flushPromises();
    expect(document.body.textContent).toContain('月總覽');
    expect(document.body.textContent).toContain('明細合計');
    expect(document.body.querySelectorAll('table').length).toBeGreaterThan(0);
    wrapper.unmount();
  });

  it('installs print mode, calls print, and restores opener after cleanup', async () => {
    vi.useFakeTimers();
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    const wrapper = mount(CalendarPrintDialog, { attachTo: document.body, props: { open: false, branchId: 11, initialDate: '2026-09-15' } });
    await wrapper.setProps({ open: true });
    await flushPromises();
    document.body.querySelector('.calendar-print-actions button:last-child').click();
    await flushPromises();
    expect(print).toHaveBeenCalledOnce();
    expect(document.body.classList.contains('calendar-print-active')).toBe(true);
    window.dispatchEvent(new Event('afterprint'));
    expect(document.body.classList.contains('calendar-print-active')).toBe(false);
    await wrapper.setProps({ open: false });
    await flushPromises();
    expect(document.activeElement).toBe(opener);
    wrapper.unmount();
  });
});
