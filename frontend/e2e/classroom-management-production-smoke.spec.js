// @ts-check
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = {
  account: process.env.SMOKE_DIRECTOR_USER,
  password: process.env.SMOKE_DIRECTOR_PASS,
};

async function loginAsDirector(page) {
  await page.goto('/');
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  await page.locator('button.login-btn').click({ force: true });
  if (await page.locator('#login-account').count()) {
    await page.locator('button.login-btn').click({ force: true });
  }
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
  // Deep-link navigation before the director landing state settles can be
  // overwritten by the auth/profile bootstrap and leave this test elsewhere.
  await expect(page.getByRole('main', { name: '主任總覽' })).toBeVisible({ timeout: 25_000 });
}

test.describe('UI smoke — production classroom management', () => {
  test.skip(
    !BASE || !DIRECTOR.account || !DIRECTOR.password,
    '未設定 SMOKE_BASE_URL / SMOKE_DIRECTOR_*（缺 director secrets）— 略過',
  );

  test('director can load classroom management without a JavaScript error', async ({ page }) => {
    test.setTimeout(60_000);
    const errors = [];
    page.on('pageerror', (error) => errors.push(String(error)));
    page.on('response', (response) => {
      const path = new URL(response.url()).pathname;
      if (['/api/v1/auth/login', '/api/v1/me', '/api/v1/campuses'].includes(path)) {
        // Keep failed-login evidence without printing credentials or bodies.
        console.log(`[classroom-smoke] ${path} HTTP ${response.status()}`);
      }
    });

    await loginAsDirector(page);
    await page.goto('/?app_page=classroom');
    await dismissOverlays(page);

    await expect(page.getByRole('heading', { name: '教室管理', exact: true })).toBeVisible();
    await expect(page.locator('.classroom-page .card')).toHaveAttribute('aria-busy', 'false', { timeout: 15_000 });

    const classroomState = page.locator('[data-guide="classroom-table"], .empty-text');
    await expect(classroomState).toHaveCount(1, { timeout: 15_000 });

    const table = page.locator('[data-guide="classroom-table"]');
    if (await table.isVisible()) {
      await expect(table.getByRole('columnheader', { name: '教室名稱', exact: true })).toBeVisible();
      const firstRow = table.locator('tbody tr').first();
      await expect(firstRow).toBeVisible();
      await expect(firstRow.getByRole('button', { name: /^編輯教室：.+$/ })).toBeVisible();
      await expect(firstRow.getByRole('button', { name: /^(?:停用|啟用)教室：.+$/ })).toBeVisible();
      await expect(firstRow.getByRole('button', { name: /^刪除教室：.+$/ })).toBeVisible();
    } else {
      await expect(page.locator('.empty-text')).toBeVisible();
      await expect(page.locator('.empty-text').getByRole('button', { name: '新增教室', exact: true })).toBeVisible();
    }

    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});
