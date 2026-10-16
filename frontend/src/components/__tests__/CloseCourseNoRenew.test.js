import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import {
  CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE,
  CLOSED_PENDING_HIDDEN_BY_ACTIVE_FILTER_NOTICE,
  RELOAD_AFTER_CLOSE_FAILED_MESSAGE,
  closeCourseNoRenew,
  closedCourseHiddenNotice,
  createCourseCloseFlow,
} from '../../lib/closeCourseNoRenew.js';
import { clearRowCachesForWinningRequest, createListLoadOutcome, fallbackListRows, isCurrentListRequest } from '../../lib/listRefreshState.js';

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

  // in-app #415: callers close the 管理課程 drawer only after a real close.
  it('resolves true only when the course was actually closed', async () => {
    expect(await closeCourseNoRenew(setup().deps)).toBe(true);
    expect(await closeCourseNoRenew(setup({ accepted: false }).deps)).toBe(false);
    expect(await closeCourseNoRenew(setup({ settled: null }).deps)).toBe(false);
    const failed = setup();
    authedFetch.mockImplementation(async () => ({ ok: false, statusText: 'Bad', json: async () => ({ message: 'nope' }) }));
    expect(await closeCourseNoRenew(failed.deps)).toBe(false);
    expect(failed.calls.reloads).toBe(0);
  });

  it('a refresh failure after a successful close still resolves true with its own notice', async () => {
    const { calls, deps } = setup();
    deps.reloadCourses = async () => { calls.reloads += 1; throw new Error('network'); };
    expect(await closeCourseNoRenew(deps)).toBe(true);
    expect(calls.requests).toHaveLength(1);
    expect(calls.alerts[0]).toContain('已結案');
    expect(calls.alerts[1]).toBe(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
    expect(calls.alerts.some((a) => a.startsWith('操作失敗') || a.startsWith('結案失敗'))).toBe(false);
  });

  it('a reload that resolves { ok: false } (both list sources failed) is a refresh failure, not a failed close', async () => {
    const { calls, deps } = setup();
    deps.reloadCourses = async () => { calls.reloads += 1; return { ok: false, error: new Error('fallback') }; };
    deps.onReloadFailed = vi.fn();
    expect(await closeCourseNoRenew(deps)).toBe(true);
    expect(calls.alerts).toHaveLength(2);
    expect(calls.alerts[1]).toBe(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
    expect(deps.onReloadFailed).toHaveBeenCalledTimes(1);
  });

  it('onReloadFailed also fires when the reload throws, and never on a successful reload', async () => {
    const thrown = setup();
    thrown.deps.reloadCourses = async () => { throw new Error('network'); };
    thrown.deps.onReloadFailed = vi.fn();
    expect(await closeCourseNoRenew(thrown.deps)).toBe(true);
    expect(thrown.deps.onReloadFailed).toHaveBeenCalledTimes(1);
    for (const outcome of [undefined, { ok: true }]) {
      const ok = setup();
      ok.deps.reloadCourses = async () => outcome;
      ok.deps.onReloadFailed = vi.fn();
      expect(await closeCourseNoRenew(ok.deps)).toBe(true);
      expect(ok.calls.alerts).not.toContain(RELOAD_AFTER_CLOSE_FAILED_MESSAGE);
      expect(ok.deps.onReloadFailed).not.toHaveBeenCalled();
    }
  });

  it('reports a network failure of the close request itself as a failed close', async () => {
    const { calls, deps } = setup();
    authedFetch.mockImplementation(async () => { throw new Error('offline'); });
    expect(await closeCourseNoRenew(deps)).toBe(false);
    expect(calls.reloads).toBe(0);
    expect(calls.alerts).toEqual(['操作失敗：offline']);
  });
});

