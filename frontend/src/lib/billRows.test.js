import { describe, expect, it } from 'vitest';
import { billCounts, billRows } from './billRows.js';

const T = '2026-11-05';
const r = (o) => ({ id: 1, student_id: 1, student_name: 'S', subject: '數學', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 1000, ...o });
const kinds = (rows) => Object.fromEntries(billRows(rows, T).map((b) => [b.key, b.kind]));

describe('billRows', () => {
  it('keys a monthly package row with a null id on package_id and never drops a row', () => {
    const out = billRows([r({ id: null, package_id: 7, schedule_mode: 'date', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null }), r({ id: null, package_id: null })], T);
    expect(out).toHaveLength(2);
    expect(out.map((b) => b.key).sort()).toEqual(['i1', 'p7']);
    expect(out.find((b) => b.key === 'p7')).toMatchObject({ kind: 'issue', class_id: null, package_id: 7 });
  });

  it('puts amount_review in review, with no owed amount', () => {
    const [b] = billRows([r({ payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 900 })], T);
    expect(b).toMatchObject({ kind: 'review', owed: 0, label: '需核對' });
  });

  it('takes count-mode overdue days from open_periods due dates', () => {
    const [b] = billRows([r({ due_date: null, open_periods: [{ outstanding: 500, due_date: '2026-11-01' }, { outstanding: 500, due_date: '2026-12-01' }] })], T);
    expect(b).toMatchObject({ kind: 'owing', owed: 500, owed_later: 500, overdue_days: 4, period: '剩 4 堂' });
  });

  it('treats a partial payment as owing', () => {
    const [b] = billRows([r({ payment_status: 'partial', payable_outstanding: 300, paid_amount: 700 })], T);
    expect(b).toMatchObject({ kind: 'owing', owed: 300, label: '繳了一部分', hint: 'NT$ 700'.replace('NT$ ', '已收 NT$ ') });
  });

  it('lets a waiting parent report win over owing on the same contract', () => {
    expect(kinds([r({ payment_status: 'pending_report', latest_payment_report_id: 5 })])).toEqual({ c1: 'confirm' });
    expect(kinds([r({ payment_status: 'unpaid', latest_payment_report_id: 5 })])).toEqual({ c1: 'confirm' });
  });

  it('uses the existing renew status for issue, and counts every kind', () => {
    const rows = billRows([
      r({ id: 1, payment_status: 'pending_report', latest_payment_report_id: 5 }),
      r({ id: 2 }), r({ id: 3, payment_status: 'partial' }),
      r({ id: 4, payment_status: 'renew_needed', payable_outstanding: 0 }),
      r({ id: 5, payment_status: 'amount_review', amount_pending_confirmation: true }),
      r({ id: 6, payment_status: 'paid', payable_outstanding: 0 }),
      r({ id: 7, payment_status: 'waived', payable_outstanding: 0 }),
    ], T);
    expect(billCounts(rows)).toEqual({ confirm: 1, owing: 2, issue: 1, review: 1, paid: 1, waived: 1, todo: 5 });
    expect(rows.map((b) => b.kind)).toEqual(['confirm', 'owing', 'owing', 'issue', 'review', 'paid', 'waived']);
  });

  it('always carries the old warning notes, even on a confirm row, and never replaces them with the hint', () => {
    const [b] = billRows([r({
      payment_status: 'pending_report', latest_payment_report_id: 5,
      newer_course_overlap: true, current_course_end_date: '2026-12-31', newer_course_start_date: '2026-12-01',
      invoice_amount_discrepancy: true, invoice_period_sessions: 8, invoice_stored_amount: 9000, invoice_computed_amount: 8800,
    })], T);
    expect(b.kind).toBe('confirm');
    expect(b.hint).toBe('家長已回報');
    expect(b.warnings).toHaveLength(2);
    expect(b.warnings[0]).toMatch(/^數學：本期順延與下一期重疊，請調整/);
    expect(b.warnings[1]).toBe('數學：依實際 8 堂計算（原始帳單 NT$ 9,000；實際計算 NT$ 8,800）');
  });

  it('keeps the 需核對金額 amount on review rows and uses no retired wording', () => {
    const [b] = billRows([r({ payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 3400 })], T);
    expect(b.review_amount).toBe(3400);
    const all = billRows([r({ payment_status: 'pending_report', latest_payment_report_id: 5 })], T).map((x) => `${x.hint}${x.label}`).join();
    expect(all).not.toMatch(/等你確認|已回報付款|待核對入帳/);
  });

  it('gives the lessons-left sort signal only to contracts not renewed by a newer one', () => {
    const [a, b] = billRows([r({ id: 1, remaining_sessions: 2 }), r({ id: 2, remaining_sessions: 1, renewed_by_newer_course: true })], T);
    expect([a.sort_remaining, b.sort_remaining].sort()).toEqual([2, null]);
  });
});

// Ported from the retired student-list tests (#4114): nothing without a real payment on record reads 已收.
describe('billRows 已收 regressions (#4114)', () => {
  const kindOf = (o) => billRows([r(o)], T)[0].kind;
  it.each([
    ['no invoice and no payment', { payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: null }, 'issue'],
    ['a zero amount due alone', { payment_status: 'unpaid', payable_status: 'review_required', payable_outstanding: 0, last_paid_at: null }, 'review'],
    ['a pending payment report', { payment_status: 'pending_report', payable_status: 'unbilled', payable_outstanding: null }, 'confirm'],
    ['a voided report (invoice back to unpaid)', { payment_status: 'unpaid', payable_status: 'unpaid', payable_outstanding: 5000, paid_amount: 0, due_date: '2026-10-01' }, 'owing'],
    ['a stale PayDate with no payment', { payment_status: 'unpaid', payable_status: 'review_required', payable_outstanding: null, last_paid_at: '2025-01-15' }, 'review'],
    ['a partial payment', { payment_status: 'partial', payable_status: 'partial', payable_outstanding: 3000, paid_amount: 2000, due_date: '2026-10-01' }, 'owing'],
    ['a legacy paid flag alone', { payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, legacy_paid_flag_only: true }, 'review'],
  ])('%s is not 已收', (_n, o, kind) => {
    expect(kindOf(o)).toBe(kind);
  });
  it('a paid contract next to an owing one stays its own row', () => {
    expect(kinds([r({ id: 281, payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0 }), r({ id: 282, payment_status: 'unpaid', due_date: '2026-10-01' })])).toEqual({ c281: 'paid', c282: 'owing' });
  });
});
