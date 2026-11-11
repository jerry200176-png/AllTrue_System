// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { compareDrawnCards, describeDrawnCards, drawsServedSessions, sessionsOfDay } from './calendarDrawnCards.js';

const DAY = '2026-08-05';
const row = (student, time, extra = {}) => ({
  session_date: DAY, start_time: `${time}:00`, student_name: student, status: 'scheduled', ...extra,
});
const label = (student, time, date = DAY, subject = '數學') => `${date} ${time}:00 ${student} ${subject}，點擊查看詳細資訊`;

describe('calendar drawn cards (#5178)', () => {
  const rows = [
    row('甲', '10:00'),
    row('乙', '14:00'),
    row('丙', '16:00', { status: 'cancelled' }),
    row('丁', '10:00', { session_date: '2026-08-06' }),
  ];

  it('counts only live, named sessions of the day as expected', () => {
    expect(sessionsOfDay(rows, DAY).live.map((s) => s.student)).toEqual(['甲', '乙']);
    expect(sessionsOfDay(rows, DAY).all).toHaveLength(3);
  });

  it('accepts a day whose served sessions are drawn', () => {
    const result = compareDrawnCards([label('甲', '10:00'), label('乙', '14:00'), label('丙', '16:00')], rows, DAY);
    expect(result).toMatchObject({ expected: 2, matched: 2, wrongDay: [], phantom: [] });
    expect(drawsServedSessions(result)).toBe(true);
  });

  it('rejects an empty calendar', () => {
    expect(drawsServedSessions(compareDrawnCards([], rows, DAY))).toBe(false);
  });

  it('rejects cards of another day even when the time and student exist on this day', () => {
    const result = compareDrawnCards([label('甲', '10:00', '2026-08-04')], rows, DAY);
    expect(result.wrongDay).toHaveLength(1);
    expect(result.matched).toBe(0);
    expect(drawsServedSessions(result)).toBe(false);
  });

  it('rejects a card with no served session behind it', () => {
    const result = compareDrawnCards([label('甲', '10:00'), label('戊', '12:00')], rows, DAY);
    expect(result.phantom).toEqual([label('戊', '12:00')]);
    expect(drawsServedSessions(result)).toBe(false);
  });

  it('matches by time, not by student alone', () => {
    expect(compareDrawnCards([label('甲', '11:00')], rows, DAY).matched).toBe(0);
  });

  it('describes a result for the failure message', () => {
    expect(describeDrawnCards({ expected: 2, matched: 1, wrongDay: [], phantom: ['x'] })).toContain('drawn for them=1');
  });
});
