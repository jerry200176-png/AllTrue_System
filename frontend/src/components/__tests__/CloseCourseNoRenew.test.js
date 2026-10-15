import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { closeCourseNoRenew, RELOAD_AFTER_CLOSE_FAILED_MESSAGE, retainClosedCourseInList } from '../../lib/closeCourseNoRenew.js';
import { ENDED_PENDING_LABEL, closedReason, isHistoryCourse } from '../../lib/courseMoneyState.js';

function setup({ remaining = 0, settled = true, accepted = true, response = { pending_reconciliation: true } } = {}) {
  const calls = { requests: [], alerts: [], confirms: [], reloads: 0 };
  const deps = {
    course: { id: 42, subject: '英文', remaining_sessions: remaining },
    studentName: '學生',
    getRemainingSessions: (course) => course.remaining_sessions,
    getSubjectLabel: (subject) => subject,
    isCourseSettled: () => settled,
    reloadCourses: async () => { calls.reloads += 1; },
    confirmImpl: (message) => { calls.confirms.push(message); return accepted; },
    alertImpl: (message) => calls.alerts.push(message),
  };
  authedFetch.mockImplementation(async (url, options, token) => {
    calls.requests.push({ url, options, token });
    return { ok: true, json: async () => response };
  });
  return { calls, deps };
}

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('test-token');
});

