import { describe, it, expect } from 'vitest';
import { owedCard, renewalCount, oldestEvaluationAge } from './directorHomeCards.js';

// Raw /alerts/tuition rows, classified by billRows() exactly as the 帳務中心 list does.
const alerts = [
  { id: 1, alert_type: 'unpaid', payment_status: 'unpaid', payable_outstanding: 3000 },
  { id: 2, alert_type: 'unpaid', payment_status: 'partial', payable_outstanding: 1000 },
  { id: 3, alert_type: 'unpaid', payment_status: 'pending_report', latest_payment_report_id: 9 },
  { id: 4, alert_type: 'unpaid', payment_status: 'amount_review' },
  { id: 5, alert_type: 'monthly_due_soon', payment_status: 'unpaid', payable_outstanding: 6000, days_until_settlement: 2 },
  { id: 6, alert_type: 'monthly_due_soon', payment_status: 'monthly_due_soon', payable_outstanding: 0 },
  { id: 7, alert_type: 'low_sessions', payment_status: 'renew_needed', payable_outstanding: 0 },
];

describe('directorHomeCards', () => {
  it('counts the same kinds as the 帳務中心 list: owing = 未繳, confirm + review = 待確認', () => {
    expect(owedCard(alerts)).toEqual({ unpaid: 3, waiting: 2, total: 5, intent: 'unpaid' });
    expect(renewalCount(alerts)).toBe(2);
    expect(owedCard([])).toMatchObject({ total: 0 });
  });
  it('a contract already renewed by a newer course is not counted as 快上完', () => {
    expect(renewalCount([...alerts, { id: 8, alert_type: 'low_sessions', payment_status: 'renew_needed', renewed_by_newer_course: true }])).toBe(2);
  });
  it('invoice-settled contract with a stale legacy flag (alert_type unpaid, renew_needed) counts as 快上完', () => {
    expect(renewalCount([{ id: 9, alert_type: 'unpaid', payment_status: 'renew_needed', payable_outstanding: 0 }])).toBe(1);
    expect(owedCard([{ id: 9, alert_type: 'unpaid', payment_status: 'renew_needed', payable_outstanding: 0 }]).total).toBe(0);
  });
  it('legacy paid flag without evidence goes to 待確認 (review), not 快上完, matching its destination', () => {
    const r = { id: 10, alert_type: 'low_sessions', payment_status: 'renew_needed', legacy_paid_flag_only: true };
    expect(renewalCount([r])).toBe(0);
    expect(owedCard([r])).toMatchObject({ waiting: 1, intent: 'amount_review' });
  });
  it('routes to the filter of the biggest group, amount_review stays distinct', () => {
    expect(owedCard([{ id: 4, payment_status: 'amount_review' }]).intent).toBe('amount_review');
    expect(owedCard([{ id: 3, payment_status: 'pending_report' }]).intent).toBe('pending_report');
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
