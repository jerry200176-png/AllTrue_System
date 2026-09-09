// @ts-check
import { test, expect } from '@playwright/test';

const campuses = [
  { id: 4101, name: '台北總校超長分校名稱用來驗證手機版資訊仍然可讀', code: 'taipei-main', SwipeWindowMinutes: 30, active: true, Token: 'token-a', LineNotifyID: '', TelegramToken: '', TelegramChatID: '' },
  { id: 4102, name: '台中分校', code: 'taichung', SwipeWindowMinutes: 45, active: false, Token: '', LineNotifyID: '', TelegramToken: '', TelegramChatID: '' },
];

async function installBranchMocks(page, mode = 'long') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/admin/campuses') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '分校資料暫時無法載入' }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mode === 'empty' ? [] : campuses) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Branch Management real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps branch controls reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installBranchMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=branch-management&mode=long');

      await expect(page.getByText('分校管理')).toBeVisible();
      await expect(page.getByRole('button', { name: '新增分校' })).toBeVisible();
      await expect(page.getByRole('button', { name: '編輯' }).first()).toBeVisible();
      await expect(page.getByRole('button', { name: '刪除' }).first()).toBeVisible();
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-branch-management-after-20260909/vue-branch-management-normal-${viewport.name}.png`, fullPage: true });
      }
      const controls = page.locator('.branch-mgmt button:visible');
      const dimensions = await controls.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);

      const editTrigger = page.getByRole('button', { name: '編輯' }).first();
      await editTrigger.click();
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog).toHaveAttribute('aria-modal', 'true');
      await expect(dialog).toBeFocused();
      const dialogRect = await dialog.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
      });
      expect(dialogRect.left).toBeGreaterThanOrEqual(0);
      expect(dialogRect.top).toBeGreaterThanOrEqual(0);
      expect(dialogRect.right).toBeLessThanOrEqual(viewport.width);
      expect(dialogRect.bottom).toBeLessThanOrEqual(viewport.height);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(editTrigger).toBeFocused();

      await page.getByRole('button', { name: '刪除' }).first().click();
      await expect(page.getByRole('dialog')).toContainText('確認刪除');
      await page.getByRole('dialog').getByRole('button', { name: '取消' }).click();
      await expect(page.getByRole('dialog')).toBeHidden();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
    });
  }

  test('makes branch empty and loading states explicit', async ({ page }) => {
    await installBranchMocks(page, 'empty');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=branch-management&mode=empty');
    await expect(page.getByText('尚無分校資料')).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installBranchMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status')).toContainText('載入中');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('surfaces branch load errors with retry', async ({ page }) => {
    await installBranchMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=branch-management&mode=error');
    await expect(page.getByRole('alert')).toContainText('分校資料暫時無法載入');
    await expect(page.getByRole('button', { name: '重新載入' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });
});
