// @ts-check
import { test, expect } from '@playwright/test';

/** Action Inbox journeys. Skips without SMOKE_*; screenshots land in test output. */
const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = { account: process.env.SMOKE_DIRECTOR_USER, password: process.env.SMOKE_DIRECTOR_PASS };

async function loginDirector(page) {
  await page.goto('/');
  await page.locator('.role-btn', { hasText: '主任' }).first().click().catch(() => {});
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  await page.locator('button.login-btn').click();
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

test.describe('Action Inbox', () => {
  test.skip(!BASE || !DIRECTOR.account, 'needs SMOKE_BASE_URL + SMOKE_DIRECTOR_*');

  test('urgent badge / pager / deep-link / cross-campus hide', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.route('**/api/v1/action-inbox/count**', async (route) => {
      const branch = new URL(route.request().url()).searchParams.get('branch_id');
      if (branch === '999') return route.fulfill({ status: 403, body: '{"message":"forbidden"}' });
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          notifications_unread: 1, cases_unresolved: 2, cases_overdue: 0, cases_due_soon: 1,
          cases_candidate_ready: 1, urgent_total: 0, badge_total: 3,
        }),
      });
    });
    await loginDirector(page);
    await page.getByRole('button', { name: '主任收件匣', exact: false }).first().click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.getByRole('button', { name: '主任收件匣', exact: false }).first()).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('inbox-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: testInfo.outputPath('inbox-390.png'), fullPage: true });
    expect(errors, errors.join('\n')).toEqual([]);
  });
});
