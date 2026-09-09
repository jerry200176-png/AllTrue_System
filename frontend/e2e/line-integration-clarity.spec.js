// @ts-check
import { test, expect } from '@playwright/test';

const statusPayload = {
  campus_id: 1,
  campus_name: '台北總校超長名稱用來確認設定頁在手機上仍然清楚可讀',
  channel_configured: true,
  liff_configured: false,
  has_channel_token: true,
  has_channel_secret: true,
  liff_id_value: '',
  bound_count: 0,
  webhook_url: 'https://alltrue.example.test/api/v1/line/webhook/branch-with-long-identifier-1',
};

async function installLineMocks(page, mode = 'normal') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/line/status') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'LINE 狀態服務暫時無法使用' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(statusPayload) });
      return;
    }
    if (request.method() === 'POST' && url.pathname === '/api/v1/line/settings') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: { ...statusPayload, liff_configured: true, liff_id_value: '1234567890-AbCdEfGh' } }),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('LINE integration real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps settings actions reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installLineMocks(page);
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=line-integration');

      await expect(page.getByRole('heading', { name: '家長 LINE 通知設定' })).toBeVisible();
      await expect(page.getByText('台北總校超長名稱用來確認設定頁在手機上仍然清楚可讀')).toBeVisible();
      await expect(page.getByRole('button', { name: '儲存設定' })).toBeVisible();
      await expect(page.getByRole('button', { name: '複製' }).first()).toBeVisible();
      await expect(page.getByRole('button', { name: /建立 LINE 官方帳號/ })).toHaveAttribute('aria-expanded', 'true');

      const controls = page.locator('.li-page button:visible');
      const dimensions = await controls.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);

      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-line-integration-after-20260909/vue-line-integration-normal-${viewport.name}.png`, fullPage: true });
      }
    });
  }

  test('supports disclosure, copy, and save feedback', async ({ page }) => {
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    await installLineMocks(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=line-integration');

    const firstStep = page.getByRole('button', { name: /建立 LINE 官方帳號/ });
    await firstStep.click();
    await expect(firstStep).toHaveAttribute('aria-expanded', 'false');
    await firstStep.click();
    await expect(firstStep).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('button', { name: '複製' }).first().click();
    await expect(page.getByRole('button', { name: '✓ 已複製' })).toBeVisible();

    await page.getByLabel('手機開啟代碼').fill('1234567890-AbCdEfGh');
    await page.getByRole('button', { name: '儲存設定' }).click();
    await expect(page.getByRole('status')).toContainText('已儲存');
    await expect(page.getByLabel('手機開啟代碼')).toHaveValue('1234567890-AbCdEfGh');
  });

  test('makes loading and empty status states explicit', async ({ page }) => {
    await installLineMocks(page);
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=line-integration');
    await expect(page.getByText('已綁定家長')).toBeVisible();
    await expect(page.getByText('0 位')).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installLineMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status')).toContainText('載入中');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('surfaces controlled status errors without unexpected console or request failures', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    await installLineMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=line-integration');
    await expect(page.getByRole('alert')).toContainText('LINE 狀態服務暫時無法使用');
    await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
    // Chromium reports the intentionally mocked HTTP 503 as a resource error;
    // application-generated console errors remain forbidden.
    expect(consoleErrors.filter((message) => !message.includes('status of 503 (Service Unavailable)'))).toEqual([]);
    expect(failedRequests).toEqual([]);
  });
});
