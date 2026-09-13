// @ts-check
import { test, expect } from '@playwright/test';

const status = {
  campus_id: 1,
  campus_name: '測試分校',
  channel_configured: true,
  liff_configured: false,
  has_channel_token: true,
  has_channel_secret: true,
  liff_id_value: '',
  bound_count: 0,
  webhook_url: 'https://alltrue.example.test/api/v1/line/webhook/1',
};

async function installMocks(page, savedBodies) {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/line/status') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(status) });
      return;
    }
    if (request.method() === 'POST' && url.pathname === '/api/v1/line/settings') {
      savedBodies.push(JSON.parse(request.postData() || '{}'));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: { ...status, liff_configured: true, liff_id_value: '1234567890-AbCdEfGh' } }),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test('keeps LINE setup controls usable on mobile and keyboard disclosures explicit', async ({ page }) => {
  const savedBodies = [];
  await installMocks(page, savedBodies);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/pilot-mount.html?page=line-integration');

  await expect(page.getByLabel('頻道授權碼')).toHaveAttribute('type', 'password');
  await page.getByRole('button', { name: '顯示' }).first().click();
  await expect(page.getByLabel('頻道授權碼')).toHaveAttribute('type', 'text');

  const firstStep = page.getByRole('button', { name: /建立 LINE 官方帳號/ });
  await expect(firstStep).toHaveAttribute('aria-expanded', 'true');
  await firstStep.click();
  await expect(firstStep).toHaveAttribute('aria-expanded', 'false');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
    await page.evaluate(() => document.documentElement.clientWidth),
  );
  expect(savedBodies).toEqual([]);
});

test('retains the LINE settings API payload while presenting save feedback accessibly', async ({ page }) => {
  const savedBodies = [];
  await installMocks(page, savedBodies);
  await page.goto('/pilot-mount.html?page=line-integration');

  await page.getByLabel('頻道授權碼').fill(' token ');
  await page.getByLabel('頻道密鑰').fill(' secret ');
  await page.getByLabel('手機開啟代碼').fill('1234567890-AbCdEfGh');
  await page.getByRole('button', { name: '儲存設定' }).click();

  await expect(page.getByRole('status')).toContainText('已儲存');
  expect(savedBodies).toEqual([{
    branch_id: 1,
    messaging_channel_token: 'token',
    messaging_channel_secret: 'secret',
    liff_id: '1234567890-AbCdEfGh',
  }]);
});
