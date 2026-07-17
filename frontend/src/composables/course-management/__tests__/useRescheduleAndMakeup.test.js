import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useRescheduleAndMakeup } from '../useRescheduleAndMakeup.js';

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

describe('useRescheduleAndMakeup write safety', () => {
  beforeEach(() => {
    vi.stubGlobal('confirm', vi.fn(() => true));
    vi.stubGlobal('alert', vi.fn());
    vi.stubGlobal('fetch', vi.fn());
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
    expect(alert).toHaveBeenCalledWith('登入狀態已失效，請重新登入後再調課。');
    expect(deps.loadCourses).not.toHaveBeenCalled();
  });

  it('compensates only newly created schedule rows when the session move returns 422', async () => {
    const deps = makeDeps({
      supabase: {
        auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'tok' } } })) },
        from: vi.fn(),
      },
    });
    const deletedIds = [];
    const postedBodies = [];
    let schedulePostCount = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
      const method = options.method || 'GET';
      if (String(url).startsWith('/api/v1/schedules?')) {
        return { ok: true, status: 200, json: async () => [] };
      }
      if (url === '/api/v1/schedules' && method === 'POST') {
        schedulePostCount += 1;
        postedBodies.push(JSON.parse(options.body));
        const id = schedulePostCount === 1 ? 11 : 12;
        return { ok: true, status: 201, json: async () => ({ id, write_disposition: 'created' }) };
      }
      if (url === '/api/v1/learning-records/reschedule-session') {
        postedBodies.push(JSON.parse(options.body));
        return { ok: false, status: 422, json: async () => ({ message: '找不到指定堂次' }) };
      }
      if (String(url).startsWith('/api/v1/schedules/') && method === 'DELETE') {
        deletedIds.push(Number(String(url).split('/').pop()));
        return { ok: true, status: 200, json: async () => ({}) };
      }
      throw new Error(`Unexpected request: ${method} ${url}`);
    }));

    const flow = useRescheduleAndMakeup(deps);
    seedForm(flow.rescheduleForm);
    flow.showRescheduleModal.value = true;

    await flow.submitReschedule();

    expect(postedBodies[2]).toMatchObject({
      student_class_id: 2443,
      old_date: '2026-07-14',
      old_start_time: '17:30',
      new_date: '2026-07-14',
      start_time: '18:00',
    });
    expect(deletedIds).toEqual([12, 11]);
    expect(flow.showRescheduleModal.value).toBe(true);
    expect(deps.loadCourses).not.toHaveBeenCalled();
    expect(alert).toHaveBeenCalledWith(expect.stringContaining('調課失敗'));
  });
});
