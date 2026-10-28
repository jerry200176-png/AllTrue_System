import { describe, expect, it, vi } from 'vitest';
import { useMonthlyAttendanceSummary } from '../useMonthlyAttendanceSummary.js';

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
describe('monthly attendance summary request state', () => {
  it('does not let an older result overwrite a newer request', async () => {
    const first = deferred(), second = deferred();
    const fetchSummary = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const summary = useMonthlyAttendanceSummary({ getToken: async () => 'test', fetchSummary });
    const a = summary.refresh(7, 42); await Promise.resolve();
    const b = summary.refresh(7, 42); await Promise.resolve();
    second.resolve({ count: 3, cancelled: 0 }); await b;
    first.resolve({ count: 2, cancelled: 0 }); await a;
    expect(summary.states.value['7:42']).toEqual({ status: 'ready', count: 3, cancelled: 0 });
  });
  it('distinguishes initial failure, successful zero and failed refresh of prior data', async () => {
    const fetchSummary = vi.fn().mockRejectedValueOnce(new Error()).mockResolvedValueOnce({ count: 0, cancelled: 0 }).mockRejectedValueOnce(new Error());
    const summary = useMonthlyAttendanceSummary({ getToken: async () => 'test', fetchSummary });
    await summary.refresh(7, 42); expect(summary.states.value['7:42']).toEqual({ status: 'error', count: null, cancelled: 0 });
    await summary.refresh(7, 42); expect(summary.states.value['7:42']).toEqual({ status: 'ready', count: 0, cancelled: 0 });
    await summary.refresh(7, 42); expect(summary.states.value['7:42']).toEqual({ status: 'error', count: 0, cancelled: 0 });
  });
  it('does not begin reading after a pending token resolves for a closed course', async () => {
    const token = deferred();
    const fetchSummary = vi.fn();
    const summary = useMonthlyAttendanceSummary({ getToken: () => token.promise, fetchSummary });
    const request = summary.refresh(7, 42);
    summary.cancel('7:42'); token.resolve('test'); await request;
    expect(fetchSummary).not.toHaveBeenCalled();
  });
  it('aborts an in-flight request when its scope is cleared', async () => {
    const pending = deferred();
    let signal;
    const fetchSummary = vi.fn(({ signal: s }) => { signal = s; return pending.promise; });
    const summary = useMonthlyAttendanceSummary({ getToken: async () => 'test', fetchSummary });
    const request = summary.refresh(7, 42); await Promise.resolve();
    expect(signal.aborted).toBe(false);
    summary.clear(); expect(signal.aborted).toBe(true);
    pending.resolve({ count: 1 }); await request;
    expect(summary.states.value).toEqual({});
  });
  it('ignores responses after scope is cleared, the course closes, or the view unmounts', async () => {
    const pending = deferred();
    const summary = useMonthlyAttendanceSummary({ getToken: async () => 'test', fetchSummary: () => pending.promise });
    const request = summary.refresh(7, 42); await Promise.resolve();
    summary.cancel('7:42'); summary.clear(); summary.dispose();
    pending.resolve({ count: 9 }); await request;
    expect(summary.states.value).toEqual({});
  });
});
