import { beforeEach, describe, expect, it, vi } from 'vitest';
import { previewSingleCoursePackageConversion } from '../../lib/coursePackagesApi';

describe('course package conversion preview API', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    global.fetch = vi.fn();
    localStorage.clear();
  });

  it('uses a read-only GET endpoint and returns the safety result', async () => {
    const payload = {
      read_only: true,
      can_convert: false,
      recommendation: 'create_new_package',
      blocking_reasons: [{ code: 'invoice_exists', message: '已有帳單資料，不能直接轉換。' }],
    };
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => payload,
    });

    await expect(previewSingleCoursePackageConversion(42)).resolves.toEqual(payload);
    expect(global.fetch).toHaveBeenCalledWith(
      '/api/v1/student-classes/42/package-conversion-preview',
      { headers: { 'Content-Type': 'application/json' } },
    );
  });

  it('surfaces the server error without retrying or mutating', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 403,
      json: async () => ({ message: 'Forbidden' }),
    });

    await expect(previewSingleCoursePackageConversion(42)).rejects.toThrow('Forbidden');
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch.mock.calls[0][1].method).toBeUndefined();
  });
});
