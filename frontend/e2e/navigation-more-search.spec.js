// @ts-check
import { test, expect } from '@playwright/test';

const localUrl = process.env.ONBOARDING_LOCAL_URL;
test.skip(!localUrl || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(localUrl), 'Requires local Vite');

test('teacher can search and recover in the mobile More sheet', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'local-e2e-only',
      user: { id: 9002, role: 'teacher', name: '導覽測試' },
    }));
    localStorage.setItem('app_branch', '1');
  });
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    let data = { data: [] };
    if (path === '/api/v1/me') data = { id: 9002, name: '導覽測試', role: 'teacher', campuses: [1] };
    if (path.includes('/class-sessions')) data = { api_kind: 'projection', completeness: 'full', data: [], by_class: {} };
    if (path.includes('/branches')) data = { data: [{ id: 1, name: '測試分校' }] };
    return route.fulfill({ json: data });
  });

  await page.goto(localUrl);
  await expect(page.getByText('今日打卡狀態', { exact: false }).first()).toBeVisible();

  const moreTrigger = page.locator('#mobile-more-trigger');
  await moreTrigger.click();
  const sheet = page.locator('#mobile-more-sheet');
  const search = sheet.getByRole('searchbox', { name: '搜尋更多功能' });
  await expect(sheet).toBeVisible();
  await expect(search).toBeFocused();

  await search.fill('科目數統計');
  await expect(sheet.locator('.more-item')).toHaveText([/科目數統計/]);
  await expect(sheet.getByRole('button', { name: '內部聊天', exact: true })).toHaveCount(0);
  await sheet.locator('.more-item').filter({ hasText: '科目數統計' }).click();
  await expect(sheet).toHaveCount(0);
  await expect(page.locator('[data-guide="subject-units-header"]')).toBeVisible();

  await moreTrigger.click();
  await search.fill('不存在的功能');
  await expect(sheet.locator('.sidebar-more-empty')).toContainText('找不到符合');
  await sheet.locator('.sidebar-more-empty-reset').click();
  await expect(sheet.locator('.more-item').filter({ hasText: '科目數統計' })).toBeVisible();

  await search.fill('科目數統計');
  await search.press('Escape');
  await expect(sheet).toHaveCount(0);
  await expect(moreTrigger).toBeFocused();
});
