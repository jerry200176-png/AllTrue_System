import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/SubjectUnitsPage.vue'), 'utf8');

describe('SubjectUnitsPage disclosure accessibility', () => {
  it('uses one labelled native toggle for the calculation guide', () => {
    const headerStart = source.indexOf('<div class="calc-guide-header">');
    const panelStart = source.indexOf('<div\n        v-show="showCalcGuide"', headerStart);
    const header = source.slice(headerStart, panelStart);

    expect(headerStart).toBeGreaterThanOrEqual(0);
    expect(panelStart).toBeGreaterThan(headerStart);
    expect(source).not.toContain('<div class="calc-guide-header" @click=');
    expect((header.match(/<button/g) || []).length).toBe(1);
    expect(header).toContain('class="ghost small calc-guide-toggle"');
    expect(header).toContain(':aria-expanded="showCalcGuide"');
    expect(header).toContain('aria-controls="subject-units-calc-guide-body"');
    expect(source).toContain('id="subject-units-calc-guide-body"');
  });

  it('uses one labelled native toggle for the level breakdown', () => {
    const headerStart = source.indexOf('<div class="level-breakdown-header">');
    const panelStart = source.indexOf('<div\n        v-show="showLevelBreakdown"', headerStart);
    const header = source.slice(headerStart, panelStart);

    expect(headerStart).toBeGreaterThanOrEqual(0);
    expect(panelStart).toBeGreaterThan(headerStart);
    expect(source).not.toContain('<div class="level-breakdown-header" @click=');
    expect((header.match(/<button/g) || []).length).toBe(1);
    expect(header).toContain('class="ghost small level-breakdown-toggle"');
    expect(header).toContain(':aria-expanded="showLevelBreakdown"');
    expect(header).toContain('aria-controls="subject-units-level-breakdown-body"');
    expect(source).toContain('id="subject-units-level-breakdown-body"');
  });

  it('keeps the daily comparison dimensions and role copy visible in the page contract', () => {
    expect(source).toContain('/v1/finance/subject-units/timeline');
    expect(source).toContain('老師 × 日期 × 分校 × 科目');
    expect(source).toContain('只顯示我的資料');
    expect(source).toContain('正課');
    expect(source).toContain('輔導／試聽');
    expect(source).toContain('核薪');
    expect(source).toContain('每日核薪科目數');
  });
});
