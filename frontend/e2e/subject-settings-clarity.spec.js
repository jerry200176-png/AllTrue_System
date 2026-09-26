// @ts-check
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];
const shotDir = process.env.UI_FOUNDATION_SHOT_DIR || '/tmp/alltrue-subject-settings-after-20260909';

const subjects = (mode) => mode === 'long'
  ? [
      { id: 1, name: '英文進階長內容驗證科目名稱', campus_id: null },
      { id: 2, name: '分校專屬數學', campus_id: 1 },
    ]
  : [
      { id: 1, name: '英文', campus_id: null },
      { id: 2, name: '分校數學', campus_id: 1 },
    ];

async function openPilot(page, { mode = 'normal', viewport }) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'e2e-foundation-token',
      token: 'e2e-foundation-token',
      user: { id: 9001, role: 'director', name: 'E2E Director', must_change_password: false },
    }));
    localStorage.setItem('app_branch', '1');
  });

  let attempts = 0;
  let delayed = false;
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    if (p === '/api/v1/subjects' && route.request().method() === 'GET') {
      attempts += 1;
      if (mode === 'loading' && !delayed) {
        delayed = true;
        await new Promise((resolve) => setTimeout(resolve, 700));
      }
      if (mode === 'error' && attempts === 1) {
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '科目服務暫時無法載入' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mode === 'empty' ? [] : subjects(mode)) });
      return;
    }
    if (route.request().method() !== 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto(`/pilot-mount.html?page=subject-settings&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
}

for (const viewport of VIEWPORTS) {
  test(`subject settings shell @${viewport.name}`, async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    await openPilot(page, { viewport });

    await expect(page.getByRole('heading', { name: '科目管理', exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('button', { name: '新增科目', exact: true })).toBeVisible();
    await expect(page.getByText('英文', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '更名', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: '刪除', exact: true }).first()).toBeDisabled();
    await expect(page.getByRole('button', { name: '刪除', exact: true }).nth(1)).toBeEnabled();

    const controls = await page.locator('.subject-settings-page button:visible').evaluateAll((buttons) => buttons.map((button) => {
      const rect = button.getBoundingClientRect();
      return { label: button.textContent?.trim(), width: rect.width, height: rect.height };
    }));
    expect(controls.every((control) => control.width > 0 && control.height >= 44), JSON.stringify(controls)).toBeTruthy();
    const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);

    await page.getByRole('button', { name: '更名', exact: true }).first().click();
    const dialog = page.getByRole('dialog', { name: '科目更名', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog).toBeFocused();
    await expect(dialog.locator('#subject-name')).toHaveAttribute('aria-describedby', 'subject-name-hint');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await page.getByRole('button', { name: '新增科目', exact: true }).click();
    await expect(page.getByRole('dialog', { name: '新增科目', exact: true })).toBeVisible();
    await page.getByRole('dialog', { name: '新增科目', exact: true }).getByRole('button', { name: '取消', exact: true }).click();
    await expect(page.getByRole('dialog', { name: '新增科目', exact: true })).toBeHidden();

    fs.mkdirSync(shotDir, { recursive: true });
    await page.locator('.subject-settings-page').screenshot({ path: path.join(shotDir, `after-${viewport.name}.png`) });
    expect(consoleErrors, consoleErrors.join('\n')).toEqual([]);
    expect(failedRequests, failedRequests.join('\n')).toEqual([]);
  });
}

test('subject settings exposes loading state', async ({ page }) => {
  await openPilot(page, { mode: 'loading', viewport: VIEWPORTS[0] });
  await expect(page.locator('.subject-settings-page .hint[role="status"]')).toBeVisible();
  await expect(page.getByText('英文', { exact: true })).toBeVisible({ timeout: 10_000 });
});

test('subject settings exposes empty state', async ({ page }) => {
  await openPilot(page, { mode: 'empty', viewport: VIEWPORTS[1] });
  await expect(page.getByText('目前沒有科目資料', { exact: true })).toBeVisible({ timeout: 10_000 });
});

test('subject settings exposes recoverable error state', async ({ page }) => {
  await openPilot(page, { mode: 'error', viewport: VIEWPORTS[2] });
  await expect(page.getByRole('alert')).toContainText('無法載入科目列表');
  await page.getByRole('button', { name: '重試', exact: true }).click();
  await expect(page.getByText('英文', { exact: true })).toBeVisible({ timeout: 10_000 });
  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
});

test('subject settings keeps long Chinese names inside the mobile card layout', async ({ page }) => {
  await openPilot(page, { mode: 'long', viewport: VIEWPORTS[0] });
  await expect(page.getByText('英文進階長內容驗證科目名稱', { exact: true })).toBeVisible({ timeout: 10_000 });
  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  await expect(page.getByRole('button', { name: '更名', exact: true }).first()).toBeEnabled();
});
