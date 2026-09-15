// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.BINDING_HEALTH_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/binding-health-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const normalData = {
  overall: { bound_count: 82, student_total: 100 },
  weekly_summary: { bound: 7, unbound: 2, net: 5 },
  by_campus: [
    { campus_id: 1, campus_name: '大安分校', rate: 0.86, bound_count: 43, student_total: 50 },
    { campus_id: 2, campus_name: '新莊分校', rate: 0.78, bound_count: 39, student_total: 50 },
  ],
  trend: {
    day: [
      { date: '2026-09-01', bound: 72 },
      { date: '2026-09-02', bound: 75 },
      { date: '2026-09-03', bound: 77 },
      { date: '2026-09-04', bound: 80 },
      { date: '2026-09-05', bound: 82 },
    ],
    week: [
      { week: '2026-W35', bound: 70 },
      { week: '2026-W36', bound: 76 },
      { week: '2026-W37', bound: 82 },
    ],
  },
  unbound_active: [
    { student_id: 11, student_name: '林同學', campus_id: 1, campus_name: '大安分校' },
  ],
  anomalies: [
    { id: 101, type: '重複綁定', severity: 'warning', campus_id: 2, campus_name: '新莊分校', student_name: '陳同學', occurred_at: '2026-09-09T09:30:00Z' },
  ],
};

async function installMock(page) {
  await page.addInitScript(() => { window.__bindingHealthRetryAllowed = false; });
  await page.route('**/api/v1/bindings/metrics**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 500));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__bindingHealthRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '綁定健康資料暫時無法載入' }) });
    }
    if (mode === 'empty') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({}) });
    }
    const data = mode === 'long'
      ? {
        ...normalData,
        by_campus: [{ ...normalData.by_campus[0], campus_name: '這是一個很長的分校名稱用來驗證手機折行與數字仍然可讀' }],
        unbound_active: [{ ...normalData.unbound_active[0], student_name: '這是一個很長的學生姓名用來驗證行動版內容不會被截斷' }],
      }
      : normalData;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
  });
}

async function assertNoPageOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Binding health clarity browser verification', () => {
  test('keeps the dashboard readable and controls reachable at every target width', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/binding-health-pilot-mount.html?mode=normal');
      await expect(page.getByTestId('at-page-header').getByRole('heading', { name: '綁定健康度', exact: true })).toBeVisible();
      await expect(page.locator('.bhd-stats')).toBeVisible();
      await expect(page.locator('.bhd-line')).toBeVisible();
      await expect(page.getByRole('button', { name: '日', exact: true })).toHaveAttribute('aria-pressed', 'true');
      await expect(page.getByRole('button', { name: '週', exact: true })).toHaveAttribute('aria-pressed', 'false');
      const controls = await page.locator('button, input, select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
      }).map((node) => ({ height: node.getBoundingClientRect().height })));
      expect(controls.filter((control) => control.height < 44)).toEqual([]);
      await assertNoPageOverflow(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('supports keyboard granularity toggles and clear empty/loading/error recovery states', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/binding-health-pilot-mount.html?mode=normal');
    const week = page.getByRole('button', { name: '週', exact: true });
    await week.focus();
    await expect(week).toBeFocused();
    await week.press('Enter');
    await expect(week).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: '日', exact: true })).toHaveAttribute('aria-pressed', 'false');

    await page.goto('/binding-health-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await expect(page.getByRole('heading', { name: '綁定健康度', exact: true })).toBeVisible();

    await page.goto('/binding-health-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前沒有可顯示的綁定健康度資料', { exact: true })).toBeVisible();
    await expect(page.getByText('資料準備好後會顯示在這裡。請使用右上角重新整理。', { exact: true })).toBeVisible();

    await page.goto('/binding-health-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入綁定健康度');
    await page.evaluate(() => { window.__bindingHealthRetryAllowed = true; });
    await page.getByRole('button', { name: '重試', exact: true }).click();
    await expect(page.locator('.bhd-stats')).toBeVisible();
  });

  test('keeps long Chinese content reachable on mobile', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/binding-health-pilot-mount.html?mode=long');
    await expect(page.getByText('這是一個很長的分校名稱用來驗證手機折行與數字仍然可讀', { exact: true })).toBeVisible();
    await expect(page.getByText('這是一個很長的學生姓名用來驗證行動版內容不會被截斷', { exact: true })).toBeVisible();
    await assertNoPageOverflow(page);
  });
});
