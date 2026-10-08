import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useSessionEditFlow } from '../useSessionEditFlow';

// Regression coverage for #942 (in-app #177): clicking a session chip whose real
// ClassSession is missing from the local cache (duplicate / overlapping sessions or
// a stale load) used to dead-end on "此堂次資料尚未載入". openSessionEdit must now
// reload the course's sessions and retry before alerting.

function makeRow(overrides = {}) {
  return {
    id: 555,
    studentClassId: 42,
    startTime: '16:00',
    endTime: '18:00',
    status: 'scheduled',
    teacherId: 7,
    teacherName: 'T',
    attendanceSignInAt: null,
    learningRecordStatus: '',
    note: '',
    sessionCharge: 1000,
    contractRate: 1000,
    contractSessionDuration: 120,
    contractRateUnit: 'session',
    ...overrides,
  };
}

function buildFlow(deps = {}) {
  return useSessionEditFlow({
    supabase: { auth: { getSession: vi.fn().mockResolvedValue({ data: { session: { access_token: 't' } } }) } },
    branchId: { value: 16 },
    computeEndTime: vi.fn(),
    normalizeTo30Min: vi.fn(),
    dayOfWeekFromDate: vi.fn(),
    formatAttendanceTooltipTime: () => '',
    updateLocalSessionRow: vi.fn(),
    ensureCompletedSessionDatesLoaded: vi.fn(),
    displaySessions: () => [],
    todayYmd: { value: '2026-06-27' },
    rescheduleCourse: vi.fn(),
    rescheduleForm: { value: {} },
    fetchMakeupSlots: vi.fn(),
    loadCourses: vi.fn(),
    openQuickAddSessionModal: vi.fn(),
    ...deps,
  });
}

