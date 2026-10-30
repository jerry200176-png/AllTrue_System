import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// docs/plans/2026-10-30-billing-copy-ux-spec.md §2.1: one status vocabulary on director billing surfaces.
const RETIRED = ['已回報付款', '待你核對入帳', '待核對入帳', '未結清', '已記入', '部分記入', '已收清', '繳費通知單', '登記已回報', '登記繳費回報', '金額待確認'];
const FILES = [
  'lib/courseMoneyState.js', 'lib/studentBillingRows.js', 'lib/billingDocState.js',
  'components/AccountingLedgerModal.vue', 'components/PaymentEntryModal.vue', 'components/PaymentSlipModal.vue',
  'components/MonthlyBillingReview.vue', 'components/tuition/ContractCard.vue', 'components/tuition/StudentBillingList.vue',
  'components/tuition/PaymentAllocation.vue', 'components/tuition/MonthlyDraftsPanel.vue', 'pages/TuitionCollectionPage.vue',
];
const stripComments = (s) => s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '');

describe('billing vocabulary guard', () => {
  for (const f of FILES) {
    it(`${f} uses no retired status synonym`, () => {
      const src = stripComments(readFileSync(resolve(__dirname, '../../', f), 'utf8'));
      expect(RETIRED.filter((w) => src.includes(w))).toEqual([]);
    });
  }
});
