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
const shotDir = process.env.UI_FOUNDATION_SHOT_DIR || '/tmp/alltrue-director-dashboard-shell-20260909';

async function openPilot(page, { mode = 'normal', viewport }) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await page.addInitScript(({ mode: initialMode }) => {
    localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'e2e-foundation-token',
      token: 'e2e-foundation-token',
      user: { id: 9001, role: 'director', name: 'E2E Director', must_change_password: false },
    }));
    localStorage.setItem('app_branch', '1');
    localStorage.setItem('ui_foundation_mode', initialMode);
  }, { mode });

  let delayed = false;
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    if (route.request().method() !== 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    if (mode === 'loading' && !delayed) {
      delayed = true;
      await new Promise((resolve) => setTimeout(resolve, 700));
    }
    if (p.endsWith('/class-sessions')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: mode === 'empty' ? [] : [{ id: 901, student_class_id: 801, student_name: mode === 'long' ? '測試學生超長姓名驗證主任總覽折行內容' : '測試學生甲', teacher_name: '測試老師', start_time: '10:00', end_time: '11:00', status: 'scheduled', subject: '數學' }],
      }) });
      return;
    }
    if (p.includes('/learning-records')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mode === 'empty' ? [] : [{ id: 1001, status: 'pending', student_name: '測試學生甲' }] }) });
      return;
    }
    if (p.endsWith('/alerts/tuition')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) });
      return;
    }
    if (p.includes('/notifications')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], unread_count: 0 }) });
      return;
    }
    if (p.includes('/exception-workflows')) {
      const workflows = mode === 'empty' ? [] : [{
        id: 27,
        status: 'open',
        student: { name: mode === 'long' ? '測試學生超長姓名驗證主任工作佇列折行' : '測試學生甲' },
        class_session: { date: '2026-08-02', start_time: '10:00', end_time: '11:00' },
        payload: { reason: mode === 'long' ? '這是一段很長的請假原因，用來確認主任待辦動作不會被內容推到畫面外。' : '身體不適' },
        due_at: '2026-08-01T18:00:00+08:00',
      }];
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: workflows }) });
      return;
    }
    if (p.includes('/schedule-discrepancies/summary')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 }) });
      return;
    }
    if (p.includes('/attendance/ended-sessions')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ meta: { total: 0 } }) });
      return;
    }
    if (p.includes('/director/operations-trust')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: null }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto(`/pilot-mount.html?page=director&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
}

for (const viewport of VIEWPORTS) {
  test(`director dashboard shell @${viewport.name}`, async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    await openPilot(page, { viewport });

    await expect(page.getByRole('heading', { name: '主任總覽', exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('button', { name: '重新整理', exact: true })).toBeVisible();
    await expect(page.getByRole('tab', { name: '今天', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('button', { name: '開始處理', exact: true }).first()).toBeVisible();

    const controls = await page.locator('.director-workbench-v2 button:visible').evaluateAll((buttons) => buttons.map((button) => {
      const rect = button.getBoundingClientRect();
      return { label: button.getAttribute('aria-label') || button.textContent?.trim(), width: rect.width, height: rect.height };
    }));
    expect(controls.every((control) => control.width > 0 && control.height >= 44), JSON.stringify(controls)).toBeTruthy();

    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);

    await page.getByRole('button', { name: '開始處理', exact: true }).first().click();
    await expect(page.getByText('家長請假', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: '完整營運', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__full')).toBeVisible();
    await page.getByRole('tab', { name: '今天', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__focus')).toBeVisible();

    fs.mkdirSync(shotDir, { recursive: true });
    await page.locator('.director-workbench-v2').screenshot({ path: path.join(shotDir, `after-${viewport.name}.png`) });
    expect(consoleErrors, consoleErrors.join('\n')).toEqual([]);
    expect(failedRequests, failedRequests.join('\n')).toEqual([]);
  });
}

test('director dashboard shell exposes loading state', async ({ page }) => {
  await openPilot(page, { mode: 'loading', viewport: VIEWPORTS[0] });
  await expect(page.getByRole('button', { name: '重新整理', exact: true })).toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('.director-task-list[aria-label="正在載入今日待辦"]')).toBeVisible();
});

test('director dashboard shell exposes empty state without overflow', async ({ page }) => {
  await openPilot(page, { mode: 'empty', viewport: VIEWPORTS[1] });
  await expect(page.getByText('今天沒有需要主任處理的事', { exact: true })).toBeVisible({ timeout: 10_000 });
  await page.getByRole('tab', { name: '完整營運', exact: true }).click();
  await expect(page.locator('.director-workbench-v2__full')).toBeVisible();
  await expect(page.getByText('目前沒有待審核評量。', { exact: true })).toBeVisible();
  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
});
