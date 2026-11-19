import { describe, expect, it } from 'vitest';
import { billRows } from './billRows.js';
import { guideFacts, guideQueue, nextBill } from './billingGuide.js';

const T = '2026-11-18';
const r = (o) => ({ id: 1, student_id: 1, student_name: '學生甲', subject: '數學', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid', payable_status: 'invoiced', payable_invoice_id: 9, payable_amount: 6000, payable_outstanding: 6000, ...o });

describe('guideQueue', () => {
  it('walks only the rows that need something, 待確認入帳 first, and leaves 已收 out', () => {
    const q = guideQueue(billRows([
      r({ id: 1 }),
      r({ id: 2, payment_status: 'paid', payable_outstanding: 0 }),
      r({ id: 3, payment_status: 'pending_report', latest_payment_report_id: 55 }),
      r({ id: 4, payment_status: 'amount_review', amount_pending_confirmation: true }),
    ], T));
    expect(q.map((b) => [b.key, b.kind])).toEqual([['c3', 'confirm'], ['c1', 'owing'], ['c4', 'review']]);
  });
});

describe('nextBill', () => {
  const queue = [{ key: 'a' }, { key: 'b' }, { key: 'c' }];
  it('never offers a handled or skipped bill again', () => {
    expect(nextBill(queue, { done: new Set(['a']) }).key).toBe('b');
  });
  it('offers 稍後處理 bills after all the others, oldest first', () => {
    expect(nextBill(queue, { later: ['a'] }).key).toBe('b');
    expect(nextBill(queue, { done: new Set(['c']), later: ['b', 'a'] }).key).toBe('b');
  });
  it('returns null when nothing is left', () => {
    expect(nextBill(queue, { done: new Set(['a', 'b', 'c']) })).toBeNull();
  });
});

describe('guideFacts', () => {
  it('keeps the course period and the bill period apart and reads that bill from the ledger', () => {
    const [b] = billRows([r({ schedule_mode: 'date', remaining_sessions: null, course_start_date: '2026-09-01', course_end_date: '2027-01-31', billing_period: '2026-11', billing_period_start: '2026-11-01', billing_period_end: '2026-11-30', due_date: '2026-11-10' })], T);
    const f = guideFacts(b, { total_amount: 6000, calculated_applied_amount: 2000, outstanding_amount: 4000, period_start: '2026-11-01', period_end: '2026-11-30' });
    expect(f).toMatchObject({ mode: '月結制', course_period: '2026-09-01 ~ 2027-01-31', bill_period: '2026-11-01 ~ 2026-11-30', due_date: '2026-11-10', receivable: 6000, received: 2000, outstanding: 4000 });
  });

  it('leaves what the server did not send empty instead of guessing', () => {
    const [b] = billRows([r({ schedule_mode: null, due_date: null })], T);
    expect(guideFacts(b)).toMatchObject({ mode: null, course_period: null, bill_period: null, due_date: null, received: null, receivable: 6000, outstanding: 6000 });
  });

  it('shows only the server estimate for a contract with no bill yet', () => {
    const [b] = billRows([r({ payable_status: 'unbilled', payable_invoice_id: null, payable_amount: null, payable_outstanding: null, estimated_amount: 7200, billing_period_start: null })], T);
    expect(guideFacts(b)).toMatchObject({ billed: false, bill_period: null, receivable: null, outstanding: null, estimate: 7200 });
  });
});
