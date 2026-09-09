import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.PARTTIME_PAYROLL_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/parttime-payroll-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const teacher = {
  teacher_id: 701,
  teacher_name: '王老師',
  total_hours: 24,
  high_hours: 8,
  junior_hours: 6,
  elementary_hours: 10,
  tutoring_hours: 0,
  total_salary: 18500,
  session_count: 12,
  rule_source: 'branch_default',
};

const longTeacher = {
  ...teacher,
  teacher_name: '這是一個很長的兼職老師姓名用來驗證手機折行與展開操作仍然可達',
};

const summary = {
  total_salary: 18500,
  total_hours: 24,
  teacher_count: 1,
  avg_hourly_rate: 770,
  lock_status: 'draft',
  anomaly_count: 0,
};

const sessions = {
  sessions: [{
    learning_record_id: 9001,
    session_date: '2026-09-10',
    student_name: '測試學生甲',
    subject: '數學',
    level_key: 'junior',
    level_label: '國中',
    class_type: 'one_on_one',
    hours: 2,
    base_rate: 700,
    headcount_bonus: 0,
    effective_rate: 700,
    concurrency_bonus_amount: 0,
    session_salary: 1400,
  }],
  teacher: { total_salary: 18500, session_count: 12 },
  meta: { current_page: 1, last_page: 1 },
};

async function installMock(page) {
  await page.addInitScript(() => { window.__parttimePayrollRetryAllowed = false; });
  await page.route('**/api/v1/finance/parttime-payroll**', async (route) => {
    const request = route.request();
    const url = request.url();
    if (request.method() !== 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (url.includes('/sessions')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(sessions) });
    if (url.includes('/rules')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ base_rates: { high: 700, junior: 600, elementary: 500, tutoring: 400 }, headcount_bonus: 50, defaults: { base_rates: { high: 700, junior: 600, elementary: 500, tutoring: 400 }, headcount_bonus: 50 } }) });
    if (url.includes('/teacher-rules')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ use_branch_default: true, base_rates: { high: 700, junior: 600, elementary: 500, tutoring: 400 }, headcount_bonus: 50, history: [] }) });
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__parttimePayrollRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: '兼職薪資資料暫時無法載入' }) });
    }
    if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ summary, teachers: [] }) });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ summary, teachers: mode === 'long' ? [longTeacher] : [teacher] }) });
  });
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, input, textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  expect(controls.filter((control) => control.height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Part-time payroll clarity browser verification', () => {
  test('keeps payroll review readable across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/parttime-payroll-pilot-mount.html?mode=normal');
      await expect(page.getByText('兼職老師薪資', { exact: true })).toBeVisible();
      await expect(page.locator('.teacher-row:visible')).toHaveCount(1);
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('exposes loading, empty, error recovery, sort, and keyboard expansion', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/parttime-payroll-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await page.goto('/parttime-payroll-pilot-mount.html?mode=empty');
    await expect(page.getByText('本月無授課紀錄', { exact: true })).toBeVisible();
    await page.goto('/parttime-payroll-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入兼職薪資');
    await page.evaluate(() => { window.__parttimePayrollRetryAllowed = true; });
    await page.getByRole('button', { name: '重試' }).click();
    const row = page.locator('.teacher-row:visible');
    await row.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.session-detail:visible')).toBeVisible();
    await expect(row).toHaveAttribute('aria-expanded', 'true');
    await expectNoOverflowAndReachableControls(page);

    await page.getByRole('button', { name: '薪資計算設定' }).click();
    const rulesModal = page.locator('.rules-modal:visible');
    await expect(rulesModal).toBeVisible();
    const modalBounds = await rulesModal.evaluate((node) => {
      const rect = node.getBoundingClientRect();
      return { right: rect.right, bottom: rect.bottom, width: rect.width, height: rect.height };
    });
    expect(modalBounds.right).toBeLessThanOrEqual(390);
    expect(modalBounds.bottom).toBeLessThanOrEqual(844);
    await expectNoOverflowAndReachableControls(page);
    await page.getByRole('button', { name: '取消' }).click();
  });

  test('keeps long Chinese labels usable on mobile', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/parttime-payroll-pilot-mount.html?mode=long');
    await expect(page.locator('.teacher-row:visible')).toContainText('很長的兼職老師姓名');
    await expectNoOverflowAndReachableControls(page);
  });
});
