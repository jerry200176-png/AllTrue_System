// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.BRANCH_HEALTH_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/branch-health-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const dimensions = {
  students: { label: '穩定', status: 'green', source: '出席訊號', signals: [{ key: 'attendance', label: '近 28 天出席', value: '96%' }], period: 'rolling_28_days', next_step: '維持目前追蹤' },
  teaching: { label: '需注意', status: 'yellow', source: '評量訊號', signals: [{ key: 'pending', label: '待複核評量', value: '3 筆' }], period: 'rolling_28_days', next_step: '先看待複核評量' },
  parents: { label: '待接資料', status: 'unavailable', source: '尚未接入', signals: [], period: null, next_step: '等待家長訊號接入' },
  teachers: { label: '穩定', status: 'green', source: '教師訊號', signals: [{ key: 'active', label: '目前授課老師', value: '12 位' }], period: 'current', next_step: '維持目前追蹤' },
  operations: { label: '穩定', status: 'green', source: '營運訊號', signals: [{ key: 'health', label: '可驗證訊號', value: '4 / 5' }], period: 'current', next_step: '補接家長訊號' },
};

const rows = [
  { branch_id: 1, branch_name: '台北分校', status: 'yellow', headline: '教學訊號需要優先查看，其他已接入訊號穩定。', dimensions },
  { branch_id: 2, branch_name: '新莊分校', status: 'green', headline: '目前沒有紅色或黃色訊號。', dimensions: { ...dimensions, teaching: { ...dimensions.teaching, label: '穩定', status: 'green', next_step: '維持目前追蹤' } } },
];

async function installMock(page) {
  await page.addInitScript(() => { window.__branchHealthRetryAllowed = false; });
  await page.route('**/api/v1/admin/branch-health', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 500));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__branchHealthRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '分校健康資料暫時無法載入' }) });
    }
    const data = mode === 'empty' ? [] : mode === 'long'
      ? [{ ...rows[0], branch_name: '這是一個很長的分校名稱用來驗證手機折行與操作仍可達', headline: '這是一段較長的健康訊號說明，應該自然折行而且不會讓頁面產生水平溢出。' }]
      : rows;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, meta: { generated_at: '2026-09-09T12:00:00Z' } }) });
  });
}

test.describe('Branch health clarity browser verification', () => {
  test('uses cards on smaller screens and a structured table on desktop', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/branch-health-pilot-mount.html?mode=normal');
      await expect(page.getByTestId('at-page-header').getByRole('heading', { name: '分校健康', exact: true })).toBeVisible();
      if (viewport.width <= 900) {
        await expect(page.locator('.branch-health__mobile-list')).toBeVisible();
        await expect(page.locator('.branch-health__mobile-card')).toHaveCount(2);
        await expect(page.locator('.branch-health__table-wrap')).toBeHidden();
      } else {
        await expect(page.locator('.branch-health__table')).toBeVisible();
        await expect(page.locator('.branch-health__mobile-list')).toBeHidden();
      }
      const controls = await page.locator('button, input, select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
      }).map((node) => ({ height: node.getBoundingClientRect().height })));
      expect(controls.filter((control) => control.height < 44)).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('selects a branch and exposes loading, empty, and retryable error states', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/branch-health-pilot-mount.html?mode=normal');
    await page.getByRole('button', { name: '台北分校' }).last().click();
    await expect(page.getByRole('heading', { name: '台北分校 詳情' })).toBeVisible();
    await expect(page.locator('.branch-health__mobile-headline').filter({ hasText: '教學訊號需要優先查看' })).toBeVisible();

    await page.goto('/branch-health-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await expect(page.getByRole('heading', { name: '台北分校 詳情' })).toBeVisible();

    await page.goto('/branch-health-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前沒有啟用中的分校資料', { exact: true })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/branch-health-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入分校健康');
    await page.evaluate(() => { window.__branchHealthRetryAllowed = true; });
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.branch-health__mobile-card').first()).toContainText('台北分校');
  });

  test('long Chinese content remains reachable at mobile width', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/branch-health-pilot-mount.html?mode=long');
    await expect(page.locator('.branch-health__mobile-card').first()).toContainText('很長的分校名稱');
    await expect(page.getByRole('button', { name: /很長的分校名稱/ })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
  });
});
