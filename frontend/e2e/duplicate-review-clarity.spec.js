// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.DUPLICATE_REVIEW_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/duplicate-review-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const pendingGroup = {
  id: 'duplicate-101',
  student_name: '測試學生甲',
  session_date: '2026-09-10',
  start_time: '16:00',
  sides: [
    {
      student_class_id: 501,
      subject_name: '數學',
      teacher_name: '王老師',
      start_date: '2026-08-01',
      session_count: 8,
      remaining_sessions: 5,
      schedule_mode: 'weekly',
      session_ids: [7001],
      statuses: ['scheduled'],
      has_live_lr: false,
    },
    {
      student_class_id: 502,
      subject_name: '數學',
      teacher_name: '李老師',
      start_date: '2026-09-01',
      session_count: 12,
      remaining_sessions: 10,
      schedule_mode: 'count',
      session_ids: [7002, 7003],
      statuses: ['attended', 'scheduled'],
      has_live_lr: true,
    },
  ],
};

const longGroup = {
  ...pendingGroup,
  student_name: '這是一個很長的學生姓名用來驗證手機折行與主要操作仍然可達',
  sides: pendingGroup.sides.map((side, index) => ({
    ...side,
    subject_name: '這是一個很長的科目名稱用來驗證重複課程審核卡片自然折行',
    teacher_name: index === 0
      ? '這是一個很長的老師姓名'
      : '另一個很長的老師姓名',
  })),
};

function payloadFor(mode, status) {
  if (mode === 'empty') return { groups: [], total: 0 };
  if (mode === 'long') return { groups: [longGroup], total: 1 };
  if (status === 'all') {
    return {
      groups: [pendingGroup, { ...pendingGroup, id: 'duplicate-102', resolved_keeper_sc_id: 501 }],
      total: 2,
    };
  }
  return { groups: [pendingGroup], total: 1 };
}

async function installMock(page) {
  await page.addInitScript(() => { window.__duplicateReviewRetryAllowed = false; });
  await page.route('**/api/v1/admin/duplicate-sessions/p2-review**', async (route) => {
    const request = route.request();
    if (request.method() === 'PATCH') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ cancelled_count: 1, already_cancelled_count: 0, reversed_count: 0, kept_session_ids: [7001] }),
      });
    }
    const currentMode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (currentMode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (currentMode === 'error' && !await page.evaluate(() => Boolean(window.__duplicateReviewRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '重複課程資料暫時無法載入' }) });
    }
    const params = new URL(request.url()).searchParams;
    const status = params.has('status') ? params.get('status') : 'all';
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payloadFor(currentMode, status)) });
  });
}

async function expectNoOverflowAndReachableControls(page) {
  const controls = await page.locator('button, select, input:not([type="radio"]):not([type="checkbox"]), textarea').evaluateAll((nodes) => nodes.filter((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
  }).map((node) => ({ height: node.getBoundingClientRect().height })));
  expect(controls.filter((control) => control.height < 44)).toEqual([]);
  const radioLabels = await page.locator('.dsr-radio-label').evaluateAll((nodes) => nodes.flatMap((node) => {
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' ? [rect.height] : [];
  }));
  expect(radioLabels.filter((height) => height < 44)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}

test.describe('Duplicate session review clarity browser verification', () => {
  test('keeps the review queue readable across responsive widths', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/duplicate-review-pilot-mount.html?mode=normal');
      await expect(page.getByTestId('at-page-header').getByRole('heading', { name: '重複課程審核', exact: true })).toBeVisible();
      await expect(page.getByRole('tab', { name: /待審核/ })).toHaveAttribute('aria-selected', 'true');
      if (viewport.width <= 768) {
        await expect(page.locator('.dsr-mobile')).toBeVisible();
        await expect(page.locator('.dsr-desktop')).toBeHidden();
      } else {
        await expect(page.locator('.dsr-desktop')).toBeVisible();
        await expect(page.locator('.dsr-mobile')).toBeHidden();
      }
      await expectNoOverflowAndReachableControls(page);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('exposes tab, loading, empty, error, expansion, and submit feedback states', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/duplicate-review-pilot-mount.html?mode=normal');
    const allTab = page.getByRole('tab', { name: /全部/ });
    await allTab.focus();
    await page.keyboard.press('Enter');
    await expect(allTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('.dsr-mcard')).toHaveCount(2);

    await page.goto('/duplicate-review-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();

    await page.goto('/duplicate-review-pilot-mount.html?mode=empty');
    await expect(page.getByText('沒有待審核的重複課程時段', { exact: true })).toBeVisible();

    await page.goto('/duplicate-review-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入重複課程審核');
    await page.evaluate(() => { window.__duplicateReviewRetryAllowed = true; });
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.dsr-mcard')).toHaveCount(1);

    await page.getByRole('radio').first().check();
    await expect(page.getByRole('button', { name: '確認送出' })).toBeEnabled();
    await page.getByRole('button', { name: '確認送出' }).click();
    await expect(page.getByRole('status')).toContainText('已處理');
  });

  test('keeps long Chinese labels readable on mobile', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/duplicate-review-pilot-mount.html?mode=long');
    await expect(page.locator('.dsr-mcard')).toContainText('很長的學生姓名');
    await expect(page.locator('.dsr-mcard-side').first()).toContainText('很長的科目名稱');
    await expectNoOverflowAndReachableControls(page);
  });
});
