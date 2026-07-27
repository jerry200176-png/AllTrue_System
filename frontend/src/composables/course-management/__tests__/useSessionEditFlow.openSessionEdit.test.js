import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useSessionEditFlow } from '../useSessionEditFlow';

// Regression coverage for #942 (in-app #177) + package projected UX (F4).

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
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: false,
      statusText: 'Unprocessable',
      json: async () => ({ message: '僅月結固定時段課程可建立推算堂次' }),
    });
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

  it('opens actionable resolve dialog when reload cannot resolve (no native alert)', async () => {
    const course = { id: 42, duration_hours: 2, payment_type: 'session' };
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(course, '2026-06-27', 555, { id: 555, startTime: '16:00', isProjected: false });

    expect(globalThis.alert).not.toHaveBeenCalled();
    expect(flow.showSessionEditModal.value).toBe(false);
    expect(flow.showSessionResolveDialog.value).toBe(true);
    expect(flow.sessionResolveContext.value?.title).toMatch(/找不到|載入/);
  });
});

describe('openSessionEdit projected capability (F4 package UX)', () => {
  beforeEach(() => {
    vi.spyOn(globalThis, 'alert').mockImplementation(() => {});
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({
        session: {
          id: 999,
          student_class_id: 42,
          session_date: '2026-08-10',
          start_time: '18:00',
          end_time: '20:00',
          status: 'scheduled',
        },
      }),
    });
  });

  it('count-mode projected chip does not call ensure-projected; opens quick-add dialog', async () => {
    const openQuickAddSessionModal = vi.fn();
    const course = {
      id: 42,
      payment_type: 'session',
      ScheduleMode: 'count',
      PackageID: 115,
      duration_hours: 2,
    };
    const flow = buildFlow({
      openQuickAddSessionModal,
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(course, '2026-08-10', 0, {
      isProjected: true,
      startTime: '18:00',
      endTime: '20:00',
    });

    expect(globalThis.fetch).not.toHaveBeenCalled();
    expect(flow.showProjectedActionDialog.value).toBe(true);
    expect(flow.showSessionEditModal.value).toBe(false);

    flow.confirmProjectedQuickAdd();
    expect(openQuickAddSessionModal).toHaveBeenCalledWith(
      course,
      expect.objectContaining({
        date: '2026-08-10',
        startTime: '18:00',
        source: 'projected_count_chip',
      }),
    );
  });

  it('monthly date-mode projected chip still materializes via ensure-projected', async () => {
    const updateLocalSessionRow = vi.fn();
    const course = {
      id: 42,
      payment_type: 'monthly',
      ScheduleMode: 'date',
      duration_hours: 2,
    };
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
      updateLocalSessionRow,
    });

    await flow.openSessionEdit(course, '2026-08-10', 0, {
      isProjected: true,
      startTime: '18:00',
      endTime: '20:00',
    });

    expect(globalThis.fetch).toHaveBeenCalledWith(
      '/api/v1/class-sessions/ensure-projected',
      expect.objectContaining({ method: 'POST' }),
    );
    expect(flow.showSessionEditModal.value).toBe(true);
    expect(flow.showProjectedActionDialog.value).toBe(false);
  });
});
