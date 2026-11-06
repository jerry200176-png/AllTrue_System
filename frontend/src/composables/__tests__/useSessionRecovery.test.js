import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { useSessionRecovery } from '../useSessionRecovery.js';
import RestoreCancelledSessionDialog from '../../components/course-management/RestoreCancelledSessionDialog.vue';
import { isVisibleCancelledSession } from '../../lib/sessionOccurrenceFilter.js';

const json = (body, ok = true, status = 200) => ({ ok, status, json: async () => body });

describe('useSessionRecovery', () => {
  it('loads availability and posts expected_audit_id + trimmed reason', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(json({ available: true, audit_id: 9, previous_status: 'attended' }))
      .mockResolvedValueOnce(json({ message: '已復原' }));
    const { recovery, load, restore } = useSessionRecovery({ fetcher });
    await load(5);
    expect(fetcher.mock.calls[0][0]).toBe('/api/v1/class-sessions/5/recovery');
    expect(recovery.value).toMatchObject({ available: true, audit_id: 9, previousStatusLabel: '已上課', loading: false });
    recovery.value.reason = ' 誤取消 ';
    const data = await restore(5);
    expect(data.message).toBe('已復原');
    const [url, init] = fetcher.mock.calls[1];
    expect(url).toBe('/api/v1/class-sessions/5/restore');
    expect(JSON.parse(init.body)).toEqual({ expected_audit_id: 9, reason: '誤取消' });
    expect(recovery.value.available).toBe(false);
  });

  it('does not post when unavailable or reason empty, and surfaces the backend message as-is', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(json({ available: true, audit_id: 1, previous_status: 'scheduled' }))
      .mockResolvedValueOnce(json({ message: '此堂已有新變更，無法復原' }, false, 409));
    const { recovery, load, restore } = useSessionRecovery({ fetcher });
    await load(2);
    recovery.value.reason = '  ';
    expect(await restore(2)).toBeNull();
    expect(fetcher).toHaveBeenCalledTimes(1);
    recovery.value.reason = '誤取消';
    await expect(restore(2)).rejects.toThrow('此堂已有新變更，無法復原');
    expect(recovery.value.submitting).toBe(false);
  });
});

describe('cancelled chip eligibility', () => {
  it('only a real cancelled row qualifies, not moved/rescheduled/internal placeholders', () => {
    expect(isVisibleCancelledSession({ id: 1, status: 'cancelled' })).toBe(true);
    expect(isVisibleCancelledSession({ id: 1, status: 'rescheduled' })).toBe(false);
    expect(isVisibleCancelledSession({ id: 1, status: 'cancelled', note: 'cancelled-duplicate-reschedule-placeholder' })).toBe(false);
  });
});

describe('RestoreCancelledSessionDialog', () => {
  afterEach(() => { document.body.innerHTML = ''; });

  it('check -> reason -> restore emits restored; refusal is shown verbatim', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(json({ available: true, audit_id: 3, previous_status: 'scheduled' }))
      .mockResolvedValueOnce(json({ message: '同時段已有其他課，無法復原' }, false, 422))
      .mockResolvedValueOnce(json({ message: '已復原' }));
    vi.stubGlobal('fetch', fetchMock);
    const w = mount(RestoreCancelledSessionDialog, { props: { unit: { id: 8, status: 'cancelled' } }, attachTo: document.body });
    await flushPromises(); await flushPromises();
    const btn = () => document.body.querySelector('[data-testid="restore-submit"]');
    btn().click(); await flushPromises(); await flushPromises();
    expect(document.body.querySelector('[data-testid="restore-error"]').textContent).toBe('同時段已有其他課，無法復原');
    expect(w.emitted('restored')).toBeUndefined();
    btn().click(); await flushPromises(); await flushPromises();
    expect(w.emitted('restored')).toHaveLength(1);
    w.unmount();
    vi.unstubAllGlobals();
  });
});