describe('openSessionEdit reload-on-miss (#177)', () => {
  beforeEach(() => {
    vi.spyOn(globalThis, 'alert').mockImplementation(() => {});
  });

  it('reloads the course and opens the modal when the row is initially missing', async () => {
    const course = { id: 42, student_name: '沈宇璿', subject: '化學', duration_hours: 2 };
    let loaded = false;
    const getSessionDisplayRow = vi.fn(() => (loaded ? makeRow() : null));
    const reloadCourseSessions = vi.fn(async () => { loaded = true; return true; });

    const flow = buildFlow({
      getSessionDisplayRow,
      reloadCourseSessions,
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(course, '2026-06-27', 555, { id: 555, startTime: '16:00' });

    expect(reloadCourseSessions).toHaveBeenCalledTimes(1);
    expect(globalThis.alert).not.toHaveBeenCalled();
    expect(flow.showSessionEditModal.value).toBe(true);
    expect(flow.sessionEditForm.value.session_id).toBe(555);
  });

  it('falls back to start_time match among reloaded rows when id does not resolve', async () => {
    const course = { id: 42, duration_hours: 2 };
    const rowAt16 = makeRow({ id: 901, startTime: '16:00' });
    let loaded = false;
    const getSessionDisplayRow = vi.fn(() => null); // id never resolves
    const reloadCourseSessions = vi.fn(async () => { loaded = true; return true; });
    const getSessionRowsForDate = vi.fn(() => (loaded ? [makeRow({ id: 900, startTime: '18:00' }), rowAt16] : []));

    const flow = buildFlow({ getSessionDisplayRow, reloadCourseSessions, getSessionRowsForDate });

    await flow.openSessionEdit(course, '2026-06-27', 0, { id: 901, startTime: '16:00', isProjected: false });

    expect(globalThis.alert).not.toHaveBeenCalled();
    expect(flow.showSessionEditModal.value).toBe(true);
    expect(flow.sessionEditForm.value.session_id).toBe(901);
  });

  it('opens resolve dialog instead of alert when reload cannot resolve', async () => {
    const course = { id: 42, duration_hours: 2, payment_type: 'session' };
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(course, '2026-06-27', 555, { id: 555, startTime: '16:00', isProjected: false });

    expect(globalThis.alert).not.toHaveBeenCalled();
    expect(flow.showSessionEditModal.value).toBe(false);
    expect(flow.chipActionDialog.value?.kind).toBe('resolve_retry');
  });
});

describe('openSessionEdit projected capability (F4)', () => {
  beforeEach(() => {
    vi.spyOn(globalThis, 'alert').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({
        session: {
          id: 999, student_class_id: 42, session_date: '2026-08-10',
          start_time: '18:00', end_time: '20:00', status: 'scheduled',
        },
      }),
    });
  });

  it('count-mode projected chip does not call ensure-projected', async () => {
    const openQuickAddSessionModal = vi.fn();
    const course = { id: 42, payment_type: 'session', ScheduleMode: 'count', PackageID: 115, duration_hours: 2 };
    const flow = buildFlow({
      openQuickAddSessionModal,
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });
    await flow.openSessionEdit(course, '2026-08-10', 0, { isProjected: true, startTime: '18:00', endTime: '20:00' });
    expect(globalThis.fetch).not.toHaveBeenCalled();
    expect(flow.chipActionDialog.value?.kind).toBe('projected_quick_add');
    await flow.confirmChipActionDialog();
    expect(openQuickAddSessionModal).toHaveBeenCalledWith(course, expect.objectContaining({
      date: '2026-08-10', startTime: '18:00', source: 'projected_count_chip',
    }));
  });

  it('paused course projected chip explains the pause instead of blaming count mode', async () => {
    const course = { id: 42, payment_type: 'session', ScheduleMode: 'count', Stop: 1, duration_hours: 2 };
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });
    await flow.openSessionEdit(course, '2026-08-10', 0, { isProjected: true, startTime: '18:00', endTime: '20:00' });
    expect(flow.chipActionDialog.value?.message).toBe('課程暫停中，恢復後才會排課；這個日期不會上課。');
    expect(flow.chipActionDialog.value?.message).not.toContain('堂數制');
  });

  it.each([
    ['Stop=1', { Stop: 1 }],
    ['Supabase fallback status=inactive', { status: 'inactive' }],
  ])('paused course (%s) dialog is informational only and never quick-adds', async (_n, extra) => {
    const openQuickAddSessionModal = vi.fn();
    const course = { id: 42, payment_type: 'session', ScheduleMode: 'count', duration_hours: 2, ...extra };
    const flow = buildFlow({
      openQuickAddSessionModal,
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });
    await flow.openSessionEdit(course, '2026-08-10', 0, { isProjected: true, startTime: '18:00', endTime: '20:00' });
    const dlg = flow.chipActionDialog.value;
    expect(dlg.message).toContain('課程暫停中');
    expect(dlg.primaryLabel).toBe('知道了');
    expect(dlg.secondaryLabel).toBe('');
    await flow.confirmChipActionDialog();
    expect(openQuickAddSessionModal).not.toHaveBeenCalled();
    expect(flow.chipActionDialog.value).toBeNull();
  });

  describe('paused monthly course 預排 cancel (in-app #340)', () => {
    const paused = { id: 42, payment_type: 'monthly', ScheduleMode: 'date', Stop: 1, closed_reason: null, duration_hours: 2 };
    const chip = { isProjected: true, startTime: '18:00', endTime: '20:00' };
    const open = (course, deps = {}) => {
      const flow = buildFlow({
        getSessionDisplayRow: vi.fn(() => null),
        reloadCourseSessions: vi.fn(async () => true),
        getSessionRowsForDate: () => [],
        ...deps,
      });
      return flow.openSessionEdit(course, '2026-08-10', 0, chip).then(() => flow);
    };

    it('offers a cancel action and posts cancel:true to ensure-projected, then reloads', async () => {
      const loadCourses = vi.fn();
      globalThis.fetch.mockResolvedValueOnce({ ok: true, json: async () => ({ message: '已取消這一堂預排' }) });
      const flow = await open(paused, { loadCourses });
      expect(flow.chipActionDialog.value).toMatchObject({ kind: 'projected_paused_cancel', primaryLabel: '取消這一堂', secondaryLabel: '返回' });
      await flow.confirmChipActionDialog();
      const [url, init] = globalThis.fetch.mock.calls.at(-1);
      expect(url).toBe('/api/v1/class-sessions/ensure-projected');
      expect(JSON.parse(init.body)).toMatchObject({ student_class_id: 42, session_date: '2026-08-10', cancel: true });
      expect(loadCourses).toHaveBeenCalled();
      expect(flow.chipActionDialog.value).toBeNull();
    });

    it('keeps the dialog open and does not reload when the backend refuses', async () => {
      const loadCourses = vi.fn();
      globalThis.fetch.mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ message: '指定日期不符合此課程固定時段' }) });
      const flow = await open(paused, { loadCourses });
      await flow.confirmChipActionDialog();
      expect(loadCourses).not.toHaveBeenCalled();
      expect(flow.chipActionDialog.value?.kind).toBe('projected_paused_cancel');
    });

    it.each([
      ['closed (has closed_reason)', { closed_reason: 'completed' }],
      ['count mode', { ScheduleMode: 'count' }],
      ['manual occurrence', { scheduling_policy: 'manual_occurrence' }],
    ])('stays informational for %s', async (_n, extra) => {
      const flow = await open({ ...paused, ...extra });
      expect(flow.chipActionDialog.value?.kind).toBe('projected_paused_info');
      expect(flow.chipActionDialog.value?.primaryLabel).toBe('知道了');
    });
  });

  it('monthly date-mode projected chip still materializes', async () => {
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
      updateLocalSessionRow: vi.fn(),
    });
    await flow.openSessionEdit(
      { id: 42, payment_type: 'monthly', ScheduleMode: 'date', duration_hours: 2 },
      '2026-08-10', 0, { isProjected: true, startTime: '18:00', endTime: '20:00' },
    );
    expect(globalThis.fetch).toHaveBeenCalledWith(
      '/api/v1/class-sessions/ensure-projected',
      expect.objectContaining({ method: 'POST' }),
    );
    expect(flow.showSessionEditModal.value).toBe(true);
  });

  it('shows the backend rejection reason when projected-session materialization fails', async () => {
    globalThis.fetch.mockResolvedValueOnce({
      ok: false,
      json: async () => ({ message: '指定日期不符合此課程固定時段' }),
    });
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(
      { id: 42, payment_type: 'monthly', ScheduleMode: 'date', duration_hours: 2 },
      '2026-08-10', 0, { isProjected: true, startTime: '18:00', endTime: '20:00' },
    );

    expect(flow.chipActionDialog.value).toMatchObject({
      kind: 'resolve_retry',
      title: '無法建立可編輯堂次',
    });
    expect(flow.chipActionDialog.value?.message).toContain('指定日期不符合此課程固定時段');
  });
});
