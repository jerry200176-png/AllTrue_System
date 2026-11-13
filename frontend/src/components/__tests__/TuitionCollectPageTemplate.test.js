import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import axe from 'axe-core';
import BillList from '../tuition/BillList.vue';
import { Checkbox } from '../ui/checkbox';
import { FilterChip, SelectionBar, StatItem, StatStrip, pageShell, pageTabs } from '../ui/page';

// #4989 page 2: 帳務中心 · 帳單 on the page template (presentation only; behaviour is covered by BillList.test.js and the Tuition* page tests).
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
});

const row = (id, over = {}) => ({
  id, student_id: id, student_name: `歐陽子${id}`, subject: '國中數學', schedule_mode: 'count', payment_status: 'unpaid',
  charge: 4200, paid_amount: 0, outstanding: 4200, payable_amount: 4200, payable_outstanding: 4200, due_date: '2026-09-12',
  days_until_settlement: -3, course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 500 + id, payable_status: 'invoiced',
  remaining_sessions: 2, sessions_purchased: 16, ...over,
});
const mountList = (props = {}) => mount(BillList, {
  props: { today: '2026-09-15', alertRows: [row(1), row(2, { payment_status: 'pending_report', paid_amount: 4200, outstanding: 0, payable_outstanding: 0, latest_payment_report_id: 9 }), row(3, { payment_status: 'renew_needed', payable_outstanding: 0, outstanding: 0 })], batchModes: { 1: 'report', 2: 'confirm' }, ...props },
  attachTo: document.body,
});

describe('帳單 list on the page template', () => {
  it('is built from the shared page parts: no At* import and no scoped style', () => {
    const src = readFileSync(resolve(__dirname, '../tuition/BillList.vue'), 'utf8');
    expect(src).not.toMatch(/design-system\/At/);
    expect(src).not.toContain('<style');
    expect(src).toMatch(/from '@\/components\/ui\/page'/);
  });

  it('renders the badge once per row, the table rows once, and keeps one status word per row', () => {
    const w = mountList();
    const r = w.get('[data-testid="bill-row-c1"]');
    expect(r.findAll('[data-slot="status-badge"]')).toHaveLength(1);
    expect(r.element.tagName).toBe('TR');
    expect(w.findAll('tbody tr')).toHaveLength(3);
    expect(w.get('thead tr .bl__num').text()).toBe('未收');
    w.unmount();
  });

  it('keeps the reserved checkbox column: a row that cannot be picked shows 「–」 with its reason', () => {
    const w = mountList();
    const nopick = w.get('[data-testid="bill-nopick-c3"]');
    expect(nopick.attributes('title')).toBeTruthy();
    expect(nopick.text()).toContain('–');
    expect(nopick.text()).toContain(nopick.attributes('title'));
    const cells = (id) => w.get(`[data-testid="bill-row-c${id}"]`).findAll('td')[0];
    expect(cells(1).find('[role="checkbox"]').exists()).toBe(true);
    expect(cells(3).find('[role="checkbox"]').exists()).toBe(false);
    w.unmount();
  });

  it('shows the selection bar only while rows are picked, with the same words as before', async () => {
    const w = mountList();
    expect(w.find('[data-slot="selection-bar"]').exists()).toBe(false);
    await w.get('[data-testid="bill-filter-owing"]').trigger('click');
    await w.get('[data-testid="bill-pick-c1"]').trigger('click');
    expect(w.get('[data-slot="selection-bar"]').attributes('role')).toBe('toolbar');
    expect(w.get('[data-testid="bill-picked-total"]').text()).toBe('已選 1 筆，到今天未收合計 NT$ 4,200');
    expect(w.get('[data-testid="bill-batch"]').text()).toBe('登記 1 筆');
    expect(w.get('[data-testid="bill-select-all"]').attributes('aria-checked')).toBe('true');
    w.unmount();
  });

  it('a summary figure still filters, and says which one is on', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-sum-confirm"]').trigger('click');
    expect(w.get('[data-testid="bill-sum-confirm"]').attributes('aria-pressed')).toBe('true');
    expect(w.findAll('[data-testid^="bill-row-"]')).toHaveLength(1);
    w.unmount();
  });

  it('has no axe violations (colour contrast is checked in the browser)', async () => {
    const w = mountList();
    await w.get('[data-testid="bill-filter-owing"]').trigger('click');
    await w.get('[data-testid="bill-pick-c1"]').trigger('click');
    const res = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(res.violations.map((v) => v.id)).toEqual([]);
    w.unmount();
  });
});

