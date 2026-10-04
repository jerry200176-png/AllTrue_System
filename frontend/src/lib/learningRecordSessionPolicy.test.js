import { describe, expect, it } from 'vitest';
import {
  deduplicateLearningRecordSessions,
  pickBestLearningRecordSession,
  selectFormDaySession,
  pickSessionRecord,
} from './learningRecordSessionPolicy.js';

const normalizeTime = (value) => String(value || '').trim().slice(0, 5);

describe('Learning Records session policy', () => {
  it('prefers attended/completed rows, then the newest id for a single identity', () => {
    expect(pickBestLearningRecordSession([
      { id: 100, status: 'scheduled' },
      { id: 200, status: 'attended' },
    ])).toEqual({ id: 200, status: 'attended' });

    expect(pickBestLearningRecordSession([
      { id: 100, status: 'scheduled' },
      { id: 200, status: 'scheduled' },
    ])).toEqual({ id: 200, status: 'scheduled' });
  });

  it('preserves separate materialized ClassSession IDs at the same slot', () => {
    const rows = deduplicateLearningRecordSessions([
      { id: 101, date: '2026-08-21', startTime: '15:00', status: 'scheduled' },
      { id: 202, date: '2026-08-21', startTime: '15:00', status: 'attended' },
    ], normalizeTime);

    expect(rows.map((row) => row.id)).toEqual([101, 202]);
  });

  it('collapses only id-less rows sharing a normalized slot', () => {
    const rows = deduplicateLearningRecordSessions([
      { date: '2026-08-21', startTime: '15:00:00', status: 'scheduled' },
      { date: '2026-08-21', startTime: '15:00', status: 'attended' },
      { date: '2026-08-21', startTime: '16:00', status: 'scheduled' },
    ], normalizeTime);

    expect(rows).toHaveLength(2);
    expect(rows).toContainEqual({ date: '2026-08-21', startTime: '15:00', status: 'attended' });
    expect(rows).toContainEqual({ date: '2026-08-21', startTime: '16:00', status: 'scheduled' });
  });

  it('does not emit projected rows and keeps rows that cannot form a slot key', () => {
    const rows = deduplicateLearningRecordSessions([
      { id: 1, isProjected: true, date: '2026-08-21', startTime: '15:00' },
      { id: 2, date: '2026-08-21', startTime: '', status: 'scheduled' },
      { id: 3, date: '', startTime: '15:00', status: 'attended' },
    ], normalizeTime);

    expect(rows.map((row) => row.id)).toEqual([2, 3]);
  });
});

describe('selectFormDaySession', () => {
  const day = [
    { id: 1, status: 'rescheduled', startTime: '18:00:00' },
    { id: 2, status: 'attended', startTime: '19:30:00' },
  ];
  it('binds the opened session id, not the first row of the day', () => {
    expect(selectFormDaySession(day, { classSessionId: 2, normalizeTime }).id).toBe(2);
  });
  it('never falls back to another row when the id is unknown', () => {
    expect(selectFormDaySession(day, { classSessionId: 99, normalizeTime })).toBeNull();
  });
  it('without id prefers matching time, then the attended row', () => {
    expect(selectFormDaySession(day, { startTime: '19:30', normalizeTime }).id).toBe(2);
    expect(selectFormDaySession(day, { normalizeTime }).id).toBe(2);
  });
});

describe('pickSessionRecord', () => {
  it('never binds another session\'s record in the same slot', () => {
    const other = { ID: 7, ClassSessionID: 202 };
    expect(pickSessionRecord({ csId: 201, byCs: null, byTime: other, byDate: other })).toBeNull();
    expect(pickSessionRecord({ csId: 201, byCs: { ID: 8, ClassSessionID: 201 }, byTime: other })).toEqual({ ID: 8, ClassSessionID: 201 });
    const legacy = { ID: 9, ClassSessionID: null };
    // Slot holds a legacy unbound record and another session's bound record; the bound one won byTime.
    expect(pickSessionRecord({ csId: 201, byTime: other, byUnboundTime: legacy })).toBe(legacy);
    expect(pickSessionRecord({ csId: 0, byTime: other })).toBe(other);
  });
});

describe('selectFormDaySession attendance', () => {
  it('prefers an attended lesson over an absent one when no time matches', () => {
    const t = (v) => String(v || '').slice(0, 5);
    const day = [{ id: 1, status: 'attended', startTime: '10:00' }, { id: 2, status: 'absent', startTime: '14:00' }];
    expect(selectFormDaySession(day, { startTime: '18:00', normalizeTime: t }).id).toBe(1);
  });
});
