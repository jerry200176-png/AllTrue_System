import { describe, expect, it, vi } from 'vitest';

import { installScheduleWarnings, scheduleWarningsFrom } from './scheduleWarnings';

const response = (ok, header) => ({ ok, headers: { get: (name) => (name === 'X-Schedule-Warnings' ? header : null) } });

describe('scheduleWarnings', () => {
  it('reads one reminder per line from a successful response only', () => {
    const header = encodeURIComponent('提醒：甲\n提醒：乙');
    expect(scheduleWarningsFrom(response(true, header))).toEqual(['提醒：甲', '提醒：乙']);
    expect(scheduleWarningsFrom(response(false, header))).toEqual([]);
    expect(scheduleWarningsFrom(response(true, null))).toEqual([]);
    expect(scheduleWarningsFrom(response(true, '%E0%A4%A'))).toEqual([]);
  });

  it('shows each reminder and still returns the response', async () => {
    const res = response(true, encodeURIComponent('提醒：甲'));
    const target = { fetch: vi.fn().mockResolvedValue(res) };
    const shown = [];
    installScheduleWarnings(target, (m) => shown.push(m));
    expect(await target.fetch('/api/v1/x', { method: 'POST' })).toBe(res);
    expect(shown).toEqual(['提醒：甲']);
  });
});
