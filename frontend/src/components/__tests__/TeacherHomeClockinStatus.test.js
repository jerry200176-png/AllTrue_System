import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TeacherHomePage from '../../pages/TeacherHomePage.vue';
import { fetchClassSessions, fetchClassSessionsProjection } from '../../lib/classSessionsApi';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));
vi.mock('../../lib/classSessionsApi', () => ({
  fetchClassSessions: vi.fn(),
  fetchClassSessionsProjection: vi.fn(),
}));
vi.mock('../../lib/adoptionTelemetry', () => ({ trackAdoptionEvent: vi.fn() }));

const ok = (body) => ({ ok: true, json: async () => body });
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((a, b) => { resolve = a; reject = b; });
  return { promise, resolve, reject };
};
const wrappers = [];
let today;

function render() {
  const w = mount(TeacherHomePage, {
    props: { branchId: 9, teacherBranchIds: [9], userRole: 'teacher' },
    global: { stubs: { ReportDiscrepancyModal: true, EngagementRankStrip: true, TeacherMonthlyAttendance: true } },
  });
  wrappers.push(w);
  return w;
}

function badge(w) { return w.get('#teacher-clockin-status').text(); }

beforeEach(() => {
  fetchClassSessions.mockResolvedValue({ items: [] });
  fetchClassSessionsProjection.mockResolvedValue({ items: [] });
  today = vi.fn();
  vi.stubGlobal('fetch', vi.fn((url) => String(url).includes('/teacher-attendance/today')
    ? today()
    : Promise.resolve(ok(String(url).includes('awaiting-reply-count') ? { awaiting_reply_count: 0 } : []))));
});

afterEach(() => {
  for (const w of wrappers.splice(0)) w.unmount();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('teacher clock-in status reflects verified data', () => {
  it('shows loading and unavailable rather than claiming no record when the request fails', async () => {
    const waiting = deferred();
    today.mockReturnValue(waiting.promise);
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe('確認中');
    waiting.reject(new Error('offline'));
    await flushPromises();
    expect(badge(w)).toBe('無法確認');
    expect(w.get('.th-clockin-card').text()).toContain('打卡狀態無法載入');
    expect(w.get('.th-clockin-card').text()).not.toContain('尚未打卡');
    await w.get('.th-clockin-card').trigger('click');
    expect(w.emitted('navigate')?.at(-1)).toEqual(['attendance']);
  });

  it.each([
    ['HTTP failure', () => Promise.resolve({ ok: false })],
    ['malformed response', () => Promise.resolve(ok({}))],
  ])('treats %s as unavailable', async (_name, response) => {
    today.mockImplementation(response);
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe('無法確認');
    expect(w.get('.th-clockin-card').text()).not.toContain('尚未打卡');
  });

  it.each([
    [{ status: 'no_record', sign_in_dt: null }, '尚未打卡'],
    [{ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }, '上班中'],
    [{ status: 'normal', sign_in_dt: '2026-10-27 09:01:00', sign_out_dt: '2026-10-27 18:00:00' }, '已完成'],
    [{ status: 'late', sign_in_dt: '2026-10-27 09:15:00' }, '遲到'],
    [{ status: 'source_only', sign_in_dt: '2026-10-27 09:01:00' }, '待確認'],
    [{ status: 'no_record', sign_in_dt: '2026-10-27 09:01:00' }, '待確認'],
    [{ sign_in_dt: '2026-10-27 09:01:00' }, '待確認'],
    [{ status: 'normal', sign_in_dt: null }, '待確認'],
  ])('preserves the confirmed result for %o', async (record, expected) => {
    today.mockResolvedValue(ok(record));
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe(expected);
  });

  it('refreshes when the page becomes visible and clears stale times after a failed refresh', async () => {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    today.mockResolvedValueOnce(ok({ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }))
      .mockResolvedValueOnce({ ok: false });
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe('上班中');
    expect(w.get('.th-clockin-card').text()).toContain('09:01');
    document.dispatchEvent(new Event('visibilitychange'));
    await flushPromises();
    expect(today).toHaveBeenCalledTimes(2);
    expect(badge(w)).toBe('無法確認');
    expect(w.get('.th-clockin-card').text()).not.toContain('09:01');
  });

  it('refreshes the card during the existing visible-page poll', async () => {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    const interval = vi.spyOn(globalThis, 'setInterval');
    today.mockResolvedValueOnce(ok({ status: 'no_record', sign_in_dt: null }))
      .mockResolvedValueOnce(ok({ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }));
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe('尚未打卡');
    const poll = interval.mock.calls.find(([, delay]) => delay === 60000)?.[0];
    expect(poll).toBeTypeOf('function');
    poll();
    await flushPromises();
    expect(today).toHaveBeenCalledTimes(2);
    expect(badge(w)).toBe('上班中');
  });

  it.each(['success', 'failure'])('does not let an older %s overwrite a later refresh', async (olderResult) => {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    const older = deferred();
    const newer = deferred();
    today.mockResolvedValueOnce(ok({ status: 'no_record', sign_in_dt: null }))
      .mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise);
    const w = render();
    await flushPromises();
    document.dispatchEvent(new Event('visibilitychange'));
    document.dispatchEvent(new Event('visibilitychange'));
    await flushPromises();
    expect(today).toHaveBeenCalledTimes(3);
    newer.resolve(ok({ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }));
    await flushPromises();
    expect(badge(w)).toBe('上班中');
    if (olderResult === 'failure') older.reject(new Error('late failure'));
    else older.resolve(ok({ status: 'no_record', sign_in_dt: null }));
    await flushPromises();
    expect(badge(w)).toBe('上班中');
  });

  it('recovers on manual refresh and does not poll while hidden', async () => {
    const visible = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    const interval = vi.spyOn(globalThis, 'setInterval');
    today.mockResolvedValueOnce({ ok: false }).mockResolvedValueOnce(ok({ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }));
    const w = render();
    await flushPromises();
    expect(badge(w)).toBe('無法確認');
    const refresh = w.findAll('button').find((button) => button.text().includes('重新整理'));
    expect(refresh).toBeDefined();
    await refresh.trigger('click');
    await flushPromises();
    expect(badge(w)).toBe('上班中');
    visible.mockReturnValue('hidden');
    const poll = interval.mock.calls.find(([, delay]) => delay === 60000)?.[0];
    poll();
    await flushPromises();
    expect(today).toHaveBeenCalledTimes(2);
  });

  it('removes refresh listeners and ignores an in-flight response after unmount', async () => {
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    const pending = deferred();
    today.mockReturnValue(pending.promise);
    const w = render();
    await flushPromises();
    w.unmount();
    document.dispatchEvent(new Event('visibilitychange'));
    pending.resolve(ok({ status: 'normal', sign_in_dt: '2026-10-27 09:01:00' }));
    await flushPromises();
    expect(today).toHaveBeenCalledTimes(1);
  });
});
