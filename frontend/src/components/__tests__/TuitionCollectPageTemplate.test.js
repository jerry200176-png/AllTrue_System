import { beforeAll, describe, expect, it } from 'vitest';
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
