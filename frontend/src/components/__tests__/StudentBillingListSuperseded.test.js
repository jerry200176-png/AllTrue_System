// in-app #495: a contract with a newer same-subject contract is not the student's lessons-left or renewing signal.
import { describe, it, expect } from 'vitest';
import { buildStudentBillingRows } from '../../lib/studentBillingRows.js';

const base = { student_id: 1, student_name: '甲', subject: '英文', schedule_mode: 'count', paid_amount: 8000 };

describe('buildStudentBillingRows with a superseded contract', () => {
  it('ignores a has_newer_course contract for min_remaining, remaining_hint and renewing', () => {
    const [row] = buildStudentBillingRows([
      { ...base, id: 11, payment_status: 'renew_needed', remaining_sessions: 0, has_newer_course: true },
      { ...base, id: 12, payment_status: 'paid', remaining_sessions: 5, has_newer_course: false },
    ], [], '2026-11-04');
    expect(row.min_remaining).toBe(5);
    expect(row.remaining_hint).toBe('英文 剩 5 堂');
    expect(row.renewing).toBe(0);
    expect(row.contracts.map((c) => c.id)).toEqual([11, 12]);
  });
});
