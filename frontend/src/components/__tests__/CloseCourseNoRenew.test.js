import { describe, expect, it } from 'vitest';
import { closeCourseNoRenew } from '../../lib/closeCourseNoRenew.js';

function setup({ remaining = 0, settled = true, accepted = true, response = { pending_reconciliation: true } } = {}) {
  const calls = { requests: [], alerts: [], confirms: [], reloads: 0 };
  const deps = {
    course: { id: 42, subject: '英文', remaining_sessions: remaining },
    studentName: '學生',
    getRemainingSessions: (course) => course.remaining_sessions,
    getSubjectLabel: (subject) => subject,
    isCourseSettled: () => settled,
    supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'test-token' } } }) } },
    reloadCourses: async () => { calls.reloads += 1; },
    confirmImpl: (message) => { calls.confirms.push(message); return accepted; },
    alertImpl: (message) => calls.alerts.push(message),
    fetchImpl: async (url, options) => {
      calls.requests.push({ url, options });
      return { ok: true, json: async () => response };
    },
  };
  return { calls, deps };
}

describe('shared close-course action', () => {
  it('preserves remaining-session, unpaid reconciliation and existing endpoint semantics', async () => {
    const { calls, deps } = setup({ remaining: 2, settled: false });
    await closeCourseNoRenew(deps);
    expect(calls.confirms[0]).toContain('放棄這 2 堂剩餘額度');
    expect(calls.confirms[0]).toContain('待對帳');
    expect(calls.requests[0].url).toBe('/api/v1/student-classes/42/pause');
    expect(calls.requests[0].options.method).toBe('POST');
    expect(JSON.parse(calls.requests[0].options.body)).toEqual({
      action: 'pause', reason: 'settled', forfeit_remaining: true,
    });
    expect(calls.reloads).toBe(1);
    expect(calls.alerts[0]).toContain('結案待對帳');
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
});
