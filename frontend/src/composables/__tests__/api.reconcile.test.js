import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getReconcileLatest } from '../../api';

describe('getReconcileLatest', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('unwraps the backend data envelope', async () => {
    const report = { checked_at: '2026-07-17 02:00:00', mismatch_count: 2 };
    fetch.mockResolvedValue({
      status: 200,
      ok: true,
      json: vi.fn().mockResolvedValue({ data: report }),
    });

    await expect(getReconcileLatest('token')).resolves.toEqual(report);
    expect(fetch).toHaveBeenCalledWith('/api/v1/admin/reconcile/latest', {
      headers: {
        Authorization: 'Bearer token',
        Accept: 'application/json',
      },
    });
  });

  it('maps a missing report to null', async () => {
    fetch.mockResolvedValue({ status: 404, ok: false });

    await expect(getReconcileLatest('token')).resolves.toBeNull();
  });

  it('surfaces the backend error message', async () => {
    fetch.mockResolvedValue({
      status: 500,
      ok: false,
      json: vi.fn().mockResolvedValue({ message: '最新對帳報告無法讀取' }),
    });

    await expect(getReconcileLatest('token')).rejects.toThrow('最新對帳報告無法讀取');
  });
});
