import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useRescheduleAndMakeup } from '../useRescheduleAndMakeup.js';

vi.mock('../../../lib/rescheduleApi.js', () => ({
  commitReschedule: vi.fn(),
  RescheduleApiError: class RescheduleApiError extends Error {
    constructor(message, { status = 0, code = '', conflicts = [] } = {}) {
      super(message);
      this.name = 'RescheduleApiError';
      this.status = status;
      this.code = code;
      this.conflicts = conflicts;
    }
  },
}));

import { commitReschedule, RescheduleApiError } from '../../../lib/rescheduleApi.js';

function makeDeps(overrides = {}) {
  return {
    supabase: {
      auth: { getSession: vi.fn(async () => ({ data: { session: null } })) },
      from: vi.fn(),
    },
    branchId: ref(1),
    computeEndTime: vi.fn(() => '20:00'),
    normalizeTo30Min: (value) => value,
    dayOfWeekFromDate: vi.fn(() => 2),
    classSessionsByCourse: ref({}),
    sessions: vi.fn(() => ['2026-07-14']),
    ensureCompletedSessionDatesLoaded: vi.fn(async () => {}),
    loadCourses: vi.fn(async () => {}),
    getCapacityForClassType: vi.fn(() => 1),
    ...overrides,
  };
}

function seedForm(rescheduleForm) {
  rescheduleForm.value = {
    student_id: 10,
    student_name: '測試學生',
    subject: 'Math',
    teacher_id: 67,
    class_type: 'one_on_one',
    duration_hours: 2,
    course_id: 2443,
    original_date: '2026-07-14',
    original_day: 2,
    original_start: '17:30',
    original_end: '19:30',
    new_date: '2026-07-14',
    new_start: '18:00',
  };
}

describe('useRescheduleAndMakeup atomic write safety (R71)', () => {
  beforeEach(() => {
    vi.stubGlobal('alert', vi.fn());
    vi.stubGlobal('fetch', vi.fn());
    commitReschedule.mockReset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('fails closed without an authenticated session and performs no writes', async () => {
    const deps = makeDeps();
    const flow = useRescheduleAndMakeup(deps);
    seedForm(flow.rescheduleForm);

    await flow.submitReschedule();

    expect(deps.supabase.from).not.toHaveBeenCalled();
    expect(fetch).not.toHaveBeenCalled();
    expect(commitReschedule).not.toHaveBeenCalled();
    expect(flow.rescheduleError.value).toContain('重新登入');
    expect(deps.loadCourses).not.toHaveBeenCalled();
  });

  it('keeps modal open and shows in-dialog error when atomic commitReschedule returns 422', async () => {
    const deps = makeDeps({
      supabase: {
        auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'tok' } } })) },
        from: vi.fn(),
      },
    });
    commitReschedule.mockRejectedValue(
      new RescheduleApiError('找不到指定堂次', { status: 422, code: 'session_not_found' }),
    );

    const flow = useRescheduleAndMakeup(deps);
    seedForm(flow.rescheduleForm);
    flow.showRescheduleModal.value = true;

    await flow.submitReschedule();

    expect(commitReschedule).toHaveBeenCalledWith({
      token: 'tok',
      payload: expect.objectContaining({
        student_class_id: 2443,
        old_date: '2026-07-14',
        old_start_time: '17:30',
        new_date: '2026-07-14',
        start_time: '18:00',
      }),
    });
    expect(flow.showRescheduleModal.value).toBe(true);
    expect(deps.loadCourses).not.toHaveBeenCalled();
    expect(flow.rescheduleError.value).toContain('找不到指定堂次');
    expect(alert).not.toHaveBeenCalled();
  });

  it('surfaces conflict students inside the dialog error', async () => {
    const deps = makeDeps({
      supabase: {
        auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'tok' } } })) },
        from: vi.fn(),
      },
    });
    const err = new RescheduleApiError('衝堂', {
      status: 409,
      code: 'schedule_conflict',
      conflicts: [{ overlap_details: [{ student_name: '小華' }] }],
    });
    commitReschedule.mockRejectedValue(err);

    const flow = useRescheduleAndMakeup(deps);
    seedForm(flow.rescheduleForm);
    await flow.submitReschedule();

    expect(flow.rescheduleError.value).toContain('此時段已有：小華');
  });
});
