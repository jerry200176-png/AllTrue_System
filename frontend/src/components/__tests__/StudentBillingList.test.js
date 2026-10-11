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

  it('shows a skeleton, not zeros, while alerts load', () => {
    const w = mount(StudentBillingList, { props: { alertRows: [], students: [{ id: 4, name: '丁' }], loading: true } });
    expect(w.find('[data-testid="sbl-loading"]').exists()).toBe(true);
    expect(w.text()).not.toContain('沒有符合的學生');
    expect(w.find('[data-testid="sbl-summary"]').exists()).toBe(false);
  });

  it('shows an error with retry instead of zeros when alerts failed', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: [], students: [{ id: 4, name: '丁' }], error: '載入失敗（500）' } });
    expect(w.find('[data-testid="sbl-error"]').text()).toContain('載入失敗（500）');
    expect(w.find('[data-testid="sbl-summary"]').exists()).toBe(false);
    expect(w.text()).not.toContain('沒有符合的學生');
    await w.find('[data-testid="sbl-retry"]').trigger('click');
    expect(w.emitted('retry')).toHaveLength(1);
  });

  it('keeps the list when a refresh fails but rows are already loaded', () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, error: '載入失敗（500）', today: '2026-10-07' } });
    expect(w.find('[data-testid="sbl-error"]').exists()).toBe(true);
    expect(w.find('[data-testid="sbl-row-1"]').exists()).toBe(true);
  });

  it('neighbor() follows the current filter and sort (drawer ↑/↓)', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, students: [{ id: 4, name: '丁' }], today: '2026-10-07' } });
    expect(w.vm.neighbor(3, 1).student_id).toBe(1);
    expect(w.vm.neighbor(3, -1)).toBeNull();
    await w.find('[data-testid="sbl-filter-owing"]').trigger('click');
    expect(w.vm.neighbor(1, 1)).toBeNull();
    expect(w.vm.neighbor(99, 1)).toBeNull();
  });

  it('sorts client-side by owed (default), overdue days or name', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, students: [{ id: 4, name: '丁' }], today: '2026-10-07' } });
    const ids = () => w.findAll('[data-testid^="sbl-row-"]').map((r) => r.attributes('data-testid').replace('sbl-row-', ''));
    expect(ids()).toEqual(['3', '1', '2', '4']);
    await w.find('[data-testid="sbl-sort"]').setValue('overdue');
    expect(ids()[0]).toBe('1');
    await w.find('[data-testid="sbl-sort"]').setValue('name');
    expect(ids()).toEqual(['4', '3', '1', '2'].sort((a, b) => ({ 1: '甲', 2: '乙', 3: '丙', 4: '丁' }[a]).localeCompare(({ 1: '甲', 2: '乙', 3: '丙', 4: '丁' }[b]), 'zh-Hant')));
  });

  it('shows a dash instead of 目前沒有未繳, plain-word lozenges, and the all-clear line', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: [], students: [{ id: 4, name: '丁' }], today: '2026-10-07' } });
    const row = w.find('[data-testid="sbl-row-4"]');
    expect(row.text()).not.toContain('目前沒有未繳');
    expect(row.find('.sbl__now strong').text()).toBe('—');
    expect(row.find('[data-testid="at-badge"]').text()).toContain('已收');
    expect(w.find('[data-testid="sbl-clear"]').text()).toBe('本校區目前沒有到期未繳。');
    const w2 = mount(StudentBillingList, { props: { alertRows: ROWS, today: '2026-10-07' } });
    expect(w2.find('[data-testid="sbl-row-2"]').text()).toContain('家長說繳了，等你確認');
    expect(w2.find('[data-testid="sbl-row-1"]').text()).toContain('未繳');
    expect(w2.find('[data-testid="sbl-clear"]').exists()).toBe(false);
  });

  it('explains an empty search result', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, today: '2026-10-07' } });
    await w.find('[data-testid="sbl-search"]').setValue('不存在');
    expect(w.find('[data-testid="sbl-empty"]').text()).toContain('找不到符合「不存在」的學生。請調整搜尋或篩選。');
  });
});
