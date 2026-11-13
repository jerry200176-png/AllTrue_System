// issue 4989, 課程查找 slice 4: tones come from statuses and warning kinds, never from the words on a chip.
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 'synthetic-token' } } }) } },
}));

import * as M from '../../lib/courseMoneyState.js';
import { courseRowWarningItems, courseRowWarningSummary, duplicatesUsageReviewButton, usageBalanceWarningTitle } from '../../lib/courseRowWarnings.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const page = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');
const course = (overrides) => ({ id: 7201, class_type: 'one_on_one', payment_type: 'session', ...overrides });

describe('isPartialCoursePayment reads the payment status', () => {
  it('part-paid is true, unpaid / paid / review are false', () => {
    expect(M.isPartialCoursePayment(course({ payment_status: 'partial' }))).toBe(true);
    for (const payment_status of ['unpaid', 'paid', 'free', 'pending_report', 'review_required', 'something_new']) {
      expect(M.isPartialCoursePayment(course({ payment_status }))).toBe(false);
    }
  });

  it('follows the monthly period status when the period label is shown', () => {
    const monthly = (status) => course({ payment_status: 'unpaid', monthly_payment: { billing_period: '2026-10', payment_status: status } });
    expect(M.isPartialCoursePayment(monthly('partial'), { monthlyPeriod: true })).toBe(true);
    expect(M.isPartialCoursePayment(monthly('unpaid'), { monthlyPeriod: true })).toBe(false);
    expect(M.isPartialCoursePayment(monthly('partial'))).toBe(false);
  });

  it('a label wording change does not change the verdict', () => {
    const partial = M.TUITION_STATUS_CONFIG.partial;
    const unpaid = M.TUITION_STATUS_CONFIG.unpaid;
    const saved = [partial.label, unpaid.label];
    try {
      partial.label = '已付一半';
      unpaid.label = '尚欠部分款項';
      expect(M.coursePaymentBadge(course({ payment_status: 'partial' })).label).toBe('已付一半'); // the words really changed
      expect(M.isPartialCoursePayment(course({ payment_status: 'partial' }))).toBe(true);
      expect(M.isPartialCoursePayment(course({ payment_status: 'unpaid' }))).toBe(false);
    } finally {
      [partial.label, unpaid.label] = saved;
    }
  });

  it('the page no longer decides 部分 by matching text', () => {
    expect(page).not.toContain("includes('部分')");
    expect(page).toContain('isPartialCoursePayment(course, { monthlyPeriod: true })');
  });
});

describe('duplicatesUsageReviewButton reads the warning kind', () => {
  const review = { usage_balance_status: 'review_required' };
  it('drops only the usage-review chip of a course that shows the button', () => {
    const [chip] = courseRowWarningItems(review, usageBalanceWarningTitle);
    expect(chip.kind).toBe('usage_review');
    expect(duplicatesUsageReviewButton(review, chip)).toBe(true);
    expect(duplicatesUsageReviewButton({}, chip)).toBe(false);
  });

  it('is not fooled by wording: the same words under another kind stay, other words under the kind go', () => {
    expect(duplicatesUsageReviewButton(review, { kind: 'schedule_drift', label: '⚠ 堂數待對帳' })).toBe(false);
    expect(duplicatesUsageReviewButton(review, { kind: 'usage_review', label: '另一段文字' })).toBe(true);
  });

  it('a summary chip of several warnings stays', () => {
    const many = { ...review, hasSlotConflict: true };
    const [summary] = courseRowWarningSummary(many, usageBalanceWarningTitle);
    expect(summary.kind).toBe('summary');
    expect(duplicatesUsageReviewButton(many, summary)).toBe(false);
  });

  it('the page uses the helper and has no local wording match', () => {
    expect(page).not.toContain("String(warning.label).includes");
    expect(page).toContain('duplicatesUsageReviewButton(c, w)');
  });
});

describe('history cards use the shared template parts', () => {
  const start = page.indexOf('v-else class="history-section');
  const card = page.slice(start, start + 9000);
  it('no old tag classes are left on the card', () => {
    expect(card).not.toMatch(/class="tag |class="status-tag|tag-history/);
    expect(card).toContain('<StatusBadge');
  });
  it('maps each closed state to its tone', () => {
    expect(card).toContain(`effectiveClosedReason(hc) === 'converted_trial'" tone="success"`);
    expect(card).toContain(`closedPaymentState(hc) === 'pending'" tone="warning"`);
    expect(card).toContain('<StatusBadge v-else tone="neutral">已完課</StatusBadge>');
  });
  it('names the chip-wrap threshold', () => {
    expect(page).toContain('const PAYMENT_CHIP_NOWRAP_MAX = 8;');
    expect(page).toContain('.length <= PAYMENT_CHIP_NOWRAP_MAX');
  });
});
