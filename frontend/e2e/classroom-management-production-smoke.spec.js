// @ts-check
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { createBranchApiProbe } from './fixtures/branchApiProbe.js';

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = {
  account: process.env.SMOKE_DIRECTOR_USER,
  password: process.env.SMOKE_DIRECTOR_PASS,
};

async function loginAsDirector(page) {
  const roomsProbe = createBranchApiProbe('/api/v1/rooms');
  roomsProbe.attach(page);
  // Let App.vue apply its own deep link after auth/profile bootstrap instead
  // of navigating a second time while the authorized campus is still loading.
  await page.goto('/?app_page=classroom');
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  const campusesPromise = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/api/v1/campuses' && response.status() === 200,
    { timeout: 25_000 },
  );
  await page.locator('button.login-btn').click({ force: true });
  if (await page.locator('#login-account').count()) {
    await page.locator('button.login-btn').click({ force: true });
  }
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
  const campuses = await (await campusesPromise).json();
  expect(Array.isArray(campuses) && campuses.length > 0, 'director must have authorized campuses').toBe(true);
  const authorizedIds = new Set(campuses.map((campus) => Number(campus.id)));
  const authorizedIdsSorted = [...authorizedIds].sort((a, b) => a - b);
  await expect.poll(async () => (
    await page.locator('#mobile-branch-select option:not([value=""])').evaluateAll(
      (options) => options.map((option) => Number(option.value)).sort((a, b) => a - b),
    )
  ), { timeout: 25_000 }).toEqual(authorizedIdsSorted);
  const selectedBranch = Number(await page.locator('#mobile-branch-select').inputValue());
  expect(authorizedIds.has(selectedBranch),
    'selected classroom campus ID must be authorized').toBe(true);
  await expect.poll(() => roomsProbe.hasSuccessFor(selectedBranch), { timeout: 25_000 }).toBe(true);
  return roomsProbe;
}

test.describe('UI smoke — production classroom management', () => {
  test.skip(
    !BASE || !DIRECTOR.account || !DIRECTOR.password,
    '未設定 SMOKE_BASE_URL / SMOKE_DIRECTOR_*（缺 director secrets）— 略過',
  );

  test('director can load classroom management without a JavaScript error', async ({ page }) => {
    // Sequential readiness waits (15s login + 3x25s) must fit inside the test cap.
    test.setTimeout(120_000);
    const errors = [];
    page.on('pageerror', (error) => errors.push(String(error)));
    page.on('response', (response) => {
      const path = new URL(response.url()).pathname;
      if (['/api/v1/auth/login', '/api/v1/me', '/api/v1/campuses', '/api/v1/rooms'].includes(path)) {
        // Keep failed-login evidence without printing credentials or bodies.
        console.log(`[classroom-smoke] ${path} HTTP ${response.status()}`);
      }
    });

    const roomsProbe = await loginAsDirector(page);
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

    await expect.poll(() => roomsProbe.pendingCount(), { timeout: 15_000 }).toBe(0);
    expect(roomsProbe.unauthorizedStatuses(), 'rooms API must not return 401/403, even before a later 200').toEqual([]);
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});
