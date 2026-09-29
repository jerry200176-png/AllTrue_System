// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.ASSESSMENT_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/assessment-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const assessments = [
  { id: 101, title: '英文基準檢測', assessment_type: 'baseline', student_name: '分校共用', scheduled_for: '2026-09-10', status: 'draft', result_count: 0, max_score: 100 },
  { id: 102, title: '八年級數學階段檢測', assessment_type: 'checkpoint', student_name: '林小明', scheduled_for: '2026-09-08', status: 'published', result_count: 1, max_score: 100 },
];

async function installMock(page) {
  let listErrors = 0;
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    const method = route.request().method();
    const body = (payload) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });

    if (url.pathname === '/api/v1/assessments' && method === 'GET') {
      if (mode === 'error' && listErrors++ === 0) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '檢測資料暫時無法載入' }) });
      if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 400));
      const data = mode === 'empty' ? [] : mode === 'long'
        ? [{ ...assessments[0], title: '測試學生超長學習檢測名稱驗證手機折行與操作仍可達' }]
        : assessments;
      return body({ data });
    }
    if (url.pathname === '/api/v1/assessment-reports/summary') return body({ data: { assessment_count: 2, result_count: 1, average_percent: 82, reviewed_count: 1, remediation_open_count: 1, remediation_overdue_count: 0 } });
    if (url.pathname === '/api/v1/assessment-options/classes') return body({ data: [{ id: 501, student_name: '林小明', subject: '數學' }] });
    if (url.pathname === '/api/v1/assessments/102/results') return body({ data: [{ id: 701, student_name: '林小明', attempt_no: 1, score: 82, max_score: 100, percent: 82, status: 'submitted', remediation_count: 1 }] });
    if (url.pathname === '/api/v1/assessments/102/students') return body({ data: [{ student_class_id: 501, student_id: 201, name: '林小明 · 數學' }] });
    if (url.pathname === '/api/v1/assessments/102/questions') return body({ data: [] });
    if (url.pathname === '/api/v1/assessments/102/attempts') return body({ data: [] });
    if (method !== 'GET') return body({ data: { id: 999, status: 'ok' } });
    return body({ data: [] });
  });
}

test.describe('Assessment clarity browser verification', () => {
  test('populated queue uses cards on mobile and a structured table on desktop', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(`/assessment-pilot-mount.html?mode=normal`);
      await expect(page.getByRole('heading', { name: '學習檢測' })).toBeVisible();
      await expect(page.getByRole('button', { name: '建立檢測' })).toBeVisible();
      await expect(page.getByText('英文基準檢測')).toHaveCount(2);
      if (viewport.width <= 640) {
        await expect(page.locator('.assessment-mobile-list')).toBeVisible();
        await expect(page.locator('.assessment-mobile-card')).toHaveCount(2);
        await expect(page.locator('.assessment-desktop-table')).toBeHidden();
      } else {
        await expect(page.locator('.assessment-desktop-table')).toBeVisible();
        await expect(page.locator('.assessment-mobile-list')).toBeHidden();
      }
      const controls = await page.locator('button, input, select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
      }).map((node) => ({ text: node.textContent?.trim(), width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height })));
      expect(controls.filter((control) => control.height < 44)).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('shared dialogs provide focus, Escape, and clear action hierarchy', async ({ page }) => {
    await installMock(page);
    await page.goto('/assessment-pilot-mount.html?mode=normal');
    await page.getByRole('button', { name: '建立檢測' }).click();
    const createDialog = page.getByRole('dialog', { name: '建立學習檢測' });
    await expect(createDialog).toBeVisible();
    await expect(createDialog).toBeFocused();
    await expect(createDialog.getByRole('button', { name: '建立' })).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(createDialog).toBeHidden();

    await page.locator('.assessment-desktop-table tr').filter({ hasText: '八年級數學階段檢測' }).getByRole('button', { name: '結果' }).click();
    const resultDialog = page.getByRole('dialog', { name: '八年級數學階段檢測' });
    await expect(resultDialog).toBeVisible();
    await expect(resultDialog).toContainText('滿分 100');
    await page.keyboard.press('Escape');
    await expect(resultDialog).toBeHidden();
  });

  test('loading, empty, recoverable error, and long Chinese states remain usable', async ({ page }) => {
    await installMock(page);
    await page.goto('/assessment-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await expect(page.locator('.assessment-mobile-card').first()).toContainText('英文基準檢測');

    await page.goto('/assessment-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前還沒有檢測', { exact: true })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/assessment-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入檢測');
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.assessment-mobile-card').first()).toContainText('英文基準檢測');

    await page.goto('/assessment-pilot-mount.html?mode=long');
    await expect(page.locator('.assessment-mobile-card').first()).toContainText('超長學習檢測名稱');
    await expect(page.getByRole('button', { name: '查看結果' }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: '發布' }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
  });
});
