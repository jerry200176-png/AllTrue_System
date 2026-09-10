import { afterEach, describe, it, expect, vi } from 'vitest';
import { ref, nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { useCalendarLeaveExtra } from '../useCalendarLeaveExtra.js';

const courseManagementSource = readFileSync(resolve(import.meta.dirname, '../../../pages/CourseManagement.vue'), 'utf8');

describe('useCalendarLeaveExtra', () => {
  afterEach(() => vi.unstubAllGlobals());
  const baseDeps = () => ({
    branchId: ref(1),
    showModal: ref(true),
    modalForm: ref({
      student_id: 10, subject: 'Math', day_of_week: 3,
      start_time: '16:00', end_time: '18:00', teacher_id: 5,
      duration_hours: 2, class_type: 'one_on_one', action_date: '2026-06-10',
    }),
    editingCourseId: ref(99),
    contextMenu: ref({ show: false, x: 0, y: 0, course: null, date: null }),
    loadCourses: vi.fn().mockResolvedValue(undefined),
    getToken: vi.fn().mockResolvedValue('tok'),
    allStudents: ref([{ id: 10, name: '小明' }]),
    getSubjectLabel: (s) => s,
  });

  it('leaveDisplay reflects student name', async () => {
    const { leaveForm, leaveDisplay, openLeaveModal } = useCalendarLeaveExtra(baseDeps());
    openLeaveModal();
    leaveForm.value.student_id = 10;
    await nextTick();
    expect(leaveDisplay.value.studentName).toBe('小明');
  });

  it('openLeaveModal closes session edit modal', () => {
    const deps = baseDeps();
    const { openLeaveModal, showLeaveModal } = useCalendarLeaveExtra(deps);
    openLeaveModal();
    expect(deps.showModal.value).toBe(false);
    expect(showLeaveModal.value).toBe(true);
  });

  it('onContextLeave fills form from context menu course', () => {
    const deps = baseDeps();
    deps.contextMenu.value = {
      show: true, x: 1, y: 2,
      course: {
        id: 1, student_course_id: 2, student_id: 10, subject: 'Eng',
        teacher_id: 5, day_of_week: 4, start_time: '09:00', end_time: '11:00',
        duration_hours: 2, class_type: 'one_on_one', is_exception: false,
      },
      date: '2026-06-12',
    };
    const { onContextLeave, leaveForm, showLeaveModal } = useCalendarLeaveExtra(deps);
    onContextLeave();
    expect(leaveForm.value.schedule_date).toBe('2026-06-12');
    expect(leaveForm.value.course_id).toBe(1);
    expect(showLeaveModal.value).toBe(true);
  });

  it('checks the authoritative add-session contract before writing and does not auto-approve by default', async () => {
    const deps = baseDeps();
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ can_add: true, conflict_type: 'none', is_ended: false }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ message: '已調整加課堂次' }) });
    vi.stubGlobal('fetch', fetch);
    const alert = vi.fn();
    vi.stubGlobal('alert', alert);
    const { openExtraLesson, submitExtraLesson } = useCalendarLeaveExtra(deps);
    openExtraLesson();
    await vi.waitFor(() => expect(fetch.mock.calls[0][0]).toBe('/api/v1/student-classes/99/add-session/check'));
    await submitExtraLesson();

    expect(fetch.mock.calls[1][0]).toBe('/api/v1/student-classes/99/add-session');
    expect(fetch.mock.calls[0][1].body).toContain('duration_minutes');
    expect(fetch.mock.calls[1][1].body).toContain('teacher_id');
    expect(JSON.parse(fetch.mock.calls[1][1].body).auto_approve).toBe(false);
  });

  it('fails closed when add-session check has not authorized a past-session write', async () => {
    const deps = baseDeps();
    let resolveCheck;
    const fetch = vi.fn().mockImplementation(() => new Promise((resolve) => { resolveCheck = resolve; }));
    vi.stubGlobal('fetch', fetch);
    const alert = vi.fn();
    vi.stubGlobal('alert', alert);
    const { openExtraLesson, submitExtraLesson } = useCalendarLeaveExtra(deps);
    openExtraLesson();
    await submitExtraLesson();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(alert).toHaveBeenCalledWith('請先完成加課時段檢查後再送出');
    resolveCheck({ ok: true, json: async () => ({ can_add: true, conflict_type: 'none', is_ended: true }) });
  });

  it('uses the same leave preview and mutation boundary as Course Management', async () => {
    const deps = baseDeps();
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ leave_mode: 'monthly_bounded', future_dates_unchanged: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ leave_mode: 'monthly_bounded', undo: { schedule_id: 41, undo_window_seconds: 30 } }) });
    vi.stubGlobal('fetch', fetch);
    const toastRef = ref({ show: vi.fn() });
    const { openLeaveModal, submitLeave, leaveImpactPreview } = useCalendarLeaveExtra({ ...deps, toastRef });
    openLeaveModal();
    await vi.waitFor(() => expect(leaveImpactPreview.value.items).toContain('未來日期與合約結束日不變，不補尾'));
    expect(fetch.mock.calls[0][0]).toBe('/api/v1/schedules/leave-cascade-preview');
    await submitLeave();
    expect(fetch.mock.calls[1][0]).toBe('/api/v1/schedules');
    expect(toastRef.value.show).toHaveBeenCalledWith(expect.objectContaining({ onUndo: expect.any(Function) }));
    for (const endpoint of ['leave-cascade-preview', "'/api/v1/schedules'", 'undo-leave']) {
      expect(courseManagementSource).toContain(endpoint);
    }
  });

  it('fails closed on leave preview error and permits retry only after an authoritative preview succeeds', async () => {
    const deps = baseDeps();
    const fetch = vi.fn()
      .mockResolvedValueOnce({ ok: false, statusText: 'Service unavailable', json: async () => ({ message: '預覽服務暫時無法使用' }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ leave_mode: 'monthly_bounded', future_dates_unchanged: true }) })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ leave_mode: 'monthly_bounded' }) });
    vi.stubGlobal('fetch', fetch);
    const { openLeaveModal, refreshLeaveCascadePreview, submitLeave, leavePreviewError, leavePreviewReady } = useCalendarLeaveExtra(deps);
    openLeaveModal();
    await vi.waitFor(() => expect(leavePreviewError.value).toBe('預覽服務暫時無法使用'));
    await submitLeave();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(leavePreviewReady.value).toBe(false);
    await refreshLeaveCascadePreview();
    expect(leavePreviewReady.value).toBe(true);
    await submitLeave();
    expect(fetch.mock.calls[2][0]).toBe('/api/v1/schedules');
  });
});
