// Billing redesign PRD v2 D1/D3/D8/D12: one row per student, owed-by-today first.
import { describe, it, expect } from 'vitest';
import { buildStudentBillingRows, rowOwedTotal, rowPrimaryAction, STUDENT_FILTERS } from './studentBillingRows.js';

const ROWS = [
  { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' },
  { id: 12, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 2000, due_date: '2026-10-20' },
  { id: 21, student_id: 2, student_name: '乙', payment_status: 'pending_report', payable_outstanding: 1800, due_date: null, last_paid_at: '2026-09-03' },
  { id: 31, student_id: 3, student_name: '丙', payment_status: 'partial', payable_outstanding: 5400, due_date: null },
];

describe('buildStudentBillingRows', () => {
  it('splits due-by-today from later, counts overdue days, includes paid students', () => {
    const rows = buildStudentBillingRows(ROWS, [{ id: 4, name: '丁' }], '2026-10-07');
    expect(rows.map((r) => r.student_id)).toEqual([3, 1, 2, 4]);
    const a = rows.find((r) => r.student_id === 1);
    expect([a.owed_now, a.owed_later, a.overdue_days, a.open_contracts]).toEqual([3000, 2000, 6, 2]);
    const b = rows.find((r) => r.student_id === 2);
    expect([b.owed_now, b.waiting_confirm, b.last_paid_at]).toEqual([0, 1, '2026-09-03']);
    expect(rows.find((r) => r.student_id === 4).owed_now).toBe(0);
  });
});

describe('需續課 chip (old 續課/將到期 tab)', () => {
  it('lists students with a course to renew, and only them', () => {
    const rows = buildStudentBillingRows([
      { id: 41, student_id: 5, student_name: '戊', payment_status: 'renew_needed', remaining_sessions: 1 },
      { id: 51, student_id: 6, student_name: '己', payment_status: 'monthly_due_soon' },
      { id: 61, student_id: 7, student_name: '庚', payment_status: 'paid' },
    ], [], '2026-10-07');
    expect(rows.filter(STUDENT_FILTERS.renew.test).map((r) => r.student_id).sort()).toEqual([5, 6]);
    expect(STUDENT_FILTERS.renew.label).toBe('需續課');
  });
});

describe('buildStudentBillingRows with open_periods (same bills as the student panel)', () => {
  it('sums every open bill of a contract and splits each by its own due date', () => {
    const rows = buildStudentBillingRows([
      { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01',
        open_periods: [{ billing_period: '2026-09', outstanding: 3000, due_date: '2026-10-01' }, { billing_period: '2026-10', outstanding: 5000, due_date: '2026-10-20' }, { billing_period: '2026-08', outstanding: 1000, due_date: null }] },
    ], [], '2026-10-07');
    const a = rows[0];
    expect([a.owed_now, a.owed_later, a.overdue_days, a.open_contracts]).toEqual([4000, 5000, 6, 1]);
    expect(rowOwedTotal({ open_periods: [{ outstanding: 3000 }, { outstanding: 5000 }], payable_outstanding: 3000 })).toBe(8000);
    expect(rowOwedTotal({ payable_outstanding: 3000 })).toBe(3000);
  });

  it('counts contract owed only for owing statuses, so the expansion sums to the student total', () => {
    const [a] = buildStudentBillingRows([
      { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' },
      { id: 12, student_id: 1, student_name: '甲', payment_status: 'pending_report', payable_outstanding: 900, due_date: '2026-10-01' },
    ], [], '2026-10-07');
    expect(a.contracts.map((c) => c.owed)).toEqual([3000, 0]);
    expect(a.contracts.reduce((s, c) => s + c.owed, 0)).toBe(a.owed_now + a.owed_later);
  });
});

describe('unbilled contracts and 金額待確認 rows', () => {
  it('keeps students with an unbilled contract out of the 沒有未繳 filter (in-app #407)', () => {
    const rows = buildStudentBillingRows([
      { id: 51, student_id: 5, student_name: '戊', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null },
    ], [{ id: 7, name: '庚' }], '2026-10-07');
    expect(rows.filter(STUDENT_FILTERS.clear.test).map((r) => r.student_id)).toEqual([7]);
  });
});

describe('金額待確認 rows (Founder D2)', () => {
  const REVIEW_ROWS = [
    { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' },
    { id: 41, student_id: 5, student_name: '戊', payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 3400,
      payable_outstanding: 3400, open_periods: [{ billing_period: '2026-09', outstanding: 3400, due_date: null }] },
  ];

  it('keeps the pending amount on its own line, out of owed_now / owed_later', () => {
    const rows = buildStudentBillingRows(REVIEW_ROWS, [], '2026-10-07');
    const e = rows.find((r) => r.student_id === 5);
    expect([e.owed_now, e.owed_later, e.open_contracts]).toEqual([0, 0, 0]);
    expect([e.pending_review_amount, e.pending_review_count]).toEqual([3400, 1]);
    expect(e.contracts).toEqual([expect.objectContaining({ id: 41, owed: 0, pending_review: 3400 })]);
  });

  it('counts a pending report on a 金額待確認 bill as waiting to confirm, not as owed', () => {
    const [r] = buildStudentBillingRows([
      { id: 51, student_id: 6, student_name: '己', payment_status: 'pending_report', amount_pending_confirmation: true, pending_confirmation_amount: 4400,
        payable_outstanding: 4400, due_date: '2026-09-01' },
    ], [], '2026-10-07');
    expect([r.owed_now, r.owed_later, r.overdue_days, r.waiting_confirm]).toEqual([0, 0, 0, 1]);
    expect([r.pending_review_amount, r.pending_review_count]).toEqual([4400, 1]);
    expect(r.contracts[0]).toMatchObject({ owed: 0, pending_review: 4400 });
  });
});
