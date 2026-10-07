// Billing redesign PRD v2 D1/D3/D8/D12: one row per student, owed-by-today first.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StudentBillingList from '../tuition/StudentBillingList.vue';
import { buildStudentBillingRows } from '../../lib/studentBillingRows.js';

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

describe('StudentBillingList', () => {
  it('shows totals, filters, searches and opens a student', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, students: [{ id: 4, name: '丁' }], today: '2026-10-07' } });
    expect(w.find('[data-testid="sbl-summary"]').text()).toContain('NT$ 8,400');
    expect(w.find('[data-testid="sbl-summary"]').text()).toContain('2 位');
    expect(w.find('[data-testid="sbl-row-1"]').text()).toContain('逾期 6 天');
    expect(w.find('[data-testid="sbl-row-1"]').text()).toContain('之後還會到期 NT$ 2,000');

    await w.find('[data-testid="sbl-filter-confirm"]').trigger('click');
    expect(w.findAll('[data-testid^="sbl-row-"]').map((r) => r.attributes('data-testid'))).toEqual(['sbl-row-2']);
    await w.find('[data-testid="sbl-filter-clear"]').trigger('click');
    expect(w.findAll('[data-testid^="sbl-row-"]').map((r) => r.attributes('data-testid'))).toEqual(['sbl-row-4']);

    await w.find('[data-testid="sbl-filter-all"]').trigger('click');
    await w.find('[data-testid="sbl-search"]').setValue('丙');
    await w.find('[data-testid="sbl-row-3"]').trigger('click');
    expect(w.emitted('open')[0][0]).toMatchObject({ student_id: 3, first_class_id: 31 });
  });
});
