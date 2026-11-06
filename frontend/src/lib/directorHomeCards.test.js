import { describe, it, expect } from 'vitest';
import { owedCard, renewalCount, oldestEvaluationAge } from './directorHomeCards.js';

const alerts = [
  { alert_type: 'unpaid', payment_status: 'unpaid' },
  { alert_type: 'unpaid', payment_status: 'partial' },
  { alert_type: 'unpaid', payment_status: 'pending_report' },
  { alert_type: 'unpaid', payment_status: 'amount_review' },
  { alert_type: 'monthly_due_soon', payment_status: 'unpaid', days_until_settlement: 0 },
  { alert_type: 'monthly_due_soon', payment_status: 'paid', days_until_settlement: 3 },
  { alert_type: 'low_sessions', payment_status: 'paid' },
];

describe('directorHomeCards', () => {
  it('owed uses payment_status: unpaid monthly is owed, paid renewals are not', () => {
    expect(owedCard(alerts)).toEqual({ overdue: 3, waiting: 2, total: 5, intent: 'unpaid' });
    expect(renewalCount(alerts)).toBe(2);
    expect(owedCard([])).toMatchObject({ total: 0 });
  });
  it('routes to the filter of the biggest group, amount_review stays distinct', () => {
    expect(owedCard([{ alert_type: 'unpaid', payment_status: 'amount_review' }]).intent).toBe('amount_review');
    expect(owedCard([{ alert_type: 'unpaid', payment_status: 'pending_report' }]).intent).toBe('pending_report');
  });
  it('oldest evaluation age in days, null without dates', () => {
    expect(oldestEvaluationAge([{ SessionDate: '2026-11-03' }, { session_date: '2026-11-05' }], '2026-11-06')).toBe(3);
    expect(oldestEvaluationAge([{}], '2026-11-06')).toBeNull();
    expect(oldestEvaluationAge([], '2026-11-06')).toBeNull();
  });
});

import { plainTaskTitle } from './directorHomeCards.js';
describe('plain task rows', () => {
  it('maps ids to plain titles and keeps unknown titles', () => {
    expect(plainTaskTitle({ id: 'parent-leave', title: '家長請假待主任處理' })).toBe('家長請假');
    expect(plainTaskTitle({ id: 'adoption-1', title: '營運待辦' })).toBe('營運待辦');
  });
});

import { sevenDayEvalCounts } from './directorHomeCards.js';
describe('sevenDayEvalCounts', () => {
  it('buckets by lesson day, oldest first, null when empty/out of range', () => {
    expect(sevenDayEvalCounts([{ SessionDate: '2026-11-06' }, { SessionDate: '2026-11-06' }, { session_date: '2026-11-00' }, { SessionDate: '2026-10-31' }], '2026-11-06')).toEqual([1, 0, 0, 0, 0, 0, 2]);
    expect(sevenDayEvalCounts([{ SessionDate: '2026-10-01' }], '2026-11-06')).toBeNull();
    expect(sevenDayEvalCounts([], '2026-11-06')).toBeNull();
  });
});
