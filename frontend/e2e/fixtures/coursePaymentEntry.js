// @ts-check
import { expect } from '@playwright/test';
import { REPORT_STATUS_LABELS } from '../../src/lib/courseMoneyState.js';

/**
 * 課程查找 payment cell next actions (CourseManagement.vue paymentNextActionLabel):
 * unpaid/partial → 登記繳費回報, pending report → 查看「待核對入帳」, otherwise 在這裡看帳務.
 */
export const COURSE_PAYMENT_ACTION_NAME = new RegExp(
  `^(?:登記繳費回報|查看「${REPORT_STATUS_LABELS.pending}」|在這裡看帳務)$`,
);

/**
 * On 課程查找, the payment status is a read-only badge and its next action opens
 * the student's billing panel in place (課程查找 C-PR5 #3843, #3858). It no longer
 * navigates to 帳務中心.
 *
 * #4130 follow-up: the acceptance still expected a 前往帳務中心 button and a
 * 帳務中心 heading after the click, so it failed on every build since #3858.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ pageHeading: import('@playwright/test').Locator }} options heading of the 課程查找 page
 */
export async function expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading }) {
  const paymentCell = page.locator('.payment-status-and-action').filter({
    has: page.getByRole('button', { name: COURSE_PAYMENT_ACTION_NAME }),
  }).first();
  await expect(paymentCell, 'a 課程查找 row with a payment next action').toBeVisible({ timeout: 15_000 });
  const status = paymentCell.locator('.payment-status-badge');
  const action = paymentCell.getByRole('button', { name: COURSE_PAYMENT_ACTION_NAME });
  await expect(status).toBeVisible({ timeout: 15_000 });
  await expect(status).toHaveAttribute('role', 'status');
  await expect(status).toHaveCSS('cursor', 'default');
  await expect(page.locator('.btn-status')).toHaveCount(0);
  await expect(action).toBeVisible();

  const ledgerResponse = page.waitForResponse(
    (response) => response.url().includes('/api/v1/accounting/ledger?') && response.request().method() === 'GET',
    { timeout: 15_000 },
  );
  await action.click();
  const ledger = page.getByRole('dialog').filter({ has: page.locator('#ledger-modal-title') });
  await expect(ledger, 'student billing panel opens in place').toBeVisible({ timeout: 15_000 });
  const response = await ledgerResponse;
  expect(response.status(), 'student billing panel ledger request').toBe(200);
  await expect(ledger.locator('#ledger-modal-title')).toHaveText(/^(?:.+的帳務|學生帳務)$/);
  await expect(pageHeading, 'still on 課程查找 (billing opens in place)').toBeVisible();
}
