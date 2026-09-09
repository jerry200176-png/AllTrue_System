import { test, expect } from '@playwright/test';

const widths = [390, 412, 768, 1280, 1440];

function report({ long = false } = {}) {
  const names = long
    ? ['王小明的長期英文與數學複合課程學生', '李小華家長指定的跨校追蹤課程']
    : ['王小明', '李小華'];
  return {
    checked_at: '2026-09-09 03:00:12', threshold: 0, total_checked: 1428, mismatch_count: 3,
    cause_counts: { attendance_ahead: 1, ledger_ahead: 1, counter_overstated: 1 },
    mismatches: [
      { student_class_id: 101, student_name: names[0], subject_name: '數學', campus_name: '東湖分校', session_count: 24, recorded_used: 10, expected_used: 12, actual_attended: 12, diff: 2, category: 'attendance_ahead' },
      { student_class_id: 102, student_name: names[1], subject_name: '英文', campus_name: '板橋分校', session_count: 20, recorded_used: 5, expected_used: 8, actual_attended: 8, diff: 3, category: 'ledger_ahead' },
      { student_class_id: 103, student_name: '張大偉', subject_name: '自然科學', campus_name: '東湖分校', session_count: 16, recorded_used: 7, expected_used: 0, actual_attended: 0, diff: 7, category: 'counter_overstated' },
    ],
  };
}

async function openPage(page, mode = 'dense') {
  const consoleErrors = [];
  const failedRequests = [];
  page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  await page.route('**/api/v1/admin/reconcile/latest', async (route) => {
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'empty') return route.fulfill({ status: 404, body: JSON.stringify({}) });
    if (mode === 'error') return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '服務暫時無法使用' }) });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: report({ long: mode === 'long' }) }) });
  });
  await page.goto('/pilot-mount.html?page=nightly&mode=' + mode);
  await expect(page.locator('[data-pilot-ready="1"]')).toHaveCount(1);
  return { consoleErrors, failedRequests };
}

for (const width of widths) {
  test(`dense report is usable at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const diagnostics = await openPage(page);
    await expect(page.getByRole('heading', { name: '夜間堂數對帳' })).toBeVisible();
    await expect(page.getByRole('button', { name: /重新載入/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /依學生/ })).toBeVisible();
    await expect(page.getByRole('combobox', { name: '依分校篩選' })).toBeVisible();
    await expect(page.getByText('王小明')).toBeVisible();
    await page.getByRole('button', { name: /依學生/ }).click();
    await expect(page.locator('.nr-sort-icon').first()).toHaveText('▼');

    const targets = page.locator('.nightly-reconcile button, .nightly-reconcile select');
    const heights = await targets.evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().height));
    expect(heights.every((height) => height >= 44)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    expect(diagnostics.failedRequests).toEqual([]);
    expect(diagnostics.consoleErrors).toEqual([]);

    if (process.env.NR_EVIDENCE_DIR && (width === 390 || width === 1440)) {
      await page.screenshot({ path: `${process.env.NR_EVIDENCE_DIR}/vue-nightly-reconcile-normal-${width}.png`, fullPage: true });
    }
  });
}

test('empty, loading, error, and long-content states remain announced and recoverable', async ({ page, context }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await openPage(page, 'empty');
  await expect(page.getByRole('status')).toContainText('尚無堂數對帳報告');

  const loadingPage = await context.newPage();
  await loadingPage.setViewportSize({ width: 390, height: 900 });
  await openPage(loadingPage, 'loading');
  await expect(loadingPage.locator('.nr-loading')).toHaveCount(0);
  await expect(loadingPage.getByText('王小明')).toBeVisible();

  const errorPage = await context.newPage();
  await errorPage.setViewportSize({ width: 390, height: 900 });
  const errorDiagnostics = await openPage(errorPage, 'error');
  await expect(errorPage.getByRole('alert')).toContainText('服務暫時無法使用');
  await expect(errorPage.getByRole('button', { name: '重試' })).toBeVisible();
  expect(errorDiagnostics.failedRequests).toEqual([]);

  const longPage = await context.newPage();
  await longPage.setViewportSize({ width: 412, height: 900 });
  await openPage(longPage, 'long');
  await expect(longPage.getByText('王小明的長期英文與數學複合課程學生')).toBeVisible();
  expect(await longPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(412);
  await loadingPage.close();
  await errorPage.close();
  await longPage.close();
});

test('sorting is keyboard reachable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await openPage(page);
  const sortButton = page.getByRole('button', { name: /依學生/ });
  await sortButton.focus();
  await expect(sortButton).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('.nr-sort-icon').first()).toHaveText('▼');
});
