import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.SUBJECT_UNITS_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/subject-units-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const payload = {
  scope: { role: 'director', campus_ids: [1] },
  totals: {
    regular_subject_count: 7.5,
    tutoring_trial_subject_count: 1.5,
    payroll_subject_count: 9,
    final_payroll_subject_count: 1.13,
    regular_hours: 16,
    tutoring_trial_hours: 3,
    session_count: 12,
  },
  teacher_contributions: [
    { teacher_id: 701, teacher_name: '王老師', raw_subject_count: 6, payroll_subject_count: 0.75, campus_proportion_pct: 66.67 },
    { teacher_id: 702, teacher_name: '李老師', raw_subject_count: 3, payroll_subject_count: 0.38, campus_proportion_pct: 33.33 },
  ],
  days: [
    { date: '2026-09-10', regular_subject_count: 4, tutoring_trial_subject_count: 1, payroll_subject_count: 5, session_count: 7 },
    { date: '2026-09-11', regular_subject_count: 3.5, tutoring_trial_subject_count: 0.5, payroll_subject_count: 4, session_count: 5 },
  ],
  entries: [
    { teacher_id: 701, date: '2026-09-10', campus_id: 1, campus_name: '內湖分校', subject_id: 11, subject_name: '國中數學', regular_subject_count: 1.5, tutoring_trial_subject_count: 0, payroll_subject_count: 1.5, session_count: 2 },
    { teacher_id: 701, date: '2026-09-10', campus_id: 1, campus_name: '內湖分校', subject_id: 12, subject_name: '高中英文', regular_subject_count: 1.5, tutoring_trial_subject_count: 0.5, payroll_subject_count: 2, session_count: 3 },
    { teacher_id: 702, date: '2026-09-11', campus_id: 1, campus_name: '內湖分校', subject_id: 13, subject_name: '國小自然', regular_subject_count: 1, tutoring_trial_subject_count: 0, payroll_subject_count: 1, session_count: 2 },
  ],
};

function emptyPayload(role = 'director') {
  return {
    scope: { role, campus_ids: [1] },
    totals: { regular_subject_count: 0, tutoring_trial_subject_count: 0, payroll_subject_count: 0, final_payroll_subject_count: 0, regular_hours: 0, tutoring_trial_hours: 0, session_count: 0 },
    teacher_contributions: [],
    days: [],
    entries: [],
  };
}

async function installMock(page) {
  await page.addInitScript(() => { window.__subjectUnitsRetryAllowed = false; });
  await page.route('**/api/v1/branches', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify([{ id: 1, name: '內湖分校', code: 'neihu' }]),
  }));
  await page.route('**/api/v1/finance/subject-units/timeline**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__subjectUnitsRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'subject units unavailable' }) });
    }
    if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(emptyPayload()) });
    const role = new URL(page.url()).searchParams.get('role') || 'director';
    if (role === 'teacher') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ...payload, scope: { role: 'teacher', campus_ids: [1] }, teacher_contributions: [payload.teacher_contributions[0]] }),
      });
    }
    if (mode === 'long') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ...payload,
          teacher_contributions: payload.teacher_contributions.map((teacher) => ({ ...teacher, teacher_name: '這是一個很長的老師姓名用來驗證手機明細卡片折行與操作仍然可達' })),
          entries: payload.entries.map((entry) => ({ ...entry, teacher_name: '這是一個很長的老師姓名用來驗證手機明細卡片折行與操作仍然可達', subject_name: '這是一個很長的科目名稱用來驗證手機版內容不會被裁切' })),
        }),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
  });
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, input, select').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  expect(controls.filter((control) => control.height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Subject Units clarity browser verification', () => {
  test('keeps the real report readable across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/subject-units-pilot-mount.html?mode=normal');
      await expect(page.getByText('科目數統計', { exact: true })).toBeVisible();
      await expect(page.locator('.detail-card')).toBeVisible();
      if (viewport.width <= 768) {
        await expect(page.locator('.mobile-entry-card:visible').first()).toBeVisible();
      } else {
        await expect(page.locator('.detail-table:visible')).toBeVisible();
      }
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('uses shared state feedback and preserves report interactions', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/subject-units-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await page.goto('/subject-units-pilot-mount.html?mode=empty');
    await expect(page.getByText('這段期間沒有老師貢獻資料', { exact: true })).toBeVisible();
    await expect(page.getByText('這段期間沒有已認列的科目數', { exact: true })).toBeVisible();
    await page.goto('/subject-units-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('科目數資料載入失敗');
    await page.evaluate(() => { window.__subjectUnitsRetryAllowed = true; });
    await page.getByRole('button', { name: '重新載入' }).click();
    await expect(page.locator('.mobile-entry-card:visible').first()).toBeVisible();

    const rawSort = page.getByRole('button', { name: /原始科目數/ }).first();
    await rawSort.click();
    await expect(rawSort).toHaveAttribute('aria-sort', 'ascending');
    const trendDay = page.locator('.trend-day').first();
    await trendDay.focus();
    await page.keyboard.press('Enter');
    await expect(trendDay).toHaveClass(/trend-day--selected/);
    await page.getByRole('button', { name: '查看計算方式' }).click();
    await expect(page.locator('#subject-units-calc-guide-body')).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps long Chinese content and teacher scope usable on mobile', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/subject-units-pilot-mount.html?mode=long');
    await expect(page.locator('.mobile-entry-card:visible').first()).toContainText('很長的科目名稱');
    await expectNoOverflowAndReachableControls(page);
    await page.goto('/subject-units-pilot-mount.html?role=teacher');
    await expect(page.getByText(/只顯示我的資料/)).toBeVisible();
    await expect(page.getByText('占目前範圍', { exact: true })).toHaveCount(0);
    await expect(page.locator('[data-sort-key]')).toHaveCount(0);
    await expectNoOverflowAndReachableControls(page);
  });
});
