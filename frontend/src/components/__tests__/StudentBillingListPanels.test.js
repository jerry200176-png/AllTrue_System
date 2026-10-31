// Billing UX F-4: 要開帳單 and 月結要核對 are chips of the student list. Their panels render inside it (slot) in place of
// the rows, and the rest of the list (rows, search, sort) is only shown for the 學生 view.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StudentBillingList from '../tuition/StudentBillingList.vue';

const ALERTS = [{ id: 11, student_id: 1, student_name: '甲', payment_status: 'unpaid', payable_outstanding: 3000, due_date: '2026-10-01' }];
const PANELS = [{ key: 'monthly-drafts', label: '要開帳單' }, { key: 'monthly-review', label: '月結要核對' }];
const slots = { 'monthly-drafts': '<div data-testid="drafts-slot" />', 'monthly-review': '<div data-testid="review-slot" />' };
const make = (view = 'students', extra = {}) => mount(StudentBillingList, { props: { alertRows: ALERTS, today: '2026-10-07', view, panels: PANELS, ...extra }, slots });

describe('student list panel chips', () => {
  it('students view: rows, search and sort show, no panel; chips are offered', () => {
    const w = make();
    expect(w.find('[data-testid="sbl-row-1"]').exists()).toBe(true);
    expect(w.find('[data-testid="drafts-slot"]').exists()).toBe(false);
    expect(w.get('[data-testid="sbl-panel-monthly-drafts"]').text()).toBe('要開帳單');
    expect(w.get('[data-testid="sbl-panel-monthly-review"]').text()).toBe('月結要核對');
  });

  it('a panel chip asks the page to switch, and the panel replaces the rows', async () => {
    const w = make();
    await w.get('[data-testid="sbl-panel-monthly-review"]').trigger('click');
    expect(w.emitted('view')[0]).toEqual(['monthly-review']);
    const p = make('monthly-review');
    expect(p.find('[data-testid="review-slot"]').exists()).toBe(true);
    expect(p.find('[data-testid="sbl-row-1"]').exists()).toBe(false);
    expect(p.find('[data-testid="sbl-search"]').exists()).toBe(false);
    expect(p.find('[data-testid="sbl-summary"]').exists()).toBe(false);
    expect(p.get('[data-testid="sbl-panel-monthly-review"]').attributes('aria-pressed')).toBe('true');
    expect(p.get('[data-testid="sbl-filter-all"]').attributes('aria-pressed')).toBe('false');
  });

  it('a normal filter chip from a panel goes back to the student rows', async () => {
    const p = make('monthly-drafts');
    await p.get('[data-testid="sbl-filter-owing"]').trigger('click');
    expect(p.emitted('view')[0]).toEqual(['students']);
  });

  it('a panel still opens when the alert list failed to load', () => {
    const p = make('monthly-drafts', { alertRows: [], error: '連線失敗' });
    expect(p.find('[data-testid="drafts-slot"]').exists()).toBe(true);
    expect(p.find('[data-testid="sbl-error"]').exists()).toBe(false);
  });

  it('an unknown view falls back to the rows (no empty page)', () => {
    const w = make('monthly-drafts', { panels: [] });
    expect(w.find('[data-testid="sbl-row-1"]').exists()).toBe(true);
  });
});