// in-app #415 (PR #4159 simplification): the page only reloads; a hidden closed course gets a notice.
describe('notice when the current filter hides the closed course', () => {
  const closed = { id: 42, student_id: 3 };
  const other = { id: 7, student_id: 3 };

  it('shows the 進行中 notice with a switch to 「全部」 when the reload omits the course', () => {
    expect(closedCourseHiddenNotice({ course: closed, courses: [other], courseStatus: 'active' }))
      .toEqual({ courseId: 42, message: CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE, canShowAll: true });
    expect(CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE).toBe('已結束課程。目前篩選只顯示進行中，可切到「全部」或「歷史課程」查看。');
  });

  // PR #4159 review: an unpaid close (settled_pending) stays in the main list, never in 歷史課程.
  it('points an unpaid close only to 「全部」 and never mentions 歷史課程', () => {
    const notice = closedCourseHiddenNotice({ course: closed, courses: [other], courseStatus: 'active', pendingReconciliation: true });
    expect(notice).toEqual({ courseId: 42, message: CLOSED_PENDING_HIDDEN_BY_ACTIVE_FILTER_NOTICE, canShowAll: true });
    expect(notice.message).toContain('「全部」');
    expect(notice.message).not.toContain('歷史課程');
    expect(notice.message).toContain('課已結束，尚未收款');
  });

  it('points a paid close to 「全部」 or 「歷史課程」', () => {
    const notice = closedCourseHiddenNotice({ course: closed, courses: [other], courseStatus: 'active', pendingReconciliation: false });
    expect(notice.message).toBe(CLOSED_HIDDEN_BY_ACTIVE_FILTER_NOTICE);
    expect(notice.message).toContain('「歷史課程」');
  });

  it('reports the close outcome (pending reconciliation or not) from the server response', async () => {
    for (const pending of [true, false]) {
      const { deps } = setup({ response: { pending_reconciliation: pending } });
      const outcomes = [];
      deps.onClosed = (o) => outcomes.push(o);
      expect(await closeCourseNoRenew(deps)).toBe(true);
      expect(outcomes).toEqual([{ pendingReconciliation: pending }]);
    }
    const failed = setup();
    const outcomes = [];
    failed.deps.onClosed = (o) => outcomes.push(o);
    authedFetch.mockImplementation(async () => ({ ok: false, statusText: 'Bad', json: async () => ({}) }));
    expect(await closeCourseNoRenew(failed.deps)).toBe(false);
    expect(outcomes).toEqual([]);
  });

  it('switching to 「全部」 reveals the course, so no notice remains', () => {
    const notice = closedCourseHiddenNotice({ course: closed, courses: [other], courseStatus: 'active' });
    expect(notice.canShowAll).toBe(true);
    // After the switch the page reloads with course_status '' and the closed course is listed.
    expect(closedCourseHiddenNotice({ course: closed, courses: [other, closed], courseStatus: '' })).toBeNull();
  });

  it('no notice when the course is still visible; no switch when the status filter is not the cause', () => {
    expect(closedCourseHiddenNotice({ course: closed, courses: [other, closed], courseStatus: 'active' })).toBeNull();
    const byName = closedCourseHiddenNotice({ course: closed, courses: [other], courseStatus: '' });
    expect(byName.canShowAll).toBe(false);
    expect(byName.message).toContain('已結束課程');
  });
});

describe('single-flight close and drawer identity', () => {
  function deferred() { let resolve; const promise = new Promise((r) => { resolve = r; }); return { promise, resolve }; }
  const A = { id: 1 };
  const B = { id: 2 };

  it('keeps course B\'s drawer open when A\'s close finishes after B was opened', async () => {
    const d = deferred();
    let drawer = A;
    const closeDrawer = vi.fn(() => { drawer = null; });
    const flow = createCourseCloseFlow({ closeCourse: () => d.promise, getDrawerCourse: () => drawer, closeDrawer });
    const pending = flow.closeFromDrawer(A);
    drawer = B; // user dismissed A's drawer and opened B while A's close was pending
    d.resolve(true);
    expect(await pending).toBe(true);
    expect(closeDrawer).not.toHaveBeenCalled();
    expect(drawer).toBe(B);
  });

  it('closes the drawer when it still shows the closed course', async () => {
    const closeDrawer = vi.fn();
    const flow = createCourseCloseFlow({ closeCourse: async () => true, getDrawerCourse: () => ({ ID: 1 }), closeDrawer });
    expect(await flow.closeFromDrawer(A)).toBe(true);
    expect(closeDrawer).toHaveBeenCalledTimes(1);
  });

  it('a second close (double click) is ignored while one is pending', async () => {
    const d = deferred();
    const closeCourse = vi.fn(() => d.promise);
    const closeDrawer = vi.fn();
    const flow = createCourseCloseFlow({ closeCourse, getDrawerCourse: () => A, closeDrawer });
    const first = flow.closeFromDrawer(A);
    expect(flow.isPending()).toBe(true);
    expect(flow.isPending(A)).toBe(true);
    expect(flow.isPending(B)).toBe(false);
    expect(await flow.closeFromDrawer(A)).toBe(false);
    expect(await flow.close(A)).toBe(false);
    d.resolve(true);
    expect(await first).toBe(true);
    expect(closeCourse).toHaveBeenCalledTimes(1);
    expect(closeDrawer).toHaveBeenCalledTimes(1);
    expect(flow.isPending()).toBe(false);
    await flow.close(A);
    expect(closeCourse).toHaveBeenCalledTimes(2);
  });

  it('the lock is per course: a pending close of A does not block closing B', async () => {
    const d = deferred();
    const closeCourse = vi.fn((c) => (c === A ? d.promise : Promise.resolve(true)));
    const flow = createCourseCloseFlow({ closeCourse, getDrawerCourse: () => null, closeDrawer: vi.fn() });
    const first = flow.close(A);
    expect(await flow.close(B)).toBe(true);
    expect(closeCourse).toHaveBeenCalledTimes(2);
    d.resolve(true);
    expect(await first).toBe(true);
  });

  it('releases the lock when the close throws', async () => {
    const flow = createCourseCloseFlow({ closeCourse: async () => { throw new Error('x'); }, getDrawerCourse: () => null, closeDrawer: vi.fn() });
    await expect(flow.close(A)).rejects.toThrow('x');
    expect(flow.isPending()).toBe(false);
  });
});

