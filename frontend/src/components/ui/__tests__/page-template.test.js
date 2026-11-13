import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../table';
import { PageShell, PageHeader, StatStrip, StatItem, SectionCard, StatusBadge, control, dataTable, toneBadge, toneText } from '../page';

// docs/design/PAGE_TEMPLATE.md: the shared page parts every migrated page is built from.
const Page = {
  components: { PageShell, PageHeader, StatStrip, StatItem, SectionCard, StatusBadge, Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow },
  setup: () => ({ t: dataTable }),
  template: `<PageShell data-testid="shell">
    <PageHeader title="當月學收" context="本月已上課堂數 × 費率的估算，不等於已收。"><template #actions><button type="button">匯出 CSV</button></template></PageHeader>
    <StatStrip>
      <StatItem label="學生" value="13" unit="人" />
      <StatItem label="未收" value="6" unit="人" sub="差 42,150" tone="danger" />
    </StatStrip>
    <SectionCard title="學收明細" :count="2">
      <template #actions><button type="button">只看未收</button></template>
      <Table :class="t.table">
        <TableCaption class="tw:sr-only">學收明細</TableCaption>
        <TableHeader><TableRow><TableHead scope="col" :class="t.head">學生</TableHead><TableHead scope="col" :class="t.numHead">上課應收</TableHead><TableHead scope="col" :class="t.head">收款狀態</TableHead></TableRow></TableHeader>
        <TableBody>
          <TableRow><TableCell :class="t.name">歐陽子晴<span :class="t.sub">高中物理・司馬文傑</span></TableCell><TableCell :class="t.num">3,000</TableCell><TableCell :class="t.cell"><StatusBadge tone="warning">部分</StatusBadge></TableCell></TableRow>
        </TableBody>
      </Table>
      <template #footer><span>共 2 筆</span></template>
    </SectionCard>
  </PageShell>`,
};

describe('components/ui page template', () => {
  it('lays a page out as shell, h2 header with context and actions, stat strip and a titled section card', () => {
    const w = mount(Page);
    const shell = w.get('[data-slot="page-shell"]');
    expect(shell.classes()).toEqual(expect.arrayContaining(['tw:max-w-7xl', 'tw:mx-auto', 'tw:px-[calc(var(--ds-space-4)-10px)]', 'tw:md:px-0']));
    expect(shell.attributes('data-testid')).toBe('shell');
    const h2 = w.findAll('h2');
    expect(h2).toHaveLength(1);
    expect(h2[0].text()).toBe('當月學收');
    expect(h2[0].classes()).toContain('tw:text-tpl-page');
    expect(w.findAll('h1')).toHaveLength(0);
    expect(w.get('[data-slot="page-context"]').text()).toBe('本月已上課堂數 × 費率的估算，不等於已收。');
    expect(w.get('[data-slot="page-actions"]').text()).toBe('匯出 CSV');
    const strip = w.get('[data-slot="stat-strip"]');
    expect(strip.element.tagName).toBe('DL');
    expect(strip.findAll('dt').map((n) => n.text())).toEqual(['學生', '未收']);
    expect(strip.findAll('[data-slot="stat-item"]')[1].text()).toContain('差 42,150');
    const card = w.get('[data-slot="section-card"]');
    const h3 = card.get('h3');
    expect(h3.text()).toBe('學收明細（2）');
    expect(card.attributes('aria-labelledby')).toBe(h3.attributes('id'));
    expect(card.get('footer').text()).toBe('共 2 筆');
    w.unmount();
  });

  it('StatItem keeps a real space between the figure and its unit, so "13 人" reads as one phrase', () => {
    const w = mount({ components: { StatItem }, template: '<dl><StatItem label="學生" :value="13" unit="人" /><StatItem label="學收合計" value="103,350" /></dl>' });
    const [withUnit, plain] = w.findAll('[data-slot="stat-item"] dd:first-of-type');
    expect(withUnit.text()).toBe('13 人');
    expect(plain.text()).toBe('103,350');
    w.unmount();
  });

  it('omits the parts a page does not use', () => {
    const w = mount({ components: { PageHeader, SectionCard }, template: '<div><PageHeader title="設定" /><SectionCard><p>內容</p></SectionCard></div>' });
    expect(w.find('[data-slot="page-context"]').exists()).toBe(false);
    expect(w.find('[data-slot="page-actions"]').exists()).toBe(false);
    const card = w.get('[data-slot="section-card"]');
    expect(card.find('header').exists()).toBe(false);
    expect(card.find('footer').exists()).toBe(false);
    expect(card.attributes('aria-labelledby')).toBeUndefined();
    w.unmount();
  });

  it('keeps names on one line, numbers right-aligned and tabular, and overrides the global th / td look', () => {
    expect(dataTable.name).toContain('tw:whitespace-nowrap');
    expect(dataTable.name).not.toMatch(/overflow-wrap|break-/);
    for (const cls of ['tw:text-right', 'tw:tabular-nums', 'tw:whitespace-nowrap']) expect(dataTable.num).toContain(cls);
    expect(dataTable.numHead).toContain('tw:text-right');
    for (const cls of ['tw:normal-case', 'tw:tracking-normal', 'tw:text-tpl-caption', 'tw:bg-muted']) expect(dataTable.head).toContain(cls);
    expect(dataTable.cell).toContain('tw:text-[length:var(--tpl-text-body)]');
    expect(dataTable.sub).toContain('tw:whitespace-normal');
    expect(control.page).toContain('tw:h-12');
    expect(control.row).toContain('tw:h-11');
  });

  it('status never rests on colour alone and falls back to neutral', () => {
    const w = mount({ components: { StatusBadge }, template: '<div><StatusBadge tone="danger">未收</StatusBadge><StatusBadge tone="nope">其他</StatusBadge></div>' });
    const [danger, other] = w.findAll('[data-slot="status-badge"]');
    expect(danger.text()).toBe('未收');
    expect(danger.find('[aria-hidden="true"]').exists()).toBe(true);
    for (const cls of toneBadge.danger.split(' ')) expect(danger.classes()).toContain(cls);
    for (const cls of toneBadge.neutral.split(' ')) expect(other.classes()).toContain(cls);
    expect(Object.keys(toneBadge)).toEqual(Object.keys(toneText));
    w.unmount();
  });

  it('uses tokens only: no raw colour, font-size or radius, and no legacy At* import', () => {
    const dir = resolve(__dirname, '../page');
    for (const file of readdirSync(dir)) {
      const src = readFileSync(resolve(dir, file), 'utf8');
      expect(src, file).not.toMatch(/#[0-9a-fA-F]{3,8}\b|font-size\s*:|border-radius\s*:|design-system\/At|<style/);
    }
  });

  it('has no axe violations (colour contrast is checked in the browser)', async () => {
    const w = mount(Page, { attachTo: document.body });
    const res = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(res.violations.map((v) => v.id)).toEqual([]);
    w.unmount();
  });
});
