import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { useCoursePause, COURSE_LIFECYCLE_BUSY_MESSAGE } from '../useCoursePause';
import { createCourseCloseFlow } from '../../../lib/closeCourseNoRenew.js';

const res = (ok, json, statusText = 'Bad') => ({ ok, statusText, json: async () => json });
const active = { id: 7, status: 'active' };
const paused = { id: 8, status: 'inactive' };
let notify, onChanged;
const make = () => useCoursePause({ onChanged, notify });

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('tok');
  notify = vi.fn();
  onChanged = vi.fn(async () => {});
});

describe('useCoursePause', () => {
  it('request() opens the dialog with cancel-remaining defaulting to true', () => {
    const p = make();
    p.cancelRemaining.value = false;
    p.request(active);
    expect(p.target.value).toEqual(active);
    expect(p.cancelRemaining.value).toBe(true);
    expect(p.isResume.value).toBe(false);
    expect(p.impacts.value[0]).toBe('取消未來尚未上課堂次');
    p.cancelRemaining.value = false;
    expect(p.impacts.value[0]).toBe('不取消剩餘排課（堂次仍會留在行事曆）');
    p.request(paused);
    expect(p.isResume.value).toBe(true);
    expect(p.impacts.value[0]).toBe('恢復後可繼續排課與補課');
  });

  it('pauses with cancel_remaining, then notifies, closes the dialog and reloads', async () => {
    authedFetch.mockResolvedValue(res(true, { message: 'done' }));
    const p = make();
    p.request(active);
    p.cancelRemaining.value = false;
    await p.confirm();
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/pause', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: '{"action":"pause","cancel_remaining":false}',
    }, 'tok');
    expect(notify).toHaveBeenCalledWith('done');
    expect(p.target.value).toBeNull();
    expect(onChanged).toHaveBeenCalledTimes(1);
    expect(p.submitting.value).toBe(false);
  });

  it('resumes without cancel_remaining and falls back to the default message', async () => {
    authedFetch.mockResolvedValue(res(true, {}));
    const p = make();
    p.request(paused);
    await p.confirm();
    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/student-classes/8/pause');
    expect(authedFetch.mock.calls[0][1].body).toBe('{"action":"resume"}');
    expect(notify).toHaveBeenCalledWith('已恢復');
  });

  it('keeps the dialog open and skips reload on a server error (message, then statusText)', async () => {
    const p = make();
    p.request(active);
    authedFetch.mockResolvedValueOnce(res(false, { message: 'blocked' }));
    await p.confirm();
    expect(notify).toHaveBeenLastCalledWith('暫停失敗：blocked');
    authedFetch.mockResolvedValueOnce(res(false, {}, 'Forbidden'));
    await p.confirm();
    expect(notify).toHaveBeenLastCalledWith('暫停失敗：Forbidden');
    expect(p.target.value).toEqual(active);
    expect(onChanged).not.toHaveBeenCalled();
    expect(p.submitting.value).toBe(false);
  });

  it('asks to log in again without a token and reports network errors', async () => {
    const p = make();
    p.request(active);
    getAccessToken.mockResolvedValueOnce(undefined);
    await p.confirm();
    expect(notify).toHaveBeenLastCalledWith('請重新登入');
    expect(authedFetch).not.toHaveBeenCalled();
    authedFetch.mockRejectedValueOnce(new Error('offline'));
    await p.confirm();
    expect(notify).toHaveBeenLastCalledWith('操作失敗：offline');
    expect(p.submitting.value).toBe(false);
  });

  it('ignores confirm() with no target or while already submitting', async () => {
    const p = make();
    await p.confirm();
    expect(getAccessToken).not.toHaveBeenCalled();
    p.request(active);
    p.submitting.value = true;
    await p.confirm();
    expect(getAccessToken).not.toHaveBeenCalled();
  });
});

// PR #4159 r4286957773: close and pause/resume POST the same /pause endpoint, and a generic pause
// clears closed_reason, so they share one per-course lock (the close flow's single-flight).
describe('useCoursePause shares the per-course lifecycle lock with close', () => {
  function deferred() { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j; }); return { promise, resolve, reject }; }
  const other = { id: 9, status: 'active' };
  function setup(closeCourse) {
    const flow = createCourseCloseFlow({ closeCourse, getDrawerCourse: () => null, closeDrawer: vi.fn() });
    const p = useCoursePause({ onChanged, notify, courseLock: flow });
    return { flow, p };
  }

  it('a pause confirmed while a close of the same course is pending is not sent', async () => {
    const d = deferred();
    const { flow, p } = setup(() => d.promise);
    p.request(active); // dialog opened before the close started
    const closing = flow.close(active);
    await p.confirm();
    expect(authedFetch).not.toHaveBeenCalled();
    expect(notify).toHaveBeenCalledWith(COURSE_LIFECYCLE_BUSY_MESSAGE);
    expect(p.target.value).toEqual(active);
    p.request(active);
    expect(notify).toHaveBeenCalledTimes(2);
    d.resolve(true);
    await closing;
  });

  it('a close while a pause of the same course is pending is not sent', async () => {
    const d = deferred();
    authedFetch.mockReturnValue(d.promise);
    const closeCourse = vi.fn(async () => true);
    const { flow, p } = setup(closeCourse);
    p.request(active);
    const pausing = p.confirm();
    await vi.waitFor(() => expect(authedFetch).toHaveBeenCalledTimes(1));
    expect(await flow.close(active)).toBe(false);
    expect(await flow.closeFromDrawer(active)).toBe(false);
    expect(closeCourse).not.toHaveBeenCalled();
    d.resolve(res(true, {}));
    await pausing;
  });

  it('releases the lock after a successful pause', async () => {
    authedFetch.mockResolvedValue(res(true, {}));
    const closeCourse = vi.fn(async () => true);
    const { flow, p } = setup(closeCourse);
    p.request(active);
    await p.confirm();
    expect(flow.isPending(active)).toBe(false);
    expect(await flow.close(active)).toBe(true);
    expect(closeCourse).toHaveBeenCalledTimes(1);
  });

  it('releases the lock after a failed or throwing pause', async () => {
    const closeCourse = vi.fn(async () => true);
    const { flow, p } = setup(closeCourse);
    authedFetch.mockResolvedValueOnce(res(false, { message: 'no' }));
    p.request(active);
    await p.confirm();
    expect(flow.isPending(active)).toBe(false);
    authedFetch.mockRejectedValueOnce(new Error('net'));
    await p.confirm();
    expect(flow.isPending(active)).toBe(false);
    expect(await flow.close(active)).toBe(true);
  });

  it('releases the lock after a failed close, so a later pause is sent', async () => {
    const { flow, p } = setup(async () => { throw new Error('x'); });
    await expect(flow.close(active)).rejects.toThrow('x');
    authedFetch.mockResolvedValue(res(true, {}));
    p.request(active);
    await p.confirm();
    expect(authedFetch).toHaveBeenCalledTimes(1);
  });

  it('a pending close of one course does not block pausing a different course', async () => {
    const d = deferred();
    authedFetch.mockResolvedValue(res(true, {}));
    const { flow, p } = setup(() => d.promise);
    const closing = flow.close(active);
    p.request(other);
    await p.confirm();
    expect(authedFetch).toHaveBeenCalledTimes(1);
    expect(authedFetch.mock.calls[0][0]).toBe('/api/v1/student-classes/9/pause');
    expect(notify).not.toHaveBeenCalledWith(COURSE_LIFECYCLE_BUSY_MESSAGE);
    d.resolve(true);
    await closing;
  });
});
