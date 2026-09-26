// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.BUG_REPORTS_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/bug-reports-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const normalBug = {
  id: 101,
  title: '課程頁面在手機上顯示不完整',
  description: '在窄螢幕查看課程時，內容仍應自然折行且主要操作保持可達。',
  status: 'in_progress',
  severity: 'medium',
  reporter_name: '王老師',
  page_key: 'courses',
  created_at: '2026-09-09T09:00:00Z',
  updated_at: '2026-09-09T10:00:00Z',
  attachments_count: 0,
  comments_count: 1,
};

const longBug = {
  ...normalBug,
  title: '這是一個很長的意見與建議標題，用來確認手機寬度下內容會自然折行並且不會讓主要操作失去可達性',
  description: '這是一段較長的中文問題描述，用來驗證意見與建議列表與詳情在手機寬度下能夠自然折行、保持清楚層級、保留主要操作，並且不會造成頁面水平溢出或任何文字被裁切。',
};

const detail = {
  ...normalBug,
  comments: [{ id: 1, author_name: '處理人員', created_at: '2026-09-09T10:00:00Z', body: '已確認並安排處理。', is_internal_note: false }],
  attachments: [],
  status_logs: [],
};

async function installMock(page) {
  await page.addInitScript(() => { window.__bugsRetryAllowed = false; });
  await page.route('**/api/v1/bugs**', async (route) => {
    const requestUrl = new URL(route.request().url());
    const currentMode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (requestUrl.pathname.endsWith('/101')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detail) });
    }
    if (currentMode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (currentMode === 'error' && !await page.evaluate(() => Boolean(window.__bugsRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '意見與建議資料暫時無法載入' }) });
    }
    const rows = currentMode === 'empty' ? [] : [currentMode === 'long' ? longBug : normalBug];
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: rows, total: rows.length, current_page: 1, last_page: 1 }),
    });
  });
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, input, select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  expect(controls.filter((control) => control.height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Bug reports clarity browser verification', () => {
  test('keeps the phone feedback dialog actions above persistent bottom navigation', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/bug-reports-pilot-mount.html?mode=launcher');
    await page.getByRole('button', { name: '提供意見與建議' }).click();

    const overlay = page.locator('.at-dialog-overlay');
    const nav = page.locator('.mobile-bottom-nav');
    const submit = page.getByRole('button', { name: '送出意見' });
    await expect(overlay).toBeVisible();
    await expect(submit).toBeVisible();

    const layers = await page.evaluate(() => {
      const overlay = document.querySelector('.at-dialog-overlay');
      const nav = document.querySelector('.mobile-bottom-nav');
      const submit = [...document.querySelectorAll('button')].find((node) => node.textContent?.trim() === '送出意見');
      if (!overlay || !nav || !submit) throw new Error('feedback dialog fixture is incomplete');
      const rect = submit.getBoundingClientRect();
      const top = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
      return {
        overlayZ: Number(getComputedStyle(overlay).zIndex),
        navZ: Number(getComputedStyle(nav).zIndex),
        submitReceivesPointer: top === submit || Boolean(top?.closest('button') === submit),
      };
    });
    expect(layers.overlayZ).toBeGreaterThan(layers.navZ);
    expect(layers.submitReceivesPointer).toBe(true);
  });

  test('keeps one clear reporter workflow across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/bug-reports-pilot-mount.html?mode=normal');
      await expect(page.getByTestId('at-page-header').getByRole('heading', { name: '我的意見與建議', exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: /全部/ }).first()).toHaveAttribute('aria-pressed', 'true');
      await expect(page.locator('.bug-item')).toHaveCount(1);
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('provides keyboard-friendly filters and explicit loading, empty, error, and detail states', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/bug-reports-pilot-mount.html?mode=normal');
    const pendingFilter = page.getByRole('button', { name: '處理中', exact: true });
    await pendingFilter.focus();
    await page.keyboard.press('Enter');
    await expect(pendingFilter).toHaveAttribute('aria-pressed', 'true');

    await page.goto('/bug-reports-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: '我的意見與建議', exact: true })).toBeVisible();

    await page.goto('/bug-reports-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前沒有意見與建議', { exact: true })).toBeVisible();

    await page.goto('/bug-reports-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入意見與建議');
    await page.evaluate(() => { window.__bugsRetryAllowed = true; });
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.bug-item')).toHaveCount(1);

    await page.locator('.bug-item').click();
    await expect(page.locator('.detail-card h3')).toContainText('課程頁面在手機上顯示不完整');
    await expect(page.getByRole('button', { name: '關閉意見與建議詳情' })).toBeVisible();
    await page.getByRole('button', { name: '關閉意見與建議詳情' }).click();
    await expect(page.locator('.detail-card')).toHaveCount(0);

    await page.goto('/bug-reports-pilot-mount.html?mode=no-branch');
    await expect(page.getByText('請先選擇分校', { exact: true })).toBeVisible();
  });

  test('keeps long Chinese content readable at mobile width', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/bug-reports-pilot-mount.html?mode=long');
    await expect(page.locator('.bug-item')).toContainText('很長的意見與建議標題');
    await expectNoOverflowAndReachableControls(page);
    expect(await page.locator('.bug-item').evaluate((node) => node.getBoundingClientRect().height)).toBeGreaterThan(44);
  });
});
