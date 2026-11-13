import { describe, it, expect } from 'vitest';
import { buildCourseListSnapshot, COURSE_SNAPSHOT_ROW_KEYS, COURSE_SNAPSHOT_HISTORY_LIMIT } from './courseListSnapshot.js';
import { classificationUnknown, isCourseManagementHistory } from './courseMoneyState.js';
import { registerPageDiagnostics, getPageSnapshot } from './pageDiagnostics.js';
import { fitClientInfo, CLIENT_INFO_MAX } from './recentApiFailures.js';

// in-app #540: unknown is not history.
const live = { id: 4011, payment_type: 'session', sessions_purchased: 8, remaining_sessions: 3, status: 'active', closed_reason: null, history_reason: null };

describe('fallback rows never default to history (in-app #540)', () => {
  it('a paused fallback row with no payment mode and no purchased count is unknown, not history', () => {
    const row = { ...live, payment_type: undefined, sessions_purchased: undefined, status: 'inactive', _noncanonical: true };
    expect(classificationUnknown(row)).toBe(true);
    expect(isCourseManagementHistory(row)).toBe(false);
  });
  it('a fallback row without status is unknown and not history', () => {
    const row = { ...live, status: undefined, _noncanonical: true };
    expect(classificationUnknown(row)).toBe(true);
    expect(isCourseManagementHistory(row)).toBe(false);
  });
  it('existing verdicts are unchanged', () => {
    expect(isCourseManagementHistory({ ...live, _noncanonical: true })).toBe(false);
    expect(isCourseManagementHistory({ ...live, status: 'inactive', _noncanonical: true })).toBe(false);
    expect(isCourseManagementHistory({ ...live, history_reason: 'completed' })).toBe(true);
    expect(isCourseManagementHistory({ ...live, closed_reason: 'settled', _noncanonical: true })).toBe(true);
    expect(isCourseManagementHistory({ id: 1, payment_type: 'monthly', status: 'inactive', _noncanonical: true })).toBe(true);
    expect(classificationUnknown({ ...live, history_reason: 'completed' })).toBe(false);
  });
});

describe('course-mgmt report snapshot', () => {
  const rows = Array.from({ length: 25 }, (_, i) => ({
    ...live, id: 100 + i, history_reason: 'completed', student_name: '王小明', Charge: 9999, memo: 'free text', teacher_name: 'T',
  }));

  it('contains only the agreed keys, capped at 20 history rows', () => {
    const snap = buildCourseListSnapshot({ source: 'fallback', courses: [live, ...rows] });
    expect(Object.keys(snap).sort()).toEqual(['history', 'historyRows', 'listSource', 'working']);
    expect(snap).toMatchObject({ listSource: 'fallback', working: 1, history: 25 });
    expect(snap.historyRows).toHaveLength(COURSE_SNAPSHOT_HISTORY_LIMIT);
    for (const r of snap.historyRows) expect(Object.keys(r).sort()).toEqual([...COURSE_SNAPSHOT_ROW_KEYS].sort());
    const text = JSON.stringify(snap);
    expect(text).not.toContain('王小明');
    expect(text).not.toContain('9999');
    expect(text).not.toContain('free text');
    expect(snap.historyRows[0]).toEqual({ id: 100, history_reason: 'completed', status: 'active', payment_type: 'session', remaining: 3, noncanonical: false });
  });

  it('drops values that are not plain enums and unknown sources', () => {
    const snap = buildCourseListSnapshot({ source: 'x', courses: [{ id: 5, history_reason: 'a free text reason!', status: 'inactive', _noncanonical: true, closed_reason: 'settled' }] });
    expect(snap.listSource).toBeNull();
    expect(snap.historyRows[0].history_reason).toBe('settled');
    expect(snap.historyRows[0].noncanonical).toBe(true);
  });

  it('fits the snapshot with 20 rows inside the client_info limit', () => {
    const snap = buildCourseListSnapshot({ source: 'primary', courses: rows });
    expect(fitClientInfo({ pageSnapshot: snap, recentClicks: [] }).length).toBeLessThanOrEqual(CLIENT_INFO_MAX);
  });

  it('is best-effort: a missing, throwing or oversized provider yields null', () => {
    expect(getPageSnapshot('nope')).toBeNull();
    const off = registerPageDiagnostics('t-throw', () => { throw new Error('x'); });
    expect(getPageSnapshot('t-throw')).toBeNull();
    off();
    registerPageDiagnostics('t-big', () => ({ s: 'x'.repeat(5000) }));
    expect(getPageSnapshot('t-big')).toBeNull();
    const off2 = registerPageDiagnostics('t-ok', () => ({ a: 1 }));
    expect(getPageSnapshot('t-ok')).toEqual({ a: 1 });
    off2();
    expect(getPageSnapshot('t-ok')).toBeNull();
  });
});
