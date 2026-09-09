// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';

const outDir = process.env.UI_FOUNDATION_SHOT_DIR || '/tmp/alltrue-tuition-report-after-20260909';
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

const baseRow = {
  student_class_id: 101,
  student_name: '測試學生甲',
  subject: '國中數學',
  teacher_name: '王老師',
  class_type: 'one_on_one',
  monthly_sessions: 4,
  rate: 1500,
  monthly_tuition: 6000,
  last_paid_at: '2026-09-01',
};

function rowsFor(mode) {
  if (mode === 'empty' || mode === 'error' || mode === 'loading') return [];
  if (mode === 'long') {
    return [{
      ...baseRow,
      student_name: '測試學生超長姓名用於驗證學收報表在手機上的折行與閱讀層級',
      subject: '高中英文進階閱讀與會考寫作總複習',
      teacher_name: '測試老師超長顯示名稱',
    }];
  }
  if (mode === 'dense') {
    return Array.from({ length: 6 }, (_, index) => ({
      ...baseRow,
      student_class_id: 101 + index,
      student_name: `測試學生${index + 1}`,
      subject: index % 2 ? '高中英文' : '國中數學',
      class_type: index % 2 ? 'one_on_two' : 'one_on_one',
      monthly_tuition: index % 2 ? 4800 : 6000,
    }));
  }
  return [baseRow];
}

function payloadFor(mode) {
  const rows = rowsFor(mode);
  return {
    data: rows,
    summary: {
      total_students: rows.length,
      total_sessions: rows.length * 4,
      total_tuition: rows.reduce((total, row) => total + row.monthly_tuition, 0),
    },
    meta: { current_page: 1, last_page: 1, per_page: 50, total: rows.length },
  };
}

async function installMocks(page, mode) {
  let release;
  const hang = new Promise((resolve) => { release = resolve; });
  const consoleErrors = [];
  const failedRequests = [];
  let reportAttempt = 0;
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`));

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname;
    if (!pathname.includes('/branch-monthly-tuition')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    if (pathname.endsWith('/export')) {
      return route.fulfill({ status: 200, contentType: 'text/csv', body: 'student_name,monthly_tuition\n測試學生甲,6000\n' });
    }
    reportAttempt += 1;
    if (mode === 'loading') await hang;
    if (mode === 'error' && reportAttempt === 1) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '學收報表暫時無法載入' }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payloadFor(mode)) });
  });
  return { release: () => release?.(), consoleErrors, failedRequests };
}

async function openPage(page, mode, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const diagnostics = await installMocks(page, mode);
  await page.goto(`/pilot-mount.html?page=tuition-report&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return diagnostics;
}

async function expectNoOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function expectTouchTargets(page) {
  const heights = await page.locator('.tuition-report-page button:visible').evaluateAll((buttons) => buttons.map((button) => Math.round(button.getBoundingClientRect().height)));
  expect(heights.length).toBeGreaterThan(0);
  expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);
}

test.describe('Tuition report clarity — real Director workflow', () => {
  test.describe.configure({ mode: 'serial' });

  for (const viewport of viewports) {
    test(`dense report and keyboard controls @${viewport.name}`, async ({ page }) => {
      const diagnostics = await openPage(page, 'dense', viewport);
      await expect(page.getByRole('heading', { name: '當月學收' })).toBeVisible();
      await expect(page.getByRole('button', { name: '上一月' })).toBeVisible();
      await expect(page.getByRole('button', { name: '下一月' })).toBeVisible();
      await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
      await page.getByRole('button', { name: '上一月' }).focus();
      await page.getByRole('button', { name: '上一月' }).press('Enter');
      await expect(page.getByRole('heading', { name: '當月學收' })).toBeVisible();
      await expect(page.locator('.tr-table tbody tr:visible')).toHaveCount(6);
      await expectTouchTargets(page);
      await expectNoOverflow(page);
      expect(diagnostics.consoleErrors).toEqual([]);
      expect(diagnostics.failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.locator('.tuition-report-page').screenshot({ path: path.join(outDir, `vue-tuition-report-normal-${viewport.name}.png`) });
      }
    });
  }

  test('empty state is announced', async ({ page }) => {
    const diagnostics = await openPage(page, 'empty', viewports[0]);
    await expect(page.getByText(/無有效課程紀錄/, { exact: false })).toBeVisible();
    await expect(page.locator('.tr-empty')).toHaveAttribute('role', 'status');
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('loading state is announced and releases', async ({ page }) => {
    const diagnostics = await openPage(page, 'loading', viewports[1]);
    await expect(page.locator('.tr-loading')).toBeVisible();
    await expect(page.locator('.tr-loading')).toHaveAttribute('role', 'status');
    diagnostics.release();
    await expect(page.getByText(/無有效課程紀錄/, { exact: false })).toBeVisible();
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('error state exposes retry and expected response only', async ({ page }) => {
    const diagnostics = await openPage(page, 'error', viewports[3]);
    await expect(page.getByRole('alert')).toContainText('載入失敗（503）');
    await expect(page.getByRole('button', { name: '再試一次' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors.filter((message) => !message.includes('status of 503'))).toEqual([]);
    expect(diagnostics.consoleErrors.filter((message) => message.includes('status of 503'))).toHaveLength(1);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('long Chinese content remains readable on mobile', async ({ page }) => {
    const diagnostics = await openPage(page, 'long', viewports[0]);
    await expect(page.locator('.tr-table tbody tr').filter({ hasText: '測試學生超長姓名' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });
});
