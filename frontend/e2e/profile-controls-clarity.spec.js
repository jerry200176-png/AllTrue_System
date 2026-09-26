// @ts-check
import { test, expect } from '@playwright/test';

async function installProfileMocks(page, mode = 'profile') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/me' && request.method() === 'GET') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: '個人資料暫時無法載入' }),
        });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 9001,
          role: 'director',
          name: mode === 'long' ? '測試主任超長姓名用來驗證個人資料表單在手機上仍然可讀且不會推出視窗' : '測試主任',
          email: mode === 'long' ? 'director-with-a-long-login@example.test' : 'director@example.test',
          phone: '0912345678',
          notification_preferences: {
            in_app_enabled: true,
            email_enabled: false,
            line_enabled: false,
            quiet_hours_start: '22:00',
            quiet_hours_end: '08:00',
            event_tuition: true,
            event_learning_review: true,
            event_attendance: true,
            event_system: true,
          },
          security_summary: { recent_logins: [], active_sessions: [] },
        }),
      });
    }
    if (url.pathname.endsWith('/notification-preferences') && request.method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Profile controls real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps profile controls reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installProfileMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=profile&mode=long');

      await expect(page.getByText('個人資料管理')).toBeVisible();
      const tabs = page.locator('.profile-tabs [role="tab"]');
      await expect(tabs).toHaveCount(3);
      await expect(page.locator('#profile-panel-profile')).toHaveAttribute('role', 'tabpanel');
      await expect(page.getByRole('button', { name: '儲存基本資料' })).toBeVisible();

      const profileControls = page.locator('#profile-panel-profile input:not([type="file"]):not([type="checkbox"]), #profile-panel-profile .actions button, .profile-tabs [role="tab"]');
      const dimensions = await profileControls.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({
          path: `/tmp/alltrue-profile-after-20260909/vue-profile-long-profile-${viewport.name}.png`,
          fullPage: true,
        });
      }

      await page.getByRole('tab', { name: '安全性' }).click();
      await expect(page.locator('#profile-panel-security')).toBeVisible();
      await expect(page.locator('#profile-panel-security')).toHaveAttribute('aria-labelledby', 'profile-tab-security');
      await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
      await expect(page.getByText('目前尚無登入紀錄。')).toBeVisible();

      await page.getByRole('tab', { name: '通知偏好' }).click();
      await expect(page.locator('#profile-panel-notifications')).toBeVisible();
      await expect(page.getByRole('button', { name: '儲存通知偏好' })).toBeVisible();
      await expect(page.locator('#profile-panel-notifications .switch-row')).toHaveCount(7);
      const switchRowsMeetTouchFloor = await page.locator('#profile-panel-notifications .switch-row').evaluateAll((rows) => rows.every((row) => row.getBoundingClientRect().height >= 44));
      expect(switchRowsMeetTouchFloor).toBe(true);

      const activeTab = page.getByRole('tab', { name: '通知偏好' });
      await activeTab.focus();
      await expect(activeTab).toBeFocused();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      await page.screenshot({
        path: `/tmp/alltrue-profile-after-20260909/vue-profile-long-${viewport.name}.png`,
        fullPage: true,
      });
    });
  }

  test('keeps profile loading state explicit', async ({ page }) => {
    await installProfileMocks(page, 'loading');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=profile&mode=loading');
    await expect(page.getByRole('status')).toContainText('載入中...');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
    await page.screenshot({ path: '/tmp/alltrue-profile-after-20260909/vue-profile-loading-390.png', fullPage: true });
  });

  test('surfaces profile load errors without a blank workspace', async ({ page }) => {
    await installProfileMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=profile&mode=error');
    await expect(page.getByText('個人資料暫時無法載入')).toBeVisible();
    await expect(page.getByRole('tab', { name: '基本資料' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });
});
