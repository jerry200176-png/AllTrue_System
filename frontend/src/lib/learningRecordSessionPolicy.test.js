import { describe, expect, it } from 'vitest';
import {
  deduplicateLearningRecordSessions,
  pickBestLearningRecordSession,
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
