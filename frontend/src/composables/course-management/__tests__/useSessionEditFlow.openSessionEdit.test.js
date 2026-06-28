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

  it('still alerts when reload cannot resolve and the slot is not projectable', async () => {
    const course = { id: 42, duration_hours: 2 };
    const flow = buildFlow({
      getSessionDisplayRow: vi.fn(() => null),
      reloadCourseSessions: vi.fn(async () => true),
      getSessionRowsForDate: () => [],
    });

    await flow.openSessionEdit(course, '2026-06-27', 555, { id: 555, startTime: '16:00', isProjected: false });

    expect(globalThis.alert).toHaveBeenCalledTimes(1);
    expect(flow.showSessionEditModal.value).toBe(false);
  });
});
