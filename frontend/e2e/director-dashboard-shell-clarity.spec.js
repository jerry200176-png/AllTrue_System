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

const PAYMENT_NAVIGATION_ALERTS = [
  {
    id: 6101, student_id: 9101, student_class_id: 8101, student_name: '合成應收學生', subject: '數學',
    payment_status: 'unpaid', alert_type: 'unpaid', charge: 4200, paid_amount: 0, outstanding: 4200,
    schedule_mode: 'date', course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 91001,
  },
  {
    id: 6102, student_id: 9102, student_class_id: 8102, student_name: '合成部分入帳學生', subject: '英文',
    payment_status: 'partial', alert_type: 'unpaid', charge: 5600, paid_amount: 2000, outstanding: 3600,
    schedule_mode: 'count', remaining_sessions: 4, sessions_purchased: 16, invoice_id: 91002,
  },
  {
    id: 6103, student_id: 9103, student_class_id: 8103, student_name: '合成已回報學生', subject: '自然',
    payment_status: 'pending_report', alert_type: 'unpaid', charge: 3800, paid_amount: 3800, outstanding: 0,
    schedule_mode: 'date', latest_payment_report_id: 96103, invoice_id: 91003,
  },
  {
    id: 6104, student_id: 9104, student_class_id: 8104, student_name: '合成結案待查學生', subject: '國文',
    payment_status: 'pending_reconciliation', alert_type: 'pending_reconciliation', charge: 3800, paid_amount: 3800, outstanding: 0,
    schedule_mode: 'date', latest_payment_report_id: 96104, invoice_id: 91004,
  },
  {
    id: 6105, student_id: 9105, student_class_id: 8105, student_name: '合成續課學生', subject: '物理',
    payment_status: 'renew_needed', alert_type: 'low_sessions', charge: 0, paid_amount: 0, outstanding: 0,
    schedule_mode: 'count', remaining_sessions: 1, sessions_purchased: 8, invoice_id: 91005,
  },
];

async function openPilot(page, { mode = 'normal', viewport, pageName = 'director', paymentAlerts = [] }) {
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
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mode === 'payment-navigation' ? paymentAlerts : []),
      });
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
    if (p.endsWith('/me')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 9001,
          name: 'E2E Director',
          role: 'director',
          campuses: [1],
          must_change_password: false,
          capabilities: [],
        }),
      });
      return;
    }
    if (p.endsWith('/branches')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '內湖分校', code: 'neihu' }]),
      });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto(`/pilot-mount.html?page=${pageName}&mode=${mode}`);
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

for (const viewport of [VIEWPORTS[0], VIEWPORTS[4]]) {
  test(`payment alert rows preserve App navigation context @${viewport.name}`, async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(`${message.text()} @ ${message.location().url}`);
    });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    await openPilot(page, { mode: 'payment-navigation', pageName: 'app', viewport, paymentAlerts: PAYMENT_NAVIGATION_ALERTS });

    const navigationCases = [
      { student: '合成應收學生', tab: '應收／尚未回報' },
      { student: '合成部分入帳學生', tab: '應收／尚未回報' },
      { student: '合成已回報學生', tab: '已回報／待查帳' },
      { student: '合成結案待查學生', tab: '結案／待查帳' },
      { student: '合成續課學生', tab: '續課/將到期' },
    ];

    for (const [index, navigationCase] of navigationCases.entries()) {
      if (index > 0) {
        await page.goto('/pilot-mount.html?page=app&mode=payment-navigation');
        await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
      }
      await expect(page.getByRole('heading', { name: '主任總覽', exact: true })).toBeVisible({ timeout: 10_000 });
      await page.getByRole('tab', { name: '完整營運', exact: true }).click();
      const paymentRow = page.locator('#payments-sec .director-payment-row').filter({ hasText: navigationCase.student });
      await expect(paymentRow).toBeVisible();
      await paymentRow.getByRole('button', { name: '前往帳務中心', exact: true }).click();

      await expect(page.getByRole('heading', { name: '帳務中心', exact: true })).toBeVisible();
      await expect(page.locator('.tc-tab--active')).toContainText(navigationCase.tab);
      await expect(page.locator('.tc-focus-context')).toContainText(navigationCase.student);
      await expect(page.locator('.tc-row--focused')).toHaveCount(1);
    }

    expect(consoleErrors, consoleErrors.join('\n')).toEqual([]);
    expect(failedRequests, failedRequests.join('\n')).toEqual([]);
    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  });
}
