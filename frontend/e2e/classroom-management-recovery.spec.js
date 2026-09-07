// @ts-check
import { test, expect } from '@playwright/test';

const localUrl = process.env.ONBOARDING_LOCAL_URL;
test.skip(!localUrl || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(localUrl), 'Requires local Vite');

test('director can recover classroom loading failure and use contextual row actions', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'local-e2e-only',
      user: { id: 9003, role: 'director', name: '教室測試' },
    }));
    localStorage.setItem('app_branch', '1');
    window.__allowClassroomRetry = false;
  });

  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/v1/rooms') {
      const retryAllowed = await page.evaluate(() => Boolean(window.__allowClassroomRetry));
      if (!retryAllowed) {
        await route.fulfill({ status: 503, json: { message: 'temporary failure' } });
        return;
      }
      await route.fulfill({ json: [{ id: 101, name: '201 教室', capacity: 12, memo: '', is_active: true }] });
      return;
    }
    if (path === '/api/v1/me') {
      await route.fulfill({ json: { id: 9003, name: '教室測試', role: 'director', campuses: [1] } });
      return;
    }
    if (path === '/api/v1/branches' || path === '/api/v1/campuses') {
      await route.fulfill({ json: [{ id: 1, name: '測試分校', code: 'test' }] });
      return;
    }
    await route.fulfill({ json: { data: [] } });
  });

  await page.goto(`${localUrl}?app_page=classroom`);
  await expect(page.getByRole('heading', { name: '教室管理' })).toBeVisible();

  const error = page.getByRole('alert');
  await expect(error).toContainText('教室清單暫時無法載入，請重試。');
  await expect(error.getByRole('button', { name: '重試' })).toBeVisible();

  await page.evaluate(() => { window.__allowClassroomRetry = true; });
  await error.getByRole('button', { name: '重試' }).click();

  await expect(page.locator('[data-guide="classroom-table"]')).toBeVisible();
  await expect(page.getByRole('columnheader', { name: '教室名稱' })).toHaveAttribute('scope', 'col');
  await expect(page.getByRole('button', { name: '編輯教室：201 教室' })).toBeVisible();
  await expect(page.getByRole('button', { name: '停用教室：201 教室' })).toBeVisible();
  await expect(page.getByRole('button', { name: '刪除教室：201 教室' })).toBeVisible();

  await page.getByRole('button', { name: '新增教室' }).click();
  await expect(page.getByRole('dialog', { name: '新增教室' })).toBeVisible();
  await expect(page.getByLabel('教室名稱')).toBeVisible();
  await expect(page.getByLabel('容量（人）')).toBeVisible();
  await page.getByRole('button', { name: '取消' }).click();
  await expect(page.getByRole('dialog', { name: '新增教室' })).toHaveCount(0);
});
