import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import BillList from '../tuition/BillList.vue';

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
  it('opens on 要處理 with counts, hiding paid rows', () => {
    const w = mountList();
    expect(w.get('[data-testid="bill-filter-todo"]').attributes('aria-pressed')).toBe('true');
    expect(w.get('[data-testid="bill-filter-todo"]').text()).toBe('要處理 5');
    expect(w.find('[data-testid="bill-row-c5"]').exists()).toBe(false);
  });

  it('names the primary button after the row kind', () => {
    const w = mountList();
    const label = (k) => w.get(`[data-testid="bill-action-${k}"]`).text();
    expect([label('c1'), label('c2'), label('c3'), label('c4')]).toEqual(['確認入帳', '登記收款', '開帳單', '核對']);
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
    expect([b.class_id, kind]).toEqual([11, 'owing']);
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
});
