import { beforeEach, describe, expect, it, vi } from 'vitest';

const authedFetch = vi.fn();
const getAccessToken = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  authedFetch: (...a) => authedFetch(...a),
  getAccessToken: (...a) => getAccessToken(...a),
}));

import { useContractAmendment } from '../useContractAmendment';

const res = (ok, json) => ({ ok, json: async () => json });
const course = { id: 7 };
const init = (body) => ({
  method: 'POST', credentials: 'include',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify(body),
});
let reload, notify;
const make = () => useContractAmendment({ reload, notify });

beforeEach(() => {
  authedFetch.mockReset();
  getAccessToken.mockReset().mockResolvedValue('tok');
  reload = vi.fn(async () => {});
  notify = vi.fn();
});

describe('useContractAmendment amend', () => {
  it('open() resets preview/error and shows the modal', () => {
    const a = make();
    a.preview.value = { x: 1 }; a.error.value = 'e';
    a.open(course);
    expect([a.showModal.value, a.course.value, a.preview.value, a.error.value]).toEqual([true, course, null, '']);
  });

  it('loadPreview posts the numeric count and stores the preview', async () => {
    authedFetch.mockResolvedValue(res(true, { new_session_count: 3 }));
    const a = make(); a.open(course);
    await a.loadPreview('3');
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/contract-amendment/preview', init({ new_session_count: 3 }), 'tok');
    expect(a.preview.value).toEqual({ new_session_count: 3 });
    expect(a.previewLoading.value).toBe(false);
  });

  it('loadPreview reports login, server and fallback errors; no course sends nothing', async () => {
    const a = make();
    await a.loadPreview(3);
    expect(authedFetch).not.toHaveBeenCalled();
    a.open(course);
    getAccessToken.mockResolvedValue(undefined);
    await a.loadPreview(3);
    expect(a.error.value).toBe('登入狀態已失效，請重新登入。');
    getAccessToken.mockResolvedValue('tok');
    authedFetch.mockResolvedValueOnce(res(false, { message: 'nope' }));
    await a.loadPreview(3);
    expect(a.error.value).toBe('nope');
    authedFetch.mockResolvedValueOnce(res(false, {}));
    await a.loadPreview(3);
    expect(a.error.value).toBe('無法預覽合約調整。');
  });

  it('submit needs a preview, rejects a stale count, then posts count+reason, reloads and toasts', async () => {
    const a = make(); a.open(course);
    await a.submit({ newSessionCount: 3, reason: 'r' });
    expect(authedFetch).not.toHaveBeenCalled();
    a.preview.value = { new_session_count: 2 };
    await a.submit({ newSessionCount: 3, reason: 'r' });
    expect(a.error.value).toBe('預覽已過期，請重新預覽後再送出。');
    expect(a.preview.value).toBeNull();
    expect(authedFetch).not.toHaveBeenCalled();

    a.preview.value = { new_session_count: 3 };
    authedFetch.mockResolvedValue(res(true, {}));
    await a.submit({ newSessionCount: 3, reason: 'r' });
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/contract-amendment', init({ new_session_count: 3, reason: 'r' }), 'tok');
    expect(a.showModal.value).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(notify).toHaveBeenCalledWith({
      title: '合約已提前結束',
      description: '已調整為 3 堂；已上課紀錄保留，帳務未變更。',
      variant: 'success', durationMs: 7000,
    });
  });

  it('submit failure keeps the modal open with the server message and does not reload', async () => {
    const a = make(); a.open(course);
    a.preview.value = { new_session_count: 3 };
    authedFetch.mockResolvedValue(res(false, {}));
    await a.submit({ newSessionCount: 3, reason: 'r' });
    expect(a.error.value).toBe('合約調整失敗。');
    expect(a.showModal.value).toBe(true);
    expect(reload).not.toHaveBeenCalled();
    expect(a.submitting.value).toBe(false);
  });

  it('close() is ignored while previewing or submitting', () => {
    const a = make(); a.open(course);
    a.submitting.value = true; a.close();
    expect(a.showModal.value).toBe(true);
    a.submitting.value = false; a.close();
    expect(a.showModal.value).toBe(false);
  });
});

describe('useContractAmendment revert', () => {
  it('openRevert loads the preview from revert/preview with an empty body', async () => {
    authedFetch.mockResolvedValue(res(true, { ok: 1 }));
    const a = make();
    await a.openRevert(course);
    expect(authedFetch).toHaveBeenCalledWith('/api/v1/student-classes/7/contract-amendment/revert/preview', init({}), 'tok');
    expect(a.showRevertModal.value).toBe(true);
    expect(a.revertPreview.value).toEqual({ ok: 1 });
    expect(a.revertLoading.value).toBe(false);
  });

  it('openRevert error uses the message, falling back to the revert copy', async () => {
    authedFetch.mockResolvedValue(res(false, {}));
    const a = make();
    await a.openRevert(course);
    expect(a.revertError.value).toBe('撤銷調整失敗。');
  });

  it('submitRevert posts the reason, closes, reloads and toasts the server message', async () => {
    authedFetch.mockResolvedValueOnce(res(true, {}));
    const a = make();
    await a.openRevert(course);
    authedFetch.mockResolvedValue(res(true, { message: 'done' }));
    await a.submitRevert('why');
    expect(authedFetch).toHaveBeenLastCalledWith('/api/v1/student-classes/7/contract-amendment/revert', init({ reason: 'why' }), 'tok');
    expect(a.showRevertModal.value).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(notify).toHaveBeenCalledWith({ title: '已撤銷調整', description: 'done', variant: 'success', durationMs: 7000 });
  });

  it('submitRevert failure shows the error and does not reload', async () => {
    authedFetch.mockResolvedValueOnce(res(true, {}));
    const a = make();
    await a.openRevert(course);
    authedFetch.mockResolvedValue(res(false, { message: 'bad' }));
    await a.submitRevert('why');
    expect(a.revertError.value).toBe('bad');
    expect(a.showRevertModal.value).toBe(true);
    expect(reload).not.toHaveBeenCalled();
  });
});
