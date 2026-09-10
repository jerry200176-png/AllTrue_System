import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.TUITION_COLLECTION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/tuition-collection-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const rows = [
  {
    id: 101, student_name: '林宥辰', subject: '國中數學', schedule_mode: 'date', payment_status: 'unpaid',
    charge: 4200, paid_amount: 0, outstanding: 4200, due_date: '2026-09-12', days_until_settlement: 2,
    course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 501,
  },
  {
    id: 102, student_name: '陳品妤', subject: '高中英文', schedule_mode: 'count', payment_status: 'pending_report',
    charge: 5600, paid_amount: 5600, outstanding: 0, last_paid_at: '2026-09-08',
    remaining_sessions: 4, sessions_purchased: 16, latest_payment_report_id: 601, invoice_id: 502,
  },
  {
    id: 103, student_name: '王子謙', subject: '國小自然', schedule_mode: 'date', payment_status: 'renew_needed',
    charge: 3800, paid_amount: 3800, outstanding: 0, last_paid_at: '2026-09-02', due_date: '2026-09-01',
    days_until_settlement: -9, course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 503,
  },
];

function payload(mode) {
  if (mode === 'empty') return [];
  if (mode === 'long') {
    return rows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證帳務佇列在手機上仍然可讀且操作可達',
      subject: '這是一個很長的科目名稱用來驗證帳務資料在手機上不會被裁切',
    }));
  }
  return rows;
}

async function installMock(page) {
  await page.addInitScript(() => { window.__tuitionCollectionRetryAllowed = false; });
  await page.route('**/api/v1/alerts/tuition**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'tuition alerts unavailable' }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload(mode)) });
  });
  await page.route('**/api/v1/branches**', (route) => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 1, name: '內湖分校', code: 'neihu' }]),
  }));
  await page.route('**/api/v1/class-sessions**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [
      { id: 801, session_date: '2026-09-08', start_time: '18:00', end_time: '19:30', teacher_name: '李老師', status: 'attended' },
      { id: 802, session_date: '2026-09-10', start_time: '18:00', end_time: '19:30', teacher_name: '李老師', status: 'scheduled' },
    ] }),
  }));
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, input:not([type="checkbox"]), select, textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  if (!process.env.TUITION_COLLECTION_BASELINE) expect(controls.filter((control) => control.height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Tuition Collection clarity browser verification', () => {
  test('keeps the real receivables queue readable across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
      await expect(page.getByText('帳務中心', { exact: true })).toBeVisible();
      await expect(page.getByText('林宥辰', { exact: true })).toBeVisible();
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('uses clear loading, empty, error, and retry feedback', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=loading');
    await expect(page.locator('.tc-skeleton-area')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=empty');
    await expect(page.getByText('本分校目前無待催繳課程', { exact: true })).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=error');
    await expect(page.getByText('帳務待處理資料載入失敗', { exact: true })).toBeVisible();
    await page.evaluate(() => { window.__tuitionCollectionRetryAllowed = true; });
    await page.getByRole('button', { name: '重新載入' }).click();
    await expect(page.getByText('林宥辰', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps long Chinese content and keyboard disclosure usable', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=long');
    await expect(page.getByText(/很長的學生姓名/).first()).toBeVisible();
    const process = page.locator('.tc-process-disclosure > summary');
    await process.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('照順序完成，不用記入口', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps the existing session detail dialog within the usable viewport', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
    await page.getByRole('button', { name: /剩 4 堂/ }).click();
    const dialog = page.locator('.tc-dialog--wide');
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    expect(box.y + box.height).toBeLessThanOrEqual(844);
    await page.getByRole('button', { name: '關閉' }).click();
    await expect(dialog).toBeHidden();
  });
});
