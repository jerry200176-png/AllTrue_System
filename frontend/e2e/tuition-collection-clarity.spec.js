import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.TUITION_COLLECTION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/tuition-collection-clarity');
const dialogShotDir = process.env.TUITION_COLLECTION_DIALOG_SHOT_DIR || outDir;
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

const accountingRows = [
  { report_id: 701, payment_date: '2026-09-08', receipt_no: 'AT-260908-0001', student_name: '林宥辰', subject: '國中數學', cash_amount: 0, transfer_amount: 4200, total_amount: 4200, confirmed_by_name: 'E2E 主任' },
  { report_id: 702, payment_date: '2026-09-07', receipt_no: 'AT-260907-0002', student_name: '陳品妤', subject: '高中英文', cash_amount: 5600, transfer_amount: 0, total_amount: 5600, confirmed_by_name: 'E2E 主任', is_prepaid: true },
];
const settledRows = [
  { student_class_id: 101, course_ref: '000101', student_name: '林宥辰', subject: '國中數學', schedule_mode: 'date', paid_amount: 4200, last_paid_at: '2026-09-08' },
  { student_class_id: 102, course_ref: '000102', student_name: '陳品妤', subject: '高中英文', schedule_mode: 'count', paid_amount: 5600, last_paid_at: '2026-09-07', pending_reconciliation: true },
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
  await page.route('**/api/v1/accounting/payments**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'accounting payments unavailable' }) });
    }
    const data = mode === 'empty' ? [] : mode === 'long' ? accountingRows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證收據卡片折行與操作仍然可達',
      subject: '這是一個很長的科目名稱用來驗證收據資料在手機上不會被裁切',
    })) : accountingRows;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data, summary: { total_count: data.length, unique_paid_course_count: data.length, duplicate_payment_course_count: 0, cash_total: data.length ? 5600 : 0, transfer_total: data.length ? 4200 : 0, grand_total: data.length ? 9800 : 0, prepaid_count: data.length ? 1 : 0 } }),
    });
  });
  await page.route('**/api/v1/accounting/settled-courses**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'settled courses unavailable' }) });
    }
    const data = mode === 'empty' ? [] : mode === 'long' ? settledRows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證結清卡片折行與操作仍然可達',
      subject: '這是一個很長的科目名稱用來驗證結清資料在手機上不會被裁切',
    })) : settledRows;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data, summary: { course_count: data.length, legacy_count: 0, exception_count: 0, paid_total: data.length ? 9800 : 0, overpaid_total: 0, pending_reconciliation_count: data.length ? 1 : 0 } }),
    });
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
    const dialog = page.locator('.tc-session-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('role', 'dialog');
    await expect(dialog).toBeFocused();
    const box = await dialog.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    expect(box.y + box.height).toBeLessThanOrEqual(844);
    await page.screenshot({ path: path.join(dialogShotDir, 'session-dialog-390.png'), fullPage: true });
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });

  test('captures the accounting tabs without page overflow', async ({ page }) => {
    await installMock(page);
    for (const tab of ['payments', 'settled']) {
      for (const viewport of viewports) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
        await expect(page.getByText('帳務中心', { exact: true })).toBeVisible();
        await page.getByRole('tab', { name: tab === 'payments' ? '收據紀錄' : '已結清課程彙總' }).click();
        await expect(page.locator('.acct-table:visible')).toBeVisible();
        await expectNoOverflowAndReachableControls(page);
        await page.screenshot({ path: path.join(outDir, `${tab}-${viewport.name}.png`), fullPage: true });
      }
    }
  });

  test('uses shared accounting error, retry, and empty feedback', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=error');
    await page.getByRole('tab', { name: '收據紀錄' }).click();
    await expect(page.getByText('收據紀錄載入失敗', { exact: true })).toBeVisible();
    await page.evaluate(() => { window.__tuitionCollectionRetryAllowed = true; });
    await page.getByRole('button', { name: '重新載入' }).click();
    await expect(page.locator('.acct-table:visible')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=empty');
    await page.getByRole('tab', { name: '已結清課程彙總' }).click();
    await expect(page.getByText('目前查無已結清課程', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps accounting loading and long Chinese content usable', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=loading');
    await page.getByRole('tab', { name: '收據紀錄' }).click();
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=long');
    await page.getByRole('tab', { name: '收據紀錄' }).click();
    await expect(page.getByText(/很長的學生姓名/).first()).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
    await page.goto('/tuition-collection-pilot-mount.html?mode=long');
    await page.getByRole('tab', { name: '已結清課程彙總' }).click();
    await expect(page.getByText(/很長的學生姓名/).first()).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });
});
