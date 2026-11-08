import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount } from '@vue/test-utils';
import BillList from '../tuition/BillList.vue';

const __dirname = dirname(fileURLToPath(import.meta.url));
const row = (o) => ({ id: 1, student_id: 1, student_name: '學生甲', subject: '數學', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 1000, ...o });
const ROWS = [
  row({ id: 1, payment_status: 'pending_report', latest_payment_report_id: 9 }),
  row({ id: 2, student_id: 2, student_name: '學生乙' }),
  row({ id: 3, student_id: 3, student_name: '學生丙', payment_status: 'renew_needed', payable_outstanding: 0 }),
  row({ id: 4, student_id: 4, student_name: '學生丁', payment_status: 'amount_review', amount_pending_confirmation: true }),
  row({ id: 5, student_id: 5, student_name: '學生戊', payment_status: 'paid', payable_outstanding: 0 }),
  row({ id: 6, student_id: 6, student_name: '學生己' }),
];
const mountList = (props = {}) => mount(BillList, { props: { alertRows: ROWS, today: '2026-11-05', batchModes: { 1: 'confirm', 2: 'report', 6: 'report' }, ...props }, global: { stubs: { teleport: true } } });

describe('BillList', () => {
  it('匯出 Excel emits exactly the filter, search and rows on screen', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-search"]').setValue('乙');
    await w.get('[data-testid="bill-export"]').trigger('click');
    const [payload] = w.emitted('export')[0];
    expect(w.get('[data-testid="bill-export"]').text()).toBe('匯出 Excel');
    expect(payload).toMatchObject({ filter: 'all', search: '乙' });
    expect(payload.items).toEqual([{ id: 2, package_id: null }]);
  });

  it('opens on 要處理 with counts, hiding paid rows', () => {
    const w = mountList();
    expect(w.get('[data-testid="bill-filter-todo"]').attributes('aria-pressed')).toBe('true');
    expect(w.get('[data-testid="bill-filter-todo"]').text()).toBe('要處理 5');
    expect(w.find('[data-testid="bill-row-c5"]').exists()).toBe(false);
  });

  it('names the primary button after the row kind', () => {
    const w = mountList();
    const label = (k) => w.get(`[data-testid="bill-action-${k}"]`).text();
    expect([label('c1'), label('c2'), label('c3'), label('c4')]).toEqual(['確認入帳', '登記收款', '看帳務', '核對']);
  });

  it('a name search looks through every status', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-search"]').setValue('戊');
    expect(w.find('[data-testid="bill-row-c5"]').exists()).toBe(true);
  });

  it('only lets rows of one kind be picked, and batches that kind', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-pick-c2"]').setValue(true);
    expect(w.get('[data-testid="bill-pick-c1"]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid="bill-pick-c6"]').setValue(true);
    expect(w.get('[data-testid="bill-batch"]').text()).toBe('登記 2 筆');
    await w.get('[data-testid="bill-batch"]').trigger('click');
    expect(w.emitted('batch')[0]).toEqual(['report', [2, 6]]);
  });

  it('emits the clicked row, so the page opens that contract and not the first owing one', async () => {
    const w = mountList({ alertRows: [row({ id: 10 }), row({ id: 11, subject: '英文' })] });
    await w.get('[data-testid="bill-action-c11"]').trigger('click');
    const [b, kind] = w.emitted('action')[0];
    expect([b.class_id, kind]).toEqual([11, 'record']);
    await w.get('[data-testid="bill-name-c10"]').trigger('click');
    expect(w.emitted('student')[0][0].class_id).toBe(10);
  });

  it('shows 退回 on confirm rows and the dangerous items only where the page allows them', () => {
    const w = mountList({ rowActions: { 2: { waive: true } } });
    const items = (key) => w.get(`[data-testid="bill-row-${key}"]`).findComponent({ name: 'ActionMenu' }).props('groups').map((g) => g.items.map((i) => i.label));
    expect(items('c1')[0]).toContain('退回');
    expect(items('c2')[0]).not.toContain('退回');
    expect(items('c2')[1]).toEqual(['改成不收']);
    expect(items('c3')).toHaveLength(1);
  });

  it('gives every primary a working target: 開帳單 only for monthly, 繳費單 or 看帳務 for count renewals', () => {
    const w = mountList({
      canDocs: true,
      alertRows: [
        row({ id: 20, schedule_mode: 'date', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null }),
        row({ id: 21, payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null }),
        row({ id: 22, payment_status: 'renew_needed', payable_outstanding: 0 }),
        row({ id: 23, payment_status: 'amount_review', amount_pending_confirmation: true }),
      ],
    });
    const act = (k) => { const el = w.get(`[data-testid="bill-action-${k}"]`); el.trigger('click'); return [el.text(), w.emitted('action').at(-1)[1]]; };
    expect(act('c20')).toEqual(['開帳單', 'issue']);
    expect(act('c21')).toEqual(['繳費單', 'slip']);
    expect(act('c22')).toEqual(['看帳務', 'open']);
    expect(act('c23')).toEqual(['核對', 'open']);
  });

  it('shows warnings in warning tone and the 需核對金額 amount', () => {
    const w = mountList({ alertRows: [
      row({ id: 1, payment_status: 'pending_report', latest_payment_report_id: 9, newer_course_overlap: true }),
      row({ id: 4, payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 3400 }),
    ] });
    expect(w.get('[data-testid="bill-warn-c1"]').text()).toContain('本期順延與下一期重疊');
    expect(w.get('[data-testid="bill-review-c4"]').text()).toBe('需核對金額 NT$ 3,400');
  });

  it('keeps sorting, defaulting 要開帳單 to fewest lessons first', async () => {
    const w = mountList({ alertRows: [
      row({ id: 1, student_name: '甲', payment_status: 'renew_needed', payable_outstanding: 0, remaining_sessions: 2 }),
      row({ id: 2, student_id: 2, student_name: '乙', payment_status: 'renew_needed', payable_outstanding: 0, remaining_sessions: 1 }),
    ] });
    const order = () => w.findAll('[data-testid^="bill-name-"]').map((e) => e.text());
    await w.get('[data-testid="bill-filter-issue"]').trigger('click');
    expect(w.get('[data-testid="bill-sort"]').element.value).toBe('remaining');
    expect(order()).toEqual(['乙', '甲']);
    await w.get('[data-testid="bill-sort"]').setValue('name');
    expect(order()).toEqual(['乙', '甲'].sort((a, b) => a.localeCompare(b, 'zh-Hant')));
  });

  it('finds a fully paid campus student by name and opens them from one line', async () => {
    const w = mountList({ students: [{ id: 99, name: '學生庚' }] });
    await w.get('[data-testid="bill-search"]').setValue('庚');
    expect(w.get('[data-testid="bill-no-rows"]').text()).toContain('沒有待收款項');
    await w.get('[data-testid="bill-student-99"]').trigger('click');
    expect(w.emitted('student')[0][0]).toMatchObject({ student_id: 99, class_id: null });
  });

  it('clears picks when the chip changes and names the chip in the empty state', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-pick-c2"]').setValue(true);
    expect(w.find('[data-testid="bill-batch"]').exists()).toBe(true);
    await w.get('[data-testid="bill-filter-owing"]').trigger('click');
    expect(w.find('[data-testid="bill-batch"]').exists()).toBe(false);
    const empty = mountList({ alertRows: [] });
    expect(empty.get('[data-testid="bill-empty"]').text()).toContain('要處理');
  });

  it('keeps 未收 and 逾期 visible on a phone (only the period column is hidden)', () => {
    const css = readFileSync(resolve(__dirname, '../tuition/BillList.vue'), 'utf8').match(/@media \(max-width:760px\)\{[\s\S]*$/)[0];
    expect(css).not.toMatch(/\.bl__(due|num)[^{]*\{[^}]*display:none/);
    expect(css).toMatch(/\.bl__period\{display:none\}/);
  });

  it('keeps cached rows and shows a stale-data error with 重試 above them', async () => {
    const w = mountList({ error: '載入失敗（500）' });
    expect(w.find('[data-testid="bill-row-c2"]').exists()).toBe(true);
    expect(w.get('[data-testid="bill-stale"]').text()).toContain('可能不是最新');
    await w.get('[data-testid="bill-stale-retry"]').trigger('click');
    expect(w.emitted('retry')).toHaveLength(1);
  });

  it('never mixes kinds in the batch bar across chip, summary, search and 更多 changes', async () => {
    const w = mountList();
    const bar = () => (w.find('[data-testid="bill-batch"]').exists() ? w.get('[data-testid="bill-batch"]').text() : '');
    await w.get('[data-testid="bill-filter-confirm"]').trigger('click');
    await w.get('[data-testid="bill-pick-c1"]').setValue(true);
    expect(bar()).toBe('確認入帳 1 筆');
    await w.get('[data-testid="bill-filter-owing"]').trigger('click');
    expect(bar()).toBe('');
    await w.get('[data-testid="bill-pick-c2"]').setValue(true);
    await w.get('[data-testid="bill-filter-todo"]').trigger('click');
    expect(bar()).toBe('');
    await w.get('[data-testid="bill-pick-c1"]').setValue(true);
    await w.get('[data-testid="bill-sum-owed"]').trigger('click'); // summary strip
    await w.get('[data-testid="bill-search"]').setValue('學生');  // search
    expect(bar()).toBe('');
    await w.get('[data-testid="bill-pick-c2"]').setValue(true);
    expect(bar()).toBe('登記 1 筆');
  });

  it('shows 之後還會到期 whenever something falls due later, even with nothing owed now', () => {
    const w = mountList({ alertRows: [
      row({ id: 30, payable_outstanding: 700, open_periods: [{ outstanding: 700, due_date: '2026-12-01' }] }),
      row({ id: 31, student_id: 2, payment_status: 'partial', open_periods: [{ outstanding: 200, due_date: '2026-11-01' }, { outstanding: 300, due_date: '2026-12-01' }] }),
    ] });
    expect(w.get('[data-testid="bill-later-c30"]').text()).toBe('之後還會到期 NT$ 700');
    expect(w.get('[data-testid="bill-later-c31"]').text()).toBe('之後還會到期 NT$ 300');
    expect(w.get('[data-testid="bill-row-c30"]').text()).toContain('—');
  });

  it('a 需核對 row shows its amount once, as 需核對金額, not also as 之後還會到期 (D2)', () => {
    const w = mountList({ alertRows: [
      row({ id: 32, payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 800,
        open_periods: [{ outstanding: 800, due_date: '2026-12-01' }] }),
    ] });
    expect(w.get('[data-testid="bill-review-c32"]').text()).toBe('需核對金額 NT$ 800');
    expect(w.find('[data-testid="bill-later-c32"]').exists()).toBe(false);
  });

  it('sorts 未繳金額 by owed now, then owed later, then name', () => {
    const w = mountList({ alertRows: [
      row({ id: 40, student_id: 4, student_name: '丙', payable_outstanding: 500 }),
      row({ id: 41, student_id: 5, student_name: '乙', payable_outstanding: 500, open_periods: [{ outstanding: 500, due_date: '2026-11-01' }, { outstanding: 100, due_date: '2026-12-01' }] }),
      row({ id: 42, student_id: 6, student_name: '甲', payable_outstanding: 500 }),
      row({ id: 43, student_id: 7, student_name: '丁', payable_outstanding: 900 }),
    ] });
    expect(w.get('[data-testid="bill-sort"]').element.value).toBe('amount');
    expect(w.findAll('[data-testid^="bill-name-"]').map((e) => e.text())).toEqual(['丁', '乙', ...['甲', '丙'].sort((a, b) => a.localeCompare(b, 'zh-Hant'))]);
  });

  it('opens the quick view from a row click and from the subject button, but the name still opens the student', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-open-c2"]').trigger('click');
    expect(w.emitted('quick')[0][0].class_id).toBe(2);
    await w.get('[data-testid="bill-row-c2"] .bl__row').trigger('click');
    expect(w.emitted('quick')).toHaveLength(2);
    await w.get('[data-testid="bill-name-c2"]').trigger('click');
    expect(w.emitted('student')).toHaveLength(1);
    expect(w.emitted('quick')).toHaveLength(2);
  });

  it('#4919: a package row with no contract id gets 看帳務, never 開帳單', () => {
    const w = mountList({ alertRows: [row({ id: null, package_id: 7, schedule_mode: 'date', payment_status: 'unpaid', payable_status: 'unbilled', payable_outstanding: null })] });
    const btn = w.get('[data-testid="bill-action-p7"]');
    expect(btn.text()).toBe('看帳務');
    btn.trigger('click');
    expect(w.emitted('action')[0][1]).toBe('open');
  });
});
