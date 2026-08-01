// @ts-check
import { test, expect } from '@playwright/test';

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = { account: process.env.SMOKE_DIRECTOR_USER, password: process.env.SMOKE_DIRECTOR_PASS };
const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

async function login(page) {
  await page.goto('/');
  await page.evaluate(() => localStorage.removeItem('alltrue.director_dashboard_view_mode.v1'));
  await page.locator('.role-btn', { hasText: '主任/櫃台' }).first().click();
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  await page.locator('button.login-btn').click();
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

for (const viewport of VIEWPORTS) {
  test(`director workbench ${viewport.name}px`, async ({ page }) => {
    test.skip(!BASE || !DIRECTOR.account, '未設定 director smoke secrets — 略過');
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const errors = [];
    const secondaryRequests = [];
    page.on('request', (request) => {
      if (/\/v1\/adoption\/(task-tracker|activity-log|weekly-metrics)/.test(request.url())) secondaryRequests.push(request.url());
    });
    page.on('pageerror', (error) => errors.push(String(error)));

    await login(page);
    await expect(page.getByText('今日待辦', { exact: true }).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('今日快照', { exact: true })).toBeVisible();

    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      taskCount: document.querySelectorAll('.workbench-task__cta').length,
      importVisible: Boolean(document.querySelector('.ac--import')),
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    expect(layout.importVisible).toBe(false);
    if (layout.taskCount > 0) {
      await expect(page.locator('.workbench-task__cta').first()).toBeVisible();
    }

    const fullView = page.getByRole('tab', { name: '完整營運', exact: true });
    await fullView.click();
    await expect.poll(() => secondaryRequests.length, { timeout: 10_000 }).toBeGreaterThan(0);
    await expect(page.locator('.work-grid')).toBeVisible();
    await expect(page.getByText('趨勢、操作紀錄與老師填寫率在完整營運中載入。', { exact: true })).toBeVisible();

    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
}
