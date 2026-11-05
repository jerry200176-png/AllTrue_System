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
});
