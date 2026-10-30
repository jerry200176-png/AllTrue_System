// Billing redesign PRD v2 D1/D3/D8/D12: one row per student, owed-by-today first.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StudentBillingList from '../tuition/StudentBillingList.vue';
import { buildStudentBillingRows, rowOwedTotal, STUDENT_FILTERS } from '../../lib/studentBillingRows.js';

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
    expect(row.find('[data-testid="at-badge"]').text()).toBe('沒有待收款項');
    expect(w.find('[data-testid="sbl-clear"]').text()).toBe('本校區沒有到期未繳。');
    const w2 = mount(StudentBillingList, { props: { alertRows: ROWS, today: '2026-10-07' } });
    expect(w2.find('[data-testid="sbl-row-2"]').text()).toContain('待確認入帳');
    expect(w2.find('[data-testid="sbl-row-1"]').text()).toContain('未繳');
    expect(w2.find('[data-testid="sbl-clear"]').exists()).toBe(false);
  });

  it('explains an empty search result', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: ROWS, today: '2026-10-07' } });
    await w.find('[data-testid="sbl-search"]').setValue('不存在');
    expect(w.find('[data-testid="sbl-empty"]').text()).toContain('找不到符合「不存在」的學生。請調整搜尋或篩選。');
  });
});

describe('StudentBillingList contract expansion (in-app #396 #404)', () => {
  const rows = [
    { id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, paid_amount: 500, due_date: '2026-10-01', course_start_date: '2026-09-01', course_end_date: '2026-12-31' },
    { id: 12, student_id: 1, student_name: '甲', payment_status: 'paid', payable_outstanding: 0, paid_amount: 2000, course_start_date: '2026-01-05', course_end_date: null },
  ];

  it('is collapsed by default and lists each contract ref and period when expanded', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: rows, today: '2026-10-07' } });
    const btn = w.find('[data-testid="sbl-expand-1"]');
    expect(btn.attributes('aria-expanded')).toBe('false');
    expect(w.find('[data-testid="sbl-contracts-1"]').exists()).toBe(false);
    await btn.trigger('click');
    expect(btn.attributes('aria-expanded')).toBe('true');
    const list = w.find('[data-testid="sbl-contracts-1"]');
    expect(list.text()).toContain('課程 000011');
    expect(list.text()).toContain('2026-09-01 ~ 2026-12-31');
    expect(list.text()).toContain('NT$ 3,000');
    expect(list.text()).toContain('課程 000012');
    expect(list.text()).toContain('2026-01-05 ~ —');
    await btn.trigger('click');
    expect(w.find('[data-testid="sbl-contracts-1"]').exists()).toBe(false);
  });

  it('says 已收 only with a payment on record and flags owing contracts with no bill (in-app #407)', () => {
    const w = mount(StudentBillingList, { props: { alertRows: [
      { id: 51, student_id: 5, student_name: '戊', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null },
      { id: 61, student_id: 6, student_name: '己', payment_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      // paid monthly package: hard-coded unbilled payable, but paid
      { id: null, student_id: 8, student_name: '辛', payment_status: 'monthly_due_soon', payable_status: 'unbilled', payable_outstanding: null, paid: true, last_paid_at: '2026-10-01' },
      // course paid by flag only (G-009), no payment date
      // the server marks it (legacy_paid_flag_only), so it reads 需核對, never 已收 (#4114, Founder Q3)
      { id: 91, student_id: 9, student_name: '壬', payment_status: 'paid', payable_outstanding: 0, last_paid_at: null, legacy_paid_flag_only: true },
    ], students: [{ id: 7, name: '庚' }], today: '2026-10-07' } });
    const badge = (id) => w.find(`[data-testid="sbl-row-${id}"]`).find('[data-testid="at-badge"]').text();
    expect(badge(5)).toBe('還沒開帳單');
    expect(badge(6)).toBe('已收');
    expect(badge(7)).toBe('沒有待收款項');
    expect(badge(8)).toBe('已收');
    expect(badge(9)).toBe('需核對（舊資料標已收，查無收款）');
  });

  it('keeps students with an unbilled contract out of the 沒有未繳 filter (in-app #407)', () => {
    const rows = buildStudentBillingRows([
      { id: 51, student_id: 5, student_name: '戊', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null },
    ], [{ id: 7, name: '庚' }], '2026-10-07');
    expect(rows.filter(STUDENT_FILTERS.clear.test).map((r) => r.student_id)).toEqual([7]);
  });

  it('never treats a stale payment date alone as 已收 (#4123)', () => {
    const alertRows = [
      // unpaid, no bill yet, but an old payment date (legacy PayDate / earlier term)
      { id: 101, student_id: 10, student_name: '癸', paid: false, payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: '2025-01-15' },
      // raw paid flag but the server's verdict still says unpaid: not 已收 either
      { id: 111, student_id: 11, student_name: '子', paid: true, payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: '2025-02-01' },
      // truly paid contract
      { id: 121, student_id: 12, student_name: '丑', paid: true, payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      // paid monthly plan (server hard-codes unbilled payable for monthly packages)
      { id: null, student_id: 13, student_name: '寅', paid: true, payment_status: 'monthly_due_soon', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: '2026-10-01' },
      // valid paid status, no pay date
      // paid by the legacy flag alone, no Payment on record (#4114): not owed, so still in 沒有未繳
      { id: 141, student_id: 14, student_name: '卯', paid: true, payment_status: 'paid', payable_outstanding: 0, last_paid_at: null, legacy_paid_flag_only: true },
    ];
    const rows = buildStudentBillingRows(alertRows, [], '2026-10-07');
    expect(rows.filter(STUDENT_FILTERS.clear.test).map((r) => r.student_id).sort()).toEqual([12, 13, 14]);
    const w = mount(StudentBillingList, { props: { alertRows, today: '2026-10-07' } });
    const badge = (id) => w.find(`[data-testid="sbl-row-${id}"]`).find('[data-testid="at-badge"]').text();
    expect(badge(10)).toBe('還沒開帳單');
    expect(badge(11)).toBe('還沒開帳單');
    expect(badge(12)).toBe('已收');
    expect(badge(13)).toBe('已收');
    expect(badge(14)).toBe('需核對（舊資料標已收，查無收款）');
  });

  // #4114 (Founder Q3): flag-only is neutral 需核對 (amber): never green 已收, never 未繳, never owed.
  it('shows 需核對 for a contract paid by the legacy flag alone, even next to a real payment', () => {
    const alertRows = [
      { id: 151, student_id: 15, student_name: '辰', payment_status: 'renew_needed', payable_outstanding: 0, outstanding: 0, legacy_paid_flag_only: true },
      { id: 161, student_id: 16, student_name: '巳', payment_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03', legacy_paid_flag_only: false },
      { id: 162, student_id: 16, student_name: '巳', payment_status: 'paid', payable_outstanding: 0, legacy_paid_flag_only: true },
    ];
    const rows = buildStudentBillingRows(alertRows, [], '2026-10-07');
    const r15 = rows.find((r) => r.student_id === 15);
    expect([r15.has_paid, r15.legacy_flag_only, r15.owed_now, r15.open_contracts]).toEqual([false, 1, 0, 0]);
    expect(rows.filter(STUDENT_FILTERS.owing.test)).toEqual([]);
    const w = mount(StudentBillingList, { props: { alertRows, today: '2026-10-07' } });
    for (const id of [15, 16]) {
      const b = w.find(`[data-testid="sbl-row-${id}"]`).findAll('[data-testid="at-badge"]');
      expect(b.map((x) => x.text())).toEqual(['需核對（舊資料標已收，查無收款）']);
      expect(b[0].classes()).toContain('at-badge--warning');
    }
  });
});

// #4114 / #4123: 已收 needs the server's paid verdict; none of these cases may read 已收 (or 已收清).
describe('StudentBillingList 已收 regressions (#4114)', () => {
  const badges = (w, id) => w.find(`[data-testid="sbl-row-${id}"]`).findAll('[data-testid="at-badge"]').map((b) => b.text());
  const mountRows = (alertRows, students = []) => mount(StudentBillingList, { props: { alertRows, students, today: '2026-10-07' } });
  const neverPaid = (w, id) => badges(w, id).forEach((t) => expect(t).not.toContain('已收'));

  it('no invoice and no Payment is not 已收', () => {
    const w = mountRows([
      { id: 201, student_id: 20, student_name: '甲一', paid: false, payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: null },
    ], [{ id: 21, name: '甲二' }]);
    expect(badges(w, 20)).toEqual(['還沒開帳單']);
    expect(badges(w, 21)).toEqual(['沒有待收款項']);
  });

  it('a zero amount due alone is not 已收', () => {
    const w = mountRows([
      { id: 221, student_id: 22, student_name: '甲三', payment_status: 'unpaid', payable_status: 'review_required', payable_outstanding: 0, last_paid_at: null },
    ]);
    expect(w.find('[data-testid="sbl-row-22"]').find('.sbl__now strong').text()).toBe('—');
    neverPaid(w, 22);
  });

  it('a pending payment report is not 已收', () => {
    const w = mountRows([
      { id: 231, student_id: 23, student_name: '甲四', paid: false, payment_status: 'pending_report', payable_status: 'unbilled', payable_outstanding: null, last_paid_at: null },
    ]);
    expect(badges(w, 23)).toEqual(['待確認入帳']);
    neverPaid(w, 23);
  });

  it('a voided report is not 已收', () => {
    // server state after a report void: Paid=0, invoice back to unpaid, the reversed receipt gives no date
    const w = mountRows([
      { id: 241, student_id: 24, student_name: '甲五', paid: false, payment_status: 'unpaid', payable_status: 'unpaid', payable_outstanding: 5000, paid_amount: 0, due_date: '2026-10-01', last_paid_at: null },
    ]);
    expect(badges(w, 24)).toEqual(['未繳']);
    expect(w.find('[data-testid="sbl-row-24"]').text()).not.toContain('最近繳費');
  });

  it('a stale PayDate with no Payment is not 已收', () => {
    const w = mountRows([
      { id: 251, student_id: 25, student_name: '甲六', paid: false, payment_status: 'unpaid', payable_status: 'review_required', payable_outstanding: null, last_paid_at: '2025-01-15' },
    ]);
    neverPaid(w, 25);
    expect(buildStudentBillingRows(w.props('alertRows'), [], '2026-10-07')[0].has_paid).toBe(false);
  });

  it('a partial payment is not 已收清', () => {
    const w = mountRows([
      { id: 261, student_id: 26, student_name: '甲七', payment_status: 'partial', payable_status: 'partial', payable_outstanding: 3000, paid_amount: 2000, due_date: '2026-10-01', last_paid_at: '2026-09-20' },
      { id: 271, student_id: 27, student_name: '甲八', payment_status: 'partial', payable_status: 'partial', payable_outstanding: 3000, paid_amount: 2000, due_date: '2026-11-01', last_paid_at: '2026-09-20' },
    ]);
    expect(badges(w, 26)).toEqual(['未繳']);
    expect(badges(w, 27)).toEqual(['還沒到期']);
    neverPaid(w, 26);
    neverPaid(w, 27);
    expect(w.find('[data-testid="sbl-row-26"]').text()).toContain('最近繳費');
  });

  it('mixed contracts: a paid contract does not make the student 已收 while another contract has money due', () => {
    const w = mountRows([
      { id: 281, student_id: 28, student_name: '甲九', payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      { id: 282, student_id: 28, student_name: '甲九', payment_status: 'unpaid', payable_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' },
      { id: 291, student_id: 29, student_name: '乙一', payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      { id: 292, student_id: 29, student_name: '乙一', payment_status: 'unpaid', payable_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-11-01' },
      { id: 301, student_id: 30, student_name: '乙二', payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      { id: 302, student_id: 30, student_name: '乙二', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null },
    ]);
    expect(badges(w, 28)).toEqual(['未繳']);
    expect(badges(w, 29)).toEqual(['還沒到期']);
    expect(badges(w, 30)).toEqual(['還沒開帳單']);
  });

  // TODO(#4114): a paid contract plus a contract whose amount is still unknown (review_required, no amount, not
  // unbilled) still reads 已收 for the whole student (`has_paid` is any-contract). Needs a per-contract decision.
  it.skip('mixed contracts: a paid contract plus an unsettled contract with no amount is not 已收 (#4114)', () => {
    const w = mountRows([
      { id: 311, student_id: 31, student_name: '乙三', payment_status: 'paid', payable_status: 'paid', payable_outstanding: 0, last_paid_at: '2026-10-03' },
      { id: 312, student_id: 31, student_name: '乙三', payment_status: 'unpaid', payable_status: 'review_required', payable_outstanding: null },
    ]);
    neverPaid(w, 31);
  });
});

// Founder D2 (in-app #394): 金額待確認 is findable but never part of 未收 / owed totals.
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

  it('shows 金額待確認 separately from the campus 未收 total and never as 已收 / 已繳清', async () => {
    const w = mount(StudentBillingList, { props: { alertRows: REVIEW_ROWS, today: '2026-10-07' } });
    const summary = w.find('[data-testid="sbl-summary"]').text();
    expect(summary).toContain('本校區到今天未繳 NT$ 3,000');
    expect(summary).toContain('未繳 1 位');
    expect(w.find('[data-testid="sbl-pending-review"]').text()).toContain('NT$ 3,400（1 筆，不在未繳內）');
    const row = w.find('[data-testid="sbl-row-5"]').text();
    expect(row).toContain('需核對金額');
    expect(row).not.toContain('已收');
    await w.find('[data-testid="sbl-filter-clear"]').trigger('click');
    expect(w.find('[data-testid="sbl-row-5"]').exists()).toBe(false);
    await w.find('[data-testid="sbl-filter-pending_review"]').trigger('click');
    expect(w.findAll('[data-testid^="sbl-row-"]').map((r) => r.attributes('data-testid'))).toEqual(['sbl-row-5']);
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
