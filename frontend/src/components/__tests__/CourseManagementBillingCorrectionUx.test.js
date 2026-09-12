import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  buildBillingCorrectionBlockedState,
  formatBillingCorrectionSessionLabel,
  normalizeAffectedScheduledSessions,
} from '../../lib/billingCorrectionUx.js';

describe('in-app #287 billing correction guidance', () => {
  it('turns the production-shaped conflict into safe, actionable copy', () => {
    const state = buildBillingCorrectionBlockedState({
      message: '受影響堂次：#35056 2026-09-29 20:00',
      code: 'billing_correction_future_schedule_over_capacity',
      affected_scheduled_sessions: [{
        session_id: 35056, session_date: '2026-09-29', start_time: '20:00', end_time: '22:00', status: 'scheduled',
      }],
      next_step: 'handle_affected_scheduled_sessions_then_retry',
    });

    expect(state.message).toBe('更正後堂數會少於目前已排堂次，因此尚未儲存。');
    expect(state.message).not.toContain('#35056');
    expect(state.hint).toContain('系統不會自動取消');
    expect(state.firstAffectedDate).toBe('2026-09-29');
    expect(state.canOpenCalendar).toBe(true);
    expect(formatBillingCorrectionSessionLabel(state.affectedSessions[0])).toBe('2026-09-29 20:00–22:00');
  });

  it('sorts, deduplicates, and rejects malformed affected sessions', () => {
    expect(normalizeAffectedScheduledSessions([
      { session_id: '8', session_date: '2026-10-02T00:00:00Z', start_time: '09:30:00', end_time: '11:00:00' },
      { session_id: 7, session_date: '2026-10-01', start_time: '20:00' },
      { session_id: 7, session_date: '2026-10-01', start_time: '20:00' },
      { session_id: -1, session_date: 'not-a-date', start_time: '20:00' },
    ])).toEqual([
      { sessionId: 7, sessionDate: '2026-10-01', startTime: '20:00', endTime: '' },
      { sessionId: 8, sessionDate: '2026-10-02', startTime: '09:30', endTime: '11:00' },
    ]);
  });

  it('keeps the page contract aligned with the fail-closed backend rule', () => {
    const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
    expect(source).not.toContain('超出新堂數的未上課排程會取消');
    expect(source).toContain('billingCorrectionBlocked.affectedSessions');
    expect(source).toContain('前往行事曆處理');
    expect(source).toContain('openBillingCorrectionCalendar');
  });
});
