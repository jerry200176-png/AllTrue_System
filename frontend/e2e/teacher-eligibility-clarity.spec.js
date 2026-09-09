import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.TEACHER_ELIGIBILITY_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/teacher-eligibility-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const response = {
  teachers: [{
    teacher_id: 701,
    teacher_name: '王老師',
    overall_status: 'qualifies',
    review_required: false,
    settlement: {
      regular_subject_count: 4,
      tutoring_trial_subject_count: 2,
      payroll_subject_count: 6,
      one_to_three_count: 3,
      subject_count_bonus: 1200,
      one_to_three_bonus: 800,
      multiplier_pct: 105,
      weighted_bonus_amount: 2100,
      total_payout: 36000,
      payout_is_draft: false,
      adjustments: [{ label: '行政加給', amount: 500 }],
      multiplier_parts: [{ key: 'base', label: '基本', pct: 100 }],
    },
    components: { weekly_16_segments: { status: 'qualifies', metrics: { regular_segments: 18, trial_segments: 2, total_segments: 20, meets_16_segments: true, course_sessions: [] } }, subject_count_bonus: { status: 'qualifies', amount: 1200 } },
  }],
  period: { start: '2026-09-01', end: '2026-09-30' },
  policy_version: '115.07',
  branch_subject_total: 12,
  lock: { status: 'draft' },
};

async function installMock(page) {
  await page.addInitScript(() => { window.__teacherEligibilityRetryAllowed = false; });
  await page.route('**/api/v1/finance/teacher-eligibility**', async (route) => {
    const request = route.request();
    const url = request.url();
    if (request.method() !== 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    if (url.includes('/inputs')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ events: [], achievements: [], deductions: [], admin_allowances: [], cash_adjustments: [] }) });
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__teacherEligibilityRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '薪資要件資料暫時無法載入' }) });
    }
    if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...response, teachers: [] }) });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(response) });
  });
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, select, input, textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  expect(controls.filter((control) => control.height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Teacher eligibility clarity browser verification', () => {
  test('keeps salary review readable across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/teacher-eligibility-pilot-mount.html?mode=normal');
      await expect(page.getByText('正職薪資要件', { exact: true })).toBeVisible();
      await expect(page.locator('.teacher-card strong:visible, .desktop-table strong:visible').filter({ hasText: '王老師' })).toHaveCount(1);
      if (viewport.width <= 900) await expect(page.locator('.mobile-list')).toBeVisible();
      else await expect(page.locator('.desktop-table')).toBeVisible();
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('exposes loading, empty, error recovery, and search feedback', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher-eligibility-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await page.goto('/teacher-eligibility-pilot-mount.html?mode=empty');
    await expect(page.getByText('查詢期間沒有符合條件的正職老師', { exact: true })).toBeVisible();
    await page.goto('/teacher-eligibility-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入正職薪資要件');
    await page.evaluate(() => { window.__teacherEligibilityRetryAllowed = true; });
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.teacher-card strong:visible, .desktop-table strong:visible').filter({ hasText: '王老師' })).toHaveCount(1);
    await page.getByLabel('搜尋老師').fill('不存在');
    await expect(page.getByText('查詢期間沒有符合條件的正職老師', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps long Chinese labels usable on mobile', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/teacher-eligibility-pilot-mount.html?mode=normal');
    await page.getByLabel('搜尋老師').fill('王');
    await expect(page.locator('.teacher-card strong:visible, .desktop-table strong:visible').filter({ hasText: '王老師' })).toHaveCount(1);
    await expectNoOverflowAndReachableControls(page);
  });
});
