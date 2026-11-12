// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
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
    { campus_id: 1, campus_name: '測試分校甲', rate: 0.86, bound_count: 43, student_total: 50 },
    { campus_id: 2, campus_name: '測試分校乙', rate: 0.78, bound_count: 39, student_total: 50 },
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
    { student_id: 11, student_name: '測試學生甲', campus_id: 1, campus_name: '測試分校甲' },
    { student_id: 12, student_name: '測試學生丙', campus_id: 2, campus_name: '測試分校乙' },
  ],
  anomalies: [
    { id: 101, type: '重複綁定', severity: 'warning', campus_id: 2, campus_name: '測試分校乙', student_name: '測試學生乙', occurred_at: '2026-09-09T09:30:00Z' },
  ],
};

async function installMock(page, requests = []) {
  await page.addInitScript(() => { window.__bindingHealthRetryAllowed = false; });
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    requests.push(`${route.request().method()} ${url.pathname}${url.search}`);
    if (url.pathname !== '/api/v1/bindings/metrics') return route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
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
      const root = page.getByTestId('binding-health-page');
      await expect(root.getByRole('heading', { name: '綁定健康度', exact: true, level: 2 })).toBeVisible();
      await expect(root.locator('h1, h2')).toHaveCount(1);
      // The page adds no main landmark of its own (the fixture's mount point is the only one).
      await expect(root.locator('main, [role="main"]')).toHaveCount(0);
      await expect(page.locator('.bhd-stats')).toBeVisible();
      await expect(page.locator('.bhd-line')).toBeVisible();
      await expect(page.getByRole('img', { name: '綁定趨勢折線圖', exact: true })).toBeVisible();
      await expect(page.getByRole('img', { name: '全校綁定率 82.0%', exact: true })).toBeVisible();
      await expect(page.getByRole('progressbar', { name: '測試分校甲綁定率 86%', exact: true })).toHaveAttribute('aria-valuenow', '86');
      // The chart keeps a readable height and stays inside its panel.
      const chart = await page.locator('.bhd-line').boundingBox();
      expect(chart.height).toBeGreaterThanOrEqual(120);
      expect(chart.x + chart.width).toBeLessThanOrEqual(viewport.width);
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
    const requests = [];
    await installMock(page, requests);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/binding-health-pilot-mount.html?mode=normal');
    const week = page.getByRole('button', { name: '週', exact: true });
    await week.focus();
    await expect(week).toBeFocused();
    await week.press('Enter');
    await expect(week).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: '日', exact: true })).toHaveAttribute('aria-pressed', 'false');

    await page.goto('/binding-health-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('binding-health-loading')).toBeVisible();
    await expect(page.getByTestId('binding-health-loading')).toHaveAttribute('role', 'status');
    await expect(page.locator('.bhd-stats')).toBeVisible();
    await expect(page.getByRole('heading', { name: '綁定健康度', exact: true })).toBeVisible();

    await page.goto('/binding-health-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前沒有可顯示的綁定健康度資料', { exact: true })).toBeVisible();
    await expect(page.getByText('資料準備好後會顯示在這裡。請使用右上角重新整理。', { exact: true })).toBeVisible();

    await page.goto('/binding-health-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入綁定健康度');
    await page.evaluate(() => { window.__bindingHealthRetryAllowed = true; });
    await page.getByRole('button', { name: '重試', exact: true }).click();
    await expect(page.locator('.bhd-stats')).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    // The page only ever reads the metrics (this fixture has no branch, so all campuses).
    expect([...new Set(requests)]).toEqual(['GET /api/v1/bindings/metrics']);
  });

  test('the two-column blocks fold to one column at 768 and below', async ({ page }) => {
    await installMock(page);
    for (const [width, columns] of [[767, 1], [768, 1], [769, 2]]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/binding-health-pilot-mount.html?mode=normal');
      await expect(page.locator('.bhd-grid')).toHaveCount(2);
      const counts = await page.locator('.bhd-grid').evaluateAll((grids) => grids.map((g) => getComputedStyle(g).gridTemplateColumns.split(' ').length));
      expect(counts).toEqual([columns, columns]);
      await assertNoPageOverflow(page);
    }
  });

  test('every 查看 has its own name and opens the student list on that student without a request', async ({ page }) => {
    const requests = [];
    await installMock(page, requests);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/binding-health-pilot-mount.html?mode=normal');
    await expect(page.getByRole('button', { name: '查看：測試學生甲', exact: true })).toBeVisible();
    const before = requests.length;
    await page.getByRole('button', { name: '查看：測試學生丙', exact: true }).click();
    await expect(page).toHaveURL(/#\/students$/);
    expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem('alltrue_binding_student_focus')))).toEqual({ studentId: 12, studentName: '測試學生丙' });
    expect(requests).toHaveLength(before);
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


for (const viewport of [{ name: 'phone', width: 390, height: 844 }, { name: 'desktop', width: 1280, height: 900 }]) {
  test(`binding health has no axe violations with data, by week, empty and in the error state @${viewport.name}`, async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const scan = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze())
      .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
    await page.goto('/binding-health-pilot-mount.html?mode=normal');
    await expect(page.locator('.bhd-stats')).toBeVisible();
    expect(await scan()).toEqual([]);
    await page.getByRole('button', { name: '週', exact: true }).click();
    await expect(page.getByRole('button', { name: '週', exact: true })).toHaveAttribute('aria-pressed', 'true');
    expect(await scan()).toEqual([]);
    await page.goto('/binding-health-pilot-mount.html?mode=empty');
    await expect(page.getByTestId('binding-health-empty')).toBeVisible();
    expect(await scan()).toEqual([]);
    await page.goto('/binding-health-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('綁定健康資料暫時無法載入');
    expect(await scan()).toEqual([]);
  });
}
