// #3982 (in-app #396): on paid and review-needed billing rows the main action falls back to the
// label 在這裡看帳務, so the row showed two identical 在這裡看帳務 buttons side by side.
// The main action is now hidden whenever its label would duplicate the row's own ledger button.
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

// issue 4989 slice 5: the row buttons moved from `small ghost` / `small primary` to the template's rowAction class sets.
const ledgerButtonAt = source.indexOf(`:class="[rowAction.secondary, 'btn-invoices']"`);
const billingRowActions = source.slice(
  source.lastIndexOf(`<td :class="[dataTable.cell, dataTable.stackCell, dataTable.stackWide, 'tw:pr-5']">`, ledgerButtonAt),
  source.indexOf('</td>', ledgerButtonAt),
);

describe('課程帳務列 · 不重複顯示「在這裡看帳務」 (#3982)', () => {
  it('keeps the row ledger button', () => {
    expect(ledgerButtonAt).toBeGreaterThan(0);
    expect(billingRowActions).toContain('@click="openTuitionLedger(row.course)">在這裡看帳務</button>');
  });

  it('hides the main action when its label equals the ledger button', () => {
    const mainAction = billingRowActions.match(/<button v-(?:else-)?if="([^"]+)" :class="rowAction\.primary"[^>]*>\{\{ paymentNextActionLabel\(row\.course\) \}\}<\/button>/);
    expect(mainAction).not.toBeNull();
    expect(mainAction[1]).toBe('showsBillingRowNextAction(row.course)');
    expect(source).toContain(
      "const showsBillingRowNextAction = (course) => shouldShowPaymentAction(course) && paymentNextActionLabel(course) !== '在這裡看帳務';",
    );
  });

  it('the stacked (under 1024px) row decides its primary action with the same predicate and moves the rest into the 更多 menu', () => {
    expect(source).toContain('const billingPrimaryIsNext = (row, group) => !billingPackageLead(row, group) && showsBillingRowNextAction(row.course);');
    expect(source).toMatch(/const billingMenuGroups = [\s\S]*?id: 'payment-slip'[\s\S]*?if \(billingPrimaryIsNext\(row, group\)\) items\.push\(\{ id: 'ledger'/);
  });

  it('still falls back to 在這裡看帳務 for paid rows (the case the guard exists for)', () => {
    expect(source).toMatch(/const paymentNextActionLabel = \(course\) => \{[\s\S]*?return '在這裡看帳務';\n\};/);
  });
});
