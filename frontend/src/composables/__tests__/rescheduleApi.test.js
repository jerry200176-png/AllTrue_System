import { describe, expect, it, vi } from 'vitest';
import { commitReschedule, RescheduleApiError } from '../../lib/rescheduleApi';

describe('commitReschedule', () => {
  it('uses one atomic API request and returns only committed responses', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ committed: true, session_id: 88 }),
    });

    const result = await commitReschedule({
      token: 'token',
      payload: { student_class_id: 9, old_date: '2026-07-14', old_start_time: '16:00' },
      fetchImpl,
    });

    expect(result.session_id).toBe(88);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetchImpl.mock.calls[0][1].body)).toMatchObject({
      ensure_schedule_exception: true,
      old_start_time: '16:00',
    });
  });

  it('does not report success when the server rejects or only partially responds', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ message: 'legacy partial response' }),
    });

    await expect(commitReschedule({ token: 'token', payload: {}, fetchImpl }))
      .rejects.toBeInstanceOf(RescheduleApiError);
  });

  it('preserves conflict details for actionable UI copy', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ message: '時段衝突', code: 'schedule_conflict', conflicts: [{ id: 1 }] }),
    });

    await expect(commitReschedule({ token: 'token', payload: {}, fetchImpl }))
      .rejects.toMatchObject({ status: 409, code: 'schedule_conflict', conflicts: [{ id: 1 }] });
  });
});