// PR #4159 Codex r4289552000: a failed Supabase fallback must not be read as an empty course list.
describe('fallbackListRows', () => {
  it('returns null when the fallback errored or returned no array', () => {
    expect(fallbackListRows({ data: null, error: new Error('x') })).toBeNull();
    expect(fallbackListRows({ data: [{ id: 1 }], error: { message: 'x' } })).toBeNull();
    expect(fallbackListRows({ data: null, error: null })).toBeNull();
    expect(fallbackListRows()).toBeNull();
  });

  it('returns the rows, including an empty list, when the fallback succeeded', () => {
    const rows = [{ id: 1 }];
    expect(fallbackListRows({ data: rows, error: null })).toBe(rows);
    expect(fallbackListRows({ data: [] })).toEqual([]);
  });
});

// Codex r4289665973 / #4189: overlapping loads must not zero the session data of the kept rows.
describe('clearRowCachesForWinningRequest', () => {
  // Mirrors loadCourses' ordering: a request clears the session caches only once it wins, and a
  // double failure keeps the rows (and caches) on screen.
  function createLoader(caches) {
    let latest = 0;
    return async function load(fetchRows) {
      const requestId = ++latest;
      const rows = await fetchRows();
      if (!isCurrentListRequest(requestId, latest)) return 'superseded';
      if (rows === null) return 'failed';
      clearRowCachesForWinningRequest(requestId, latest, caches);
      caches[0].value = Object.fromEntries(rows.map((r) => [r.id, r.sessions]));
      return 'ok';
    };
  }
  const deferred = () => { let resolve; const promise = new Promise((r) => { resolve = r; }); return { promise, resolve }; };

  it('load #1 pending, load #2 double-fails: session data stays from the last successful load', async () => {
    const sessionsByCourse = { value: {} };
    const load = createLoader([sessionsByCourse]);
    expect(await load(async () => [{ id: 7, sessions: ['2026-10-01'] }])).toBe('ok');
    const lastSuccessful = sessionsByCourse.value;

    const first = deferred();
    const load1 = load(() => first.promise);
    const load2 = load(async () => null);
    expect(await load2).toBe('failed');
    expect(sessionsByCourse.value).toBe(lastSuccessful);
    expect(sessionsByCourse.value).toEqual({ 7: ['2026-10-01'] });

    first.resolve([{ id: 8, sessions: [] }]);
    expect(await load1).toBe('superseded');
    expect(sessionsByCourse.value).toBe(lastSuccessful);
  });

  it('clears every cache only for the current request', () => {
    const a = { value: { 1: ['x'] } };
    const b = { value: { 1: ['y'] } };
    expect(clearRowCachesForWinningRequest(1, 2, [a, b])).toBe(false);
    expect(a.value).toEqual({ 1: ['x'] });
    expect(clearRowCachesForWinningRequest(2, 2, [a, b])).toBe(true);
    expect(a.value).toEqual({});
    expect(b.value).toEqual({});
  });
});

// Codex r4289977924 / r4290041474: rows are kept only for the same query; every failure sets the hint.
describe('createListLoadOutcome', () => {
  const key = (branch, status = '') => JSON.stringify([branch, 1, { course_status: status }]);

  it('branch A rows, switch to branch B, double failure: the rows must be cleared', () => {
    const failedRef = { value: false };
    const outcome = createListLoadOutcome(failedRef);
    outcome.succeeded(key('A'));
    expect([outcome.failed(key('B')), failedRef.value]).toEqual([false, true]);
    expect(outcome.failed(key('A'))).toBe(false); // the cleared rows are not "branch A" any more
  });

  it('same-query refresh double failure keeps the rows and sets the hint; the next success clears it', () => {
    const failedRef = { value: false };
    const outcome = createListLoadOutcome(failedRef);
    outcome.succeeded(key('A', 'active'));
    expect([outcome.failed(key('A', 'active')), failedRef.value]).toEqual([true, true]);
    expect(outcome.failed(key('A', ''))).toBe(false); // 「切到「全部」」 over the active subset
    outcome.succeeded(key('A'));
    expect(failedRef.value).toBe(false);
  });

  it('a pause/resume reload that resolves { ok: false } shows the hint without caller code', async () => {
    const failedRef = { value: false };
    const outcome = createListLoadOutcome(failedRef);
    outcome.succeeded(key('A'));
    const onChanged = async () => { await (async () => { outcome.failed(key('A')); return { ok: false }; })(); };
    await onChanged(); // as wired into useCoursePause: `await loadCourses()`
    expect(failedRef.value).toBe(true);
  });
});
