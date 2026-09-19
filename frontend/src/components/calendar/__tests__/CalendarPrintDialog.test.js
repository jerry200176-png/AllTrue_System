import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const api = vi.hoisted(() => ({
  courses: [{ id: 1, student_id: 2, student_name: '王小明', teacher_id: 9, teacher_name: '林老師', subject: '數學', class_type: '一對一', room_id: 3, branch_id: 11 }],
  schedules: [
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'leave' },
    { student_class_id: 1, date: '2026-09-15', start_time: '16:00', type: 'extra' },
  ],
  sessions: [{ id: 4, student_class_id: 1, session_date: '2026-09-15', start_time: '16:00', end_time: '18:00', status: 'scheduled', teacher_id: 9, student_name: '王小明', branch_id: 11 }],
  events: [],
  projection: null,
}));

vi.mock('../../../lib/calendarCourseLoad', () => ({
  fetchCalendarCoursesAndSchedulesParallel: vi.fn(async ({ fetchCourses, fetchSchedules }) => ({ courses: await fetchCourses(), schedules: await fetchSchedules() })),
  fetchCalendarStudentClassesApi: vi.fn(async (options) => { api.courseOptions = options; return { list: api.courses, apiSucceeded: true }; }),
  fetchCalendarSchedulesApi: vi.fn(async (options) => { api.scheduleOptions = options; return { list: api.schedules, apiSucceeded: true }; }),
}));
vi.mock('../../../lib/classSessionsApi', () => ({
  fetchClassSessionsProjection: vi.fn(async (options) => { api.projectionOptions = options; return api.projection || { byClass: { 1: api.sessions } }; }),
}));
vi.mock('../../../lib/pagedFetchAll', () => ({ fetchAllPages: vi.fn() }));
vi.mock('../../../lib/adoptionTelemetry.js', () => ({
  adoptionErrorType: vi.fn(() => 'network'),
  trackAdoptionEvent: vi.fn(async (...args) => { api.events.push(args); }),
}));

import CalendarPrintDialog from '../CalendarPrintDialog.vue';

describe('CalendarPrintDialog acceptance contract', () => {
  let print;
  beforeEach(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'test-token' }));
    print = vi.spyOn(window, 'print').mockImplementation(() => {});
    document.body.innerHTML = '';
    api.events = [];
    api.projection = null;
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
    expect(api.courseOptions).toMatchObject({ schedStart: '2026-09-14', schedEnd: '2026-09-20' });
    expect(api.scheduleOptions).toMatchObject({ schedStart: '2026-09-14', schedEnd: '2026-09-20' });
    expect(api.projectionOptions).toMatchObject({ start: '2026-09-14', end: '2026-09-20' });
    const previewEvent = api.events.find(([event]) => event === 'calendar_print_preview_opened');
    expect(previewEvent).toBeTruthy();
    expect(previewEvent[2]).toMatchObject({ mode: 'week', range_start: '2026-09-14', range_end: '2026-09-20', row_count_bucket: '1-25', result: 'success' });
    expect(JSON.stringify(previewEvent[2])).not.toMatch(/王小明|數學|電話|地址/);
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

  it('fallback timer removes print mode when afterprint is not emitted', async () => {
    vi.useFakeTimers();
    const wrapper = mount(CalendarPrintDialog, { attachTo: document.body, props: { open: false, branchId: 11, initialDate: '2026-09-15' } });
    await wrapper.setProps({ open: true });
    await flushPromises();
    document.body.querySelector('.calendar-print-actions button:last-child').click();
    await flushPromises();
    expect(document.body.classList.contains('calendar-print-active')).toBe(true);
    await vi.advanceTimersByTimeAsync(120000);
    expect(document.body.classList.contains('calendar-print-active')).toBe(false);
    expect(document.head.querySelector('style[data-calendar-print-page]')).toBeNull();
    wrapper.unmount();
  });

  it('fails closed on an error and distinguishes an empty successful range', async () => {
    const previousSessions = api.sessions;
    api.sessions = [];
    const wrapper = mount(CalendarPrintDialog, { attachTo: document.body, props: { open: false, branchId: 11, initialDate: '2026-09-15' } });
    await wrapper.setProps({ open: true });
    await flushPromises();
    expect(document.body.querySelector('.calendar-print-state')?.textContent).toContain('此範圍沒有可列印的課程');

    api.projection = Promise.reject(new Error('projection unavailable'));
    await wrapper.setProps({ open: false });
    await wrapper.setProps({ open: true });
    await flushPromises();
    expect(document.body.querySelector('[role="alert"]')?.textContent).toContain('projection unavailable');
    expect(api.events.some(([event]) => event === 'calendar_print_failed')).toBe(true);
    api.sessions = previousSessions;
    wrapper.unmount();
  });

  it('does not let a stale earlier response replace the newer month range', async () => {
    let resolveOld;
    let resolveNew;
    const oldProjection = new Promise((resolve) => { resolveOld = resolve; });
    const newProjection = new Promise((resolve) => { resolveNew = resolve; });
    const projections = [oldProjection, newProjection];
    const { fetchClassSessionsProjection } = await import('../../../lib/classSessionsApi');
    fetchClassSessionsProjection.mockImplementationOnce(() => projections.shift()).mockImplementationOnce(() => projections.shift());
    const wrapper = mount(CalendarPrintDialog, { attachTo: document.body, props: { open: false, branchId: 11, initialDate: '2026-09-15' } });
    await wrapper.setProps({ open: true });
    await wrapper.vm.$nextTick();
    const periodSelect = document.body.querySelector('.calendar-print-toolbar select');
    periodSelect.value = 'month';
    periodSelect.dispatchEvent(new Event('change', { bubbles: true }));
    await wrapper.vm.$nextTick();
    resolveNew({ byClass: { 1: api.sessions } });
    await flushPromises();
    resolveOld({ byClass: { 1: [{ ...api.sessions[0], student_name: '舊回應' }] } });
    await flushPromises();
    expect(document.body.textContent).toContain('王小明');
    expect(document.body.textContent).not.toContain('舊回應');
    wrapper.unmount();
  });
});
