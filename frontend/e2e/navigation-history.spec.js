// @ts-check
import { test, expect } from '@playwright/test';

const localUrl = process.env.ONBOARDING_LOCAL_URL;
test.skip(!localUrl || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(localUrl), 'Requires local Vite');

test('teacher navigation survives browser back and reload', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
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

  await page.getByRole('button', { name: '我的課表', exact: true }).click();
  await expect(page).toHaveURL(/app_page=calendar/);
  await expect(page.getByText('我的課表', { exact: false }).first()).toBeVisible();

  await page.getByRole('button', { name: '出缺勤', exact: true }).click();
  await expect(page).toHaveURL(/app_page=attendance/);
  await expect(page.getByText('出缺勤', { exact: false }).first()).toBeVisible();

  await page.goBack();
  await expect(page).toHaveURL(/app_page=calendar/);
  await expect(page.getByText('我的課表', { exact: false }).first()).toBeVisible();

  await page.reload();
  await expect(page).toHaveURL(/app_page=calendar/);
  await expect(page.getByText('我的課表', { exact: false }).first()).toBeVisible();

  await page.goto(`${localUrl}?app_page=students`);
  await expect(page).toHaveURL(/app_page=teacher-home/);
  await expect(page.getByText('今日打卡狀態', { exact: false }).first()).toBeVisible();
});
