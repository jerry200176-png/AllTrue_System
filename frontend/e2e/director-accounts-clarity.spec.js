// @ts-check
import { test, expect } from '@playwright/test';

async function installDirectorAccountsMocks(page, mode = 'long') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && (url.pathname === '/api/v1/directors/pending' || url.pathname === '/api/v1/directors')) {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: '主任帳號資料暫時無法載入' }),
        });
      }
      const active = url.pathname === '/api/v1/directors';
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
      }
      const data = active
        ? [{
            id: 9101,
            name: '林主任超長姓名用來驗證帳號管理在手機上仍然可讀且操作不會被推出視窗',
            account: 'director-with-a-long-login@example.test',
            campus_names: ['台北總校', '台中分校'],
            campus_ids: [1, 2],
          }]
        : [{
            id: 9201,
            name: '待審主任甲',
            email: 'pending-director@example.test',
            campus_name: '台北總校',
          }];
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    }
    if (request.method() === 'GET' && url.pathname === '/api/v1/campuses') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '台北總校' }, { id: 2, name: '台中分校' }]),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Director Accounts real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps account actions reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installDirectorAccountsMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=director-accounts&mode=long');

      await expect(page.getByText('主任管理')).toBeVisible();
      await expect(page.getByRole('button', { name: '編輯分校' })).toBeVisible();
      await expect(page.getByRole('button', { name: '通過' })).toBeVisible();
      if (viewport.name === '390') {
        await page.screenshot({ path: '/tmp/alltrue-director-accounts-before-20260909/vue-director-accounts-390.png', fullPage: true });
      }
      const buttons = page.locator('.director-accounts-page button');
      const dimensions = await buttons.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);

      await page.getByRole('button', { name: '編輯分校' }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog).toHaveAttribute('aria-modal', 'true');
      await expect(dialog).toHaveAttribute('aria-labelledby', 'campus-modal-title');
      await expect(dialog).toBeFocused();
      const dialogRect = await dialog.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
      });
      expect(dialogRect.left).toBeGreaterThanOrEqual(0);
      expect(dialogRect.top).toBeGreaterThanOrEqual(0);
      expect(dialogRect.right).toBeLessThanOrEqual(viewport.width);
      expect(dialogRect.bottom).toBeLessThanOrEqual(viewport.height);
      await expect(page.getByRole('button', { name: '取消' })).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(page.getByRole('button', { name: '編輯分校' })).toBeFocused();

      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-director-accounts-after-20260909/vue-director-accounts-long-${viewport.name}.png`, fullPage: true });
      }
    });
  }

  test('makes empty and loading states explicit', async ({ page }) => {
    await installDirectorAccountsMocks(page, 'empty');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=director-accounts&mode=empty');
    await expect(page.getByText('目前沒有已審核的主任')).toBeVisible();
    await expect(page.getByText('目前沒有待審申請')).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installDirectorAccountsMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status').first()).toContainText('載入中...');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('surfaces account loading errors with a retry action', async ({ page }) => {
    await installDirectorAccountsMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=director-accounts&mode=error');
    await expect(page.getByRole('alert').first()).toContainText('主任帳號資料暫時無法載入');
    await expect(page.getByRole('button', { name: '重新載入' }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });
});
