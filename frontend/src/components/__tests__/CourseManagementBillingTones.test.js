// issue 4989, 課程查找 slice 5: the 帳務資料 table is built from the shared template parts (dataTable class set, StatusBadge, tones.js)
// and none of the old scoped table / tag styles are left. A money screen: the tone of each state comes from the payment status, not from words.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dataTable, toneBadge, toneText } from '@/components/ui/page';
import * as tones from '@/components/ui/page/tones.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const page = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const billing = page.slice(page.indexOf(":class=\"['student-billing-state'"), page.indexOf('</table>', page.indexOf('student-billing-table')));
const style = page.slice(page.indexOf('<style'));

describe('課程查找 帳務資料 table on the page template', () => {
  it('uses StatusBadge with the payment tone, and the same chip rule as the course rows', () => {
    expect(billing).toContain('<StatusBadge');
    expect(billing).toContain(':tone="paymentStatusTone(row.course)"');
    expect(billing).toContain('PAYMENT_CHIP_NOWRAP_MAX');
    expect(billing).toContain('role="status"');
    expect(billing).not.toContain('paymentStatusButtonClass');
  });

  it('reads 部分 off the payment status, not off the label words', () => {
    expect(page).toContain("const isPartialPayment = (course) => isPartialCoursePayment(course, { monthlyPeriod: true });");
    expect(page).not.toMatch(/includes\('部分'\)/);
  });

  it('builds head, cells, amounts and facts from the dataTable class set', () => {
    for (const key of ['head', 'cell', 'sub', 'amount', 'amountPending', 'amountEstimate', 'fact']) {
      expect(billing, `dataTable.${key}`).toContain(`dataTable.${key}`);
      expect(dataTable[key], `dataTable.${key} exists`).toBeTruthy();
    }
  });

  it('every amount line is tabular and stays on one line; only the "not billed yet" line takes a warning colour', () => {
    for (const key of ['amount', 'amountPending', 'amountEstimate']) {
      expect(dataTable[key]).toContain('tw:tabular-nums');
      expect(dataTable[key]).toContain('tw:whitespace-nowrap');
      expect(dataTable[key]).not.toMatch(/danger/);
    }
    expect(dataTable.amountPending).toContain('--ds-warning-text');
  });

  it('red is only used for owed money in this table: the summary total, the anomaly line and the error line', () => {
    const reds = billing.match(/toneText\.danger/g) || [];
    expect(reds.length).toBe(3);
    expect(billing).toMatch(/toneText\.danger, 'tw:font-semibold tw:tabular-nums'\]">尚有未繳/);
    expect(toneText.danger).toContain('--ds-danger-text');
    expect(toneBadge.danger).toContain('--ds-danger-wash');
  });

  it('keeps the hooks the specs and the money rules use', () => {
    for (const hook of ['student-billing-table', 'student-billing-state', 'student-billing-wrap', 'btn-invoices', 'payment-status-badge', 'data-testid="billing-contract-period"', 'data-testid="billing-contract-period-range"', 'data-testid="billing-payment-slip-action"', 'data-testid="billing-package-follower"']) {
      expect(page, hook).toContain(hook);
    }
    expect(billing).toContain('colspan="6"');
    expect(billing).toContain('<span class="tw:sr-only">操作</span>');
  });

  it('no old tag, price-line or table style is left behind', () => {
    expect(billing).not.toMatch(/class="[^"]*\b(tag|subject-tag|price-line|hint|course-table|small ghost|small primary)\b/);
    for (const selector of ['.subject-tag', '.price-line', '.course-table', '.student-ledger-summary', '.student-billing-note', '.btn-invoices', '.tag-paid', '.tag-unpaid', '.tag-pending-report', '.tag-no-payment', '.tag-billing-anomaly', '.col-actions']) {
      expect(style, selector).not.toContain(`${selector} {`);
      expect(style, selector).not.toContain(`${selector},`);
    }
  });
});

// Shared class sets: a lost space glues two classes into one (`tw:h-14tw:border-0`) and both stop working on every page that uses the set.
const GLUED = /\S(?=tw:)/;
const collect = (v, path) => {
  if (typeof v === 'string') return [[path, v]];
  if (typeof v === 'function') return [...collect(v(true), `${path}(true)`), ...collect(v(false), `${path}(false)`)];
  if (Array.isArray(v)) return v.flatMap((x, i) => collect(x, `${path}[${i}]`));
  if (v && typeof v === 'object') return Object.entries(v).flatMap(([k, x]) => collect(x, `${path}.${k}`));
  return [];
};
describe('tones.js class sets', () => {
  it('has no two classes glued together in any value', () => {
    const all = Object.entries(tones).flatMap(([name, v]) => collect(v, name));
    expect(all.length).toBeGreaterThan(40);
    for (const [path, value] of all) expect(value, path).not.toMatch(GLUED);
  });
  it('the check catches a glued pair', () => {
    expect('tw:h-14tw:border-0 tw:bg-muted').toMatch(GLUED);
  });
});

describe('dataTable cells in dark mode', () => {
  it('cells and the totals row override the global `tr:hover td` fill with an important, token-based background', () => {
    expect(dataTable.cell).toContain('tw:bg-transparent!');
    expect(dataTable.name).toContain('tw:bg-transparent!');
    expect(dataTable.num).toContain('tw:bg-transparent!');
    expect(dataTable.total).toContain('tw:bg-muted!');
    expect(dataTable.cell).not.toMatch(/#[0-9a-f]{3,8}/i);
  });
});
