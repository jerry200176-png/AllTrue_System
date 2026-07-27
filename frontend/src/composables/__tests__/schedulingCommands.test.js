import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { restoreContractTeacher, schedulingCommands } from '../../lib/schedulingCommands.js';

describe('schedulingCommands.restoreContractTeacher', () => {
  beforeEach(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'tok' }));
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    localStorage.removeItem('alltrue_session');
  });

  it('POSTs named endpoint with session_id only (no teacher identity)', async () => {
    const fetchMock = vi.fn(async () => ({
      ok: true,
      json: async () => ({
        restored_teacher_id: 70,
        code: 'restore_contract_teacher',
      }),
    }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await restoreContractTeacher(22359, { reason: '回復正班老師' });
    expect(result.restored_teacher_id).toBe(70);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, opts] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/v1/class-sessions/22359/restore-contract-teacher');
    expect(opts.method).toBe('POST');
    const body = JSON.parse(opts.body);
    expect(body).toEqual({ reason: '回復正班老師' });
    expect(body).not.toHaveProperty('teacher_id');
    expect(body).not.toHaveProperty('substitute_teacher_id');
    expect(body).not.toHaveProperty('contract_teacher_id');
  });

  it('exposes the same function on schedulingCommands object', () => {
    expect(schedulingCommands.restoreContractTeacher).toBe(restoreContractTeacher);
  });
});
