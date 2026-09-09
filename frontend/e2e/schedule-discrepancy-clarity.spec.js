// @ts-check
import { test, expect } from '@playwright/test';

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

function rowsFor(mode) {
  if (mode === 'empty' || mode === 'error' || mode === 'loading') return [];
  const row = {
    id: 6101,
    reporter_name: '測試老師',
    branch_name: '測試分校',
    student_name: mode === 'long' ? '測試學生超長姓名用於驗證回報卡片自然折行與操作可達性' : '測試學生甲',
    session_date: '2026-09-09',
    time_range: '10:00–12:00',
    corrected_time_range: '11:00–13:00',
    discrepancy_type_label: '時段不符',
    status: 'pending',
    notes: mode === 'long'
      ? '這是一段很長的老師回報備註，用來確認資訊自然折行，且處理動作不會被推到畫面外。'.repeat(2)
      : '請確認調課後的時段。',
  };
  if (mode !== 'dense') return [row];
  return [row, { ...row, id: 6102, student_name: '測試學生乙', discrepancy_type_label: '學生名單有誤', corrected_time_range: '', notes: '請核對現場學生。' }];
}

async function installMocks(page, mode) {
  let release;
  const hang = new Promise((resolve) => { release = resolve; });
  const consoleErrors = [];
  const failedRequests = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`));

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname;
    if (route.request().method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    if (pathname.endsWith('/schedule-discrepancies/summary')) {
      if (mode === 'loading') await hang;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ pending: rowsFor(mode).length, acknowledged: 0, resolved: 0, withdrawn: 0 }) });
    }
    if (pathname.endsWith('/schedule-discrepancies')) {
      if (mode === 'loading') await hang;
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '課表回報暫時無法載入' }) });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: rowsFor(mode) }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });
  return { release: () => release?.(), consoleErrors, failedRequests };
}

async function openPage(page, mode, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const diagnostics = await installMocks(page, mode);
  await page.goto(`/pilot-mount.html?page=discrepancy&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return diagnostics;
}

async function expectNoOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function expectTouchTargets(page) {
  const heights = await page.locator('.sdp-page button:visible').evaluateAll((buttons) => buttons.map((button) => Math.round(button.getBoundingClientRect().height)));
  expect(heights.length).toBeGreaterThan(0);
  expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);
}

test.describe('Schedule discrepancy clarity — real Director workflow', () => {
  test.describe.configure({ mode: 'serial' });

  for (const viewport of viewports) {
    test(`dense exception queue and keyboard action @${viewport.name}`, async ({ page }) => {
      const diagnostics = await openPage(page, 'dense', viewport);
      const tab = page.getByRole('tab', { name: /待處理/ });
      await expect(tab).toHaveAttribute('aria-selected', 'true');
      await expect(tab).toHaveAttribute('aria-controls', 'sdp-panel-pending');
      await expect(page.locator('#sdp-panel-pending')).toHaveAttribute('aria-labelledby', 'sdp-tab-pending');
      await tab.focus();
      await tab.press('Enter');
      await expect(page.getByRole('button', { name: '接手處理', exact: true }).first()).toBeVisible();
      await page.getByRole('button', { name: '接手處理', exact: true }).first().click();
      await expect(page.locator('.sdp-detail:visible, .sdp-resolve-form:visible').first()).toBeVisible();
      await expectTouchTargets(page);
      await expectNoOverflow(page);
      expect(diagnostics.consoleErrors).toEqual([]);
      expect(diagnostics.failedRequests).toEqual([]);
    });
  }

  test('empty state is announced and actionable', async ({ page }) => {
    const diagnostics = await openPage(page, 'empty', viewports[0]);
    await expect(page.getByText('目前沒有待處理的回報', { exact: true })).toBeVisible();
    await expect(page.locator('.sdp-state-empty')).toHaveAttribute('role', 'status');
    await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('loading state is announced and can release', async ({ page }) => {
    const diagnostics = await openPage(page, 'loading', viewports[1]);
    await expect(page.getByText('載入中…', { exact: true })).toBeVisible();
    await expect(page.locator('.sdp-state-loading')).toHaveAttribute('role', 'status');
    await diagnostics.release();
    await expect(page.getByText('目前沒有待處理的回報', { exact: true })).toBeVisible();
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('error state exposes retry and reports only the expected response', async ({ page }) => {
    const diagnostics = await openPage(page, 'error', viewports[3]);
    await expect(page.getByRole('alert')).toContainText('課表回報暫時無法載入');
    await expect(page.getByRole('button', { name: '重試' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors.filter((message) => !message.includes('status of 500'))).toEqual([]);
    expect(diagnostics.consoleErrors.filter((message) => message.includes('status of 500'))).toHaveLength(1);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('long Chinese content remains readable on mobile', async ({ page }) => {
    const diagnostics = await openPage(page, 'long', viewports[0]);
    await expect(page.locator('.sdp-mcard').filter({ hasText: '測試學生超長姓名' })).toBeVisible();
    await page.getByRole('button', { name: '接手處理', exact: true }).click();
    await expect(page.getByPlaceholder('至少 10 字')).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });
});
