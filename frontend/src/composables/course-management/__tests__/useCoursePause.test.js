import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { useCoursePause } from '../useCoursePause';

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
