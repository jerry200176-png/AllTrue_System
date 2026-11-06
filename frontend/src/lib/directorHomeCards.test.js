import { describe, it, expect } from 'vitest';
import { owedCard, renewalCount, oldestEvaluationAge, hourBuckets } from './directorHomeCards.js';

const alerts = [
  { alert_type: 'unpaid', payment_status: 'unpaid' },
  { alert_type: 'unpaid', payment_status: 'partial' },
  { alert_type: 'unpaid', payment_status: 'pending_report' },
  { alert_type: 'unpaid', payment_status: 'amount_review' },
  { alert_type: 'monthly_due_soon', days_until_settlement: -2 },
  { alert_type: 'monthly_due_soon', days_until_settlement: 3 },
  { alert_type: 'low_sessions' },
];

describe('directorHomeCards', () => {
  it('splits owed into overdue and waiting; renewal rows are not owed', () => {
    expect(owedCard(alerts)).toEqual({ overdue: 3, waiting: 2, total: 5, intent: 'unpaid' });
    expect(owedCard([{ alert_type: 'unpaid', payment_status: 'pending_report' }]).intent).toBe('pending_report');
    expect(renewalCount(alerts)).toBe(2);
    expect(owedCard([])).toMatchObject({ total: 0 });
  });
  it('oldest evaluation age in days, null without dates', () => {
    expect(oldestEvaluationAge([{ SessionDate: '2026-11-03' }, { session_date: '2026-11-05' }], '2026-11-06')).toBe(3);
    expect(oldestEvaluationAge([{}], '2026-11-06')).toBeNull();
    expect(oldestEvaluationAge([], '2026-11-06')).toBeNull();
  });
  it('buckets lessons by hour and counts unmarked', () => {
    const rows = [
      { start_time: '14:00', status: 'scheduled' },
      { start_time: '14:30', status: 'completed' },
      { start_time: '09:00', status: 'scheduled' },
      { start_time: null, status: 'scheduled' },
    ];
    expect(hourBuckets(rows)).toEqual([{ hour: 9, count: 1, open: 1 }, { hour: 14, count: 2, open: 1 }]);
  });
});