const setPhone = (on) => {
  window.matchMedia = (q) => ({ matches: on, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
};
afterEach(() => { delete window.matchMedia; });

describe('what a tap on a row does (same handlers as the old buttons)', () => {
  const rows = () => [
    row(1), // owing contract: quick view can record
    row(2, { payment_status: 'pending_report', paid_amount: 4200, outstanding: 0, payable_outstanding: 0, latest_payment_report_id: 9 }), // confirm
    row(3, { payment_status: 'renew_needed', payable_outstanding: 0, outstanding: 0 }), // issue
    row(4, { payment_status: 'amount_review', amount_pending_confirmation: true, pending_confirmation_amount: 800, open_periods: [{ outstanding: 800, due_date: '2026-12-01' }] }), // review
    row(5, { id: null, package_id: 7 }), // owing package: no contract, the quick view cannot record
  ];
  const mountAll = () => mountList({ alertRows: rows(), batchModes: {} });
  const tap = async (w, key) => { await w.get(`[data-testid="bill-row-${key}"]`).trigger('click'); };
  const clickButton = async (w, key) => { await w.get(`[data-testid="bill-action-${key}"]`).trigger('click'); };

  it('a phone tap does what the row button does where the quick view cannot (開帳單 / 繳費單 / 看帳務, 核對, package 登記)', async () => {
    setPhone(true);
    const w = mountAll();
    for (const key of ['c3', 'c4', 'p7']) {
      await clickButton(w, key);
      const viaButton = w.emitted('action').at(-1);
      const before = w.emitted('action').length;
      await tap(w, key);
      expect(w.emitted('action').length, key).toBe(before + 1);
      expect(w.emitted('action').at(-1).slice(1), key).toEqual(viaButton.slice(1));
      expect(w.emitted('action').at(-1)[0].key).toBe(key);
    }
    expect(w.emitted('action').at(-1)[1]).toBe('record'); // the package row: 登記收款
    expect(w.emitted('quick')).toBeUndefined();
    // the button and its ⋯ menu stay visible on those rows; a covered row shows a chevron instead
    const shown = (key) => !w.get(`[data-testid="bill-row-${key}"] .bl__actions`).classes().includes('tw:max-md:hidden');
    expect([shown('c3'), shown('c4'), shown('p7'), shown('c1'), shown('c2')]).toEqual([true, true, true, false, false]);
    w.unmount();
  });

  it('a phone tap opens the quick view where it can 登記收款 or 確認入帳', async () => {
    setPhone(true);
    const w = mountAll();
    await tap(w, 'c1');
    await tap(w, 'c2');
    expect(w.emitted('quick').map(([b]) => b.key)).toEqual(['c1', 'c2']);
    expect(w.emitted('action')).toBeUndefined();
    w.unmount();
  });

  it('on a desktop every row tap still opens the quick view', async () => {
    setPhone(false);
    const w = mountAll();
    for (const key of ['c1', 'c3', 'c4', 'p7']) await tap(w, key);
    expect(w.emitted('quick').map(([b]) => b.key)).toEqual(['c1', 'c3', 'c4', 'p7']);
    expect(w.emitted('action')).toBeUndefined();
    w.unmount();
  });

  it('a student without a bill can be opened by touch: the name is a real 44px target', async () => {
    setPhone(true);
    const w = mountList({ students: [{ id: 99, name: '歐陽子晴' }] });
    await w.get('[data-testid="bill-search"]').setValue('歐陽子晴');
    const btn = w.get('[data-testid="bill-student-99"]');
    expect(btn.classes()).not.toContain('tw:max-md:pointer-events-none');
    expect(btn.classes()).toContain('tw:min-h-11');
    await btn.trigger('click');
    expect(w.emitted('student')[0][0]).toMatchObject({ student_id: 99, class_id: null });
    w.unmount();
  });

  it('one status badge per row, on every screen, with the status words', () => {
    const w = mountAll();
    expect(w.get('[data-testid="bill-row-c1"] [data-slot="status-badge"]').text()).toBe('未繳');
    expect(w.findAll('[data-testid="bill-row-c1"] [data-slot="status-badge"]')).toHaveLength(1);
    expect(w.get('[data-testid="bill-row-c1"] td.bl__num').text()).not.toContain('未繳'); // no second copy of the status word
    w.unmount();
  });

  it('全選 reports the mixed state when only some rows are picked, and the bar announces the count', async () => {
    const w = mountList({ alertRows: [row(1), row(6, { student_id: 6 })], batchModes: { 1: 'report', 6: 'report' } });
    await w.get('[data-testid="bill-pick-c1"]').trigger('click');
    expect(w.get('[data-testid="bill-select-all"]').attributes('aria-checked')).toBe('mixed');
    const live = w.get('[data-slot="selection-bar"] [aria-live="polite"]');
    expect(live.text()).toBe('已選 1 筆');
    await w.get('[data-testid="bill-select-all"]').trigger('click');
    expect(w.get('[data-testid="bill-select-all"]').attributes('aria-checked')).toBe('true');
    expect(w.get('[data-slot="selection-bar"] [aria-live="polite"]').text()).toBe('已選 2 筆');
    w.unmount();
  });
});

describe('shared parts added for 帳單', () => {
  it('FilterChip: pill with its count, pressed state only when it toggles', () => {
    const w = mount({ components: { FilterChip }, template: '<div><FilterChip active :count="3">要處理</FilterChip><FilterChip :toggle="false">已結清課程</FilterChip></div>' });
    const [on, nav] = w.findAll('[data-slot="filter-chip"]');
    expect(on.text()).toBe('要處理 3');
    expect(on.attributes('aria-pressed')).toBe('true');
    expect(on.classes()).toContain('tw:bg-primary');
    expect(on.classes()).toContain('tw:h-11');
    expect(nav.attributes('aria-pressed')).toBeUndefined();
    w.unmount();
  });

  it('SelectionBar: a toolbar with the picked summary and its actions', () => {
    const w = mount({ components: { SelectionBar }, template: '<SelectionBar><b>已選 2 筆</b><template #actions><button type="button">取消選取</button></template></SelectionBar>' });
    const bar = w.get('[data-slot="selection-bar"]');
    expect(bar.attributes('role')).toBe('toolbar');
    expect(bar.text()).toContain('已選 2 筆');
    expect(bar.get('button').text()).toBe('取消選取');
    w.unmount();
  });

  it('StatItem: an optional overlay slot, so a figure can also be a filter button', () => {
    const w = mount({ components: { StatStrip, StatItem }, template: '<StatStrip><StatItem label="逾期" :value="2" unit="筆"><button type="button" data-testid="ovl">看逾期</button></StatItem></StatStrip>' });
    const item = w.get('[data-slot="stat-item"]');
    expect(item.classes()).toContain('tw:relative');
    expect(item.get('[data-testid="ovl"]').exists()).toBe(true);
    w.unmount();
  });

  it('Checkbox shows a dash, not a tick, in the mixed state', () => {
    const w = mount(Checkbox, { props: { modelValue: 'indeterminate' }, attachTo: document.body });
    expect(w.get('[role="checkbox"]').attributes('data-state')).toBe('indeterminate');
    expect(w.find('svg.lucide-minus').exists()).toBe(true);
    expect(w.find('svg.lucide-check').exists()).toBe(false);
    w.unmount();
  });

  it('pageShell and pageTabs class sets match the template', () => {
    for (const cls of ['tw:max-w-7xl', 'tw:gap-4', 'tw:md:px-0']) expect(pageShell).toContain(cls);
    const tab = (on) => pageTabs.tab(on).join(' ');
    expect(tab(true)).toContain('tw:border-foreground');
    expect(tab(false)).toContain('tw:border-transparent');
    expect(tab(true)).toContain('tw:h-12');
  });
});