describe('shared close-course action', () => {
  it('preserves remaining-session, unpaid reconciliation and existing endpoint semantics', async () => {
    const { calls, deps } = setup({ remaining: 2, settled: false });
    await closeCourseNoRenew(deps);
    expect(calls.confirms[0]).toContain('放棄這 2 堂剩餘額度');
    expect(calls.confirms[0]).toContain('課已結束，尚未收款');
    expect(calls.requests[0].url).toBe('/api/v1/student-classes/42/pause');
    expect(calls.requests[0].options.method).toBe('POST');
    expect(calls.requests[0].token).toBe('test-token');
    expect(calls.requests[0].options.headers).toEqual({ 'Content-Type': 'application/json', Accept: 'application/json' });
    expect(JSON.parse(calls.requests[0].options.body)).toEqual({
      action: 'pause', reason: 'settled', forfeit_remaining: true,
    });
    expect(calls.reloads).toBe(1);
    expect(calls.alerts[0]).toContain('「課已結束，尚未收款」分頁');
  });

  it('refuses to close when payment status is unknown (degraded load)', async () => {
    const { calls, deps } = setup({ settled: null });
    await closeCourseNoRenew(deps);
    expect(calls.confirms).toEqual([]);
    expect(calls.requests).toEqual([]);
    expect(calls.alerts[0]).toContain('繳費狀態載入中');
  });

  it('does not mutate or reload when the confirmation is canceled', async () => {
    const { calls, deps } = setup({ accepted: false });
    await closeCourseNoRenew(deps);
    expect(calls.requests).toHaveLength(0);
    expect(calls.reloads).toBe(0);
  });

  it('omits forfeiture when there are no remaining lessons', async () => {
    const { calls, deps } = setup({ remaining: 0 });
    await closeCourseNoRenew(deps);
    expect(JSON.parse(calls.requests[0].options.body)).toEqual({ action: 'pause', reason: 'settled' });
    expect(calls.reloads).toBe(1);
  });

  it('asks to log in again and sends nothing when there is no session token', async () => {
    getAccessToken.mockResolvedValue(undefined);
    const { calls, deps } = setup();
    await closeCourseNoRenew(deps);
    expect(calls.requests).toEqual([]);
    expect(calls.alerts).toEqual(['請重新登入']);
    expect(calls.reloads).toBe(0);
  });

  // in-app #415: callers reveal 歷史課程 / close the 管理課程 drawer only after a real close.
  it('resolves true only when the course was actually closed', async () => {
    const ok = setup();
    expect(await closeCourseNoRenew(ok.deps)).toBe(true);
    const canceled = setup({ accepted: false });
    expect(await closeCourseNoRenew(canceled.deps)).toBe(false);
    const unknown = setup({ settled: null });
    expect(await closeCourseNoRenew(unknown.deps)).toBe(false);
    const failed = setup();
    authedFetch.mockImplementation(async () => ({ ok: false, statusText: 'Bad', json: async () => ({ message: 'nope' }) }));
    expect(await closeCourseNoRenew(failed.deps)).toBe(false);
    expect(failed.calls.reloads).toBe(0);
  });

  // PR #4159 review: the close POST is committed; a failed refresh must not reclassify it as failed.
  it('still resolves true with a non-destructive notice when only the refresh fails', async () => {
    const { calls, deps } = setup();
    deps.reloadCourses = async () => { calls.reloads += 1; throw new Error('network'); };
    expect(await closeCourseNoRenew(deps)).toBe(true);
    expect(calls.requests).toHaveLength(1);
    expect(calls.reloads).toBe(1);
    expect(calls.alerts[0]).toContain('已結案');
    expect(calls.alerts[1]).toBe(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
    expect(calls.alerts.some((a) => a.startsWith('操作失敗') || a.startsWith('結案失敗'))).toBe(false);
  });

  it('reports a network failure of the close request itself as a failed close', async () => {
    const { calls, deps } = setup();
    authedFetch.mockImplementation(async () => { throw new Error('offline'); });
    expect(await closeCourseNoRenew(deps)).toBe(false);
    expect(calls.reloads).toBe(0);
    expect(calls.alerts).toEqual(['操作失敗：offline']);
  });
});

// PR #4159 review: with the 「進行中」 filter the post-close reload sends status=active, which
// omits the now-stopped course. The close flow must still surface it without changing the filter.
describe('post-close reload under the 進行中 (status=active) filter', () => {
  const other = { id: 7, student_id: 3, Stop: 0, status: 'active', closed_reason: null };
  const backend = {
    paid: { id: 42, student_id: 3, Stop: 1, status: 'inactive', closed_reason: 'settled', payment_status: 'paid' },
    unpaid: { id: 43, student_id: 3, Stop: 1, status: 'inactive', closed_reason: 'settled_pending', payment_status: 'unpaid' },
  };
  // Mimics the list endpoint: the status=active filter drops Stop=1 rows.
  const listActive = () => [other, backend.paid, backend.unpaid].filter((c) => c.Stop === 0);

  async function closeThenReload(course) {
    const statusFilters = [];
    let list = [other, { ...course, Stop: 0, status: 'active', closed_reason: null }];
    const { calls, deps } = setup();
    deps.course = { ...course, Stop: 0, closed_reason: null };
    deps.reloadCourses = async () => {
      calls.reloads += 1;
      statusFilters.push('active');
      list = listActive();
      list = await retainClosedCourseInList({
        courses: list,
        course: deps.course,
        fetchStudentCourses: async (sid) => { statusFilters.push(null); return [other, backend.paid, backend.unpaid].filter((c) => c.student_id === sid); },
      });
    };
    expect(await closeCourseNoRenew(deps)).toBe(true);
    return { list, statusFilters };
  }

  it('moves a paid close under 歷史課程', async () => {
    const { list, statusFilters } = await closeThenReload(backend.paid);
    expect(statusFilters).toEqual(['active', null]);
    const closed = list.find((c) => c.id === 42);
    expect(closed).toBeTruthy();
    expect(isHistoryCourse(closed)).toBe(true);
    expect(list.filter((c) => !isHistoryCourse(c)).map((c) => c.id)).toEqual([7]);
  });

  it('keeps an unpaid settled_pending close visible in the active list with its label', async () => {
    const { list } = await closeThenReload(backend.unpaid);
    const closed = list.find((c) => c.id === 43);
    expect(closed).toBeTruthy();
    expect(isHistoryCourse(closed)).toBe(false);
    expect(closedReason(closed)).toBe('settled_pending');
    expect(ENDED_PENDING_LABEL).toBe('課已結束，尚未收款');
    expect(list.filter((c) => !isHistoryCourse(c)).map((c) => c.id)).toEqual([7, 43]);
  });

  it('does not refetch when the reloaded list already has the course (全部 filter)', async () => {
    const fetchStudentCourses = vi.fn();
    const courses = [other, backend.paid];
    expect(await retainClosedCourseInList({ courses, course: backend.paid, fetchStudentCourses })).toBe(courses);
    expect(fetchStudentCourses).not.toHaveBeenCalled();
  });

  // PR #4159 review round 2: the appended course must get its ClassSessions loaded too,
  // otherwise its 歷史課程 row shows 0 attended lessons.
  it('loads sessions for the list that includes the retained course', async () => {
    const loadSessions = vi.fn(async () => {});
    const courses = listActive();
    const next = await retainClosedCourseInList({
      courses,
      course: { ...backend.paid, Stop: 0 },
      fetchStudentCourses: async () => [other, backend.paid, backend.unpaid],
      loadSessions,
    });
    expect(next.map((c) => c.id)).toEqual([7, 42]);
    expect(loadSessions).toHaveBeenCalledTimes(1);
    expect(loadSessions.mock.calls[0][0]).toBe(next);
  });

  it('does not reload sessions when nothing was appended', async () => {
    const loadSessions = vi.fn();
    const courses = [other, backend.paid];
    await retainClosedCourseInList({ courses, course: backend.paid, fetchStudentCourses: vi.fn(), loadSessions });
    await retainClosedCourseInList({ courses: [other], course: backend.paid, fetchStudentCourses: async () => [other], loadSessions });
    expect(loadSessions).not.toHaveBeenCalled();
  });

  // PR #4159 review round 3: a filter/page change while the post-close reload is pending owns the
  // view. Retention for the stale reload must not touch the newer courses or session caches.
  describe('race with a newer list load', () => {
    function makeView() {
      const view = { requestId: 0, courses: [], sessions: {} };
      const startLoad = (list) => { view.requestId += 1; view.courses = list; view.sessions = Object.fromEntries(list.map((c) => [c.id, [`s${c.id}`]])); return view.requestId; };
      const retain = async (closeReloadId, course, { duringFetch } = {}) => {
        const isCurrent = () => closeReloadId === view.requestId;
        const next = await retainClosedCourseInList({
          courses: view.courses,
          course,
          isCurrent,
          fetchStudentCourses: async () => { duringFetch?.(); return [other, backend.paid]; },
          loadSessions: async (list) => {
            if (!isCurrent()) return;
            view.sessions = Object.fromEntries(list.map((c) => [c.id, [`s${c.id}`]]));
          },
        });
        if (next !== view.courses && isCurrent()) view.courses = next;
      };
      return { view, startLoad, retain };
    }

    it('a filter change during the pending post-close reload leaves the newer list and sessions untouched', async () => {
      const { view, startLoad, retain } = makeView();
      const closeReloadId = startLoad(listActive());
      const newer = [{ id: 99, student_id: 5, Stop: 0 }];
      // Newer load lands before retention starts.
      startLoad(newer);
      const loadSessions = vi.fn();
      const skipped = await retainClosedCourseInList({
        courses: view.courses, course: backend.paid, isCurrent: () => closeReloadId === view.requestId,
        fetchStudentCourses: vi.fn(), loadSessions,
      });
      expect(skipped).toBe(newer);
      expect(loadSessions).not.toHaveBeenCalled();
      // Newer load lands while the retain fetch is in flight.
      const id2 = startLoad(listActive());
      await retain(id2, { ...backend.paid, Stop: 0 }, { duringFetch: () => startLoad(newer) });
      expect(view.courses).toBe(newer);
      expect(view.courses.some((c) => c.id === 42)).toBe(false);
      expect(view.sessions).toEqual({ 99: ['s99'] });
    });

    it('a newer load landing during the session load returns the original list', async () => {
      let current = true;
      const courses = listActive();
      const next = await retainClosedCourseInList({
        courses, course: backend.paid, isCurrent: () => current,
        fetchStudentCourses: async () => [backend.paid],
        loadSessions: async () => { current = false; },
      });
      expect(next).toBe(courses);
    });

    it('without interleaving, retention still appends the closed course and loads its sessions', async () => {
      const { view, startLoad, retain } = makeView();
      const id = startLoad(listActive());
      await retain(id, { ...backend.paid, Stop: 0 });
      expect(view.courses.map((c) => c.id)).toEqual([7, 42]);
      expect(view.sessions).toEqual({ 7: ['s7'], 42: ['s42'] });
    });
  });

  it('a failed retain fetch is reported as a refresh failure, not a failed close', async () => {
    const { calls, deps } = setup();
    deps.course = { ...backend.paid, Stop: 0 };
    deps.reloadCourses = async () => {
      await retainClosedCourseInList({ courses: listActive(), course: deps.course, fetchStudentCourses: async () => { throw new Error('x'); } });
    };
    expect(await closeCourseNoRenew(deps)).toBe(true);
    expect(calls.alerts.at(-1)).toBe(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
  });
});
