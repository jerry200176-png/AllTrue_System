// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Authenticated production smoke for the isolated TEST Parent Portal fixture.
 *
 * The token is provisioned by the protected parent-portal-smoke phase in
 * deploy.yml. This spec performs only parent GETs and local UI navigation.
 */
const BASE = process.env.SMOKE_BASE_URL;
const SESSION_B64 = process.env.SMOKE_PARENT_SESSION_B64;

function sessionToken() {
  return Buffer.from(String(SESSION_B64 || ''), 'base64').toString('utf8');
}

async function assertParentSurface(page, viewport, testInfo) {
  await page.setViewportSize(viewport);
  const errors = [];
  const failedResponses = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('response', (response) => {
    const path = new URL(response.url()).pathname;
    if (path.includes('/api/v1/parent/') && response.status() >= 400) {
      failedResponses.push(String(response.status()) + ' ' + path);
    }
  });

  await page.addInitScript((token) => {
    localStorage.setItem('parent_portal_token', token);
  }, sessionToken());
  await page.goto('/?parent=1');

  await expect(page.locator('[data-guide="parent-portal-root"]')).toBeVisible();
  await expect(page.locator('[data-guide="parent-student-card"]')).toContainText('TEST / SYNTHETIC Student');
  await expect(page.getByText('最近學了什麼', { exact: true })).toBeVisible();
  await expect(page.getByText('本週重點', { exact: true })).toBeVisible();
  await expect(page.getByText('老師建議／處理', { exact: true })).toBeVisible();
  await expect(page.getByText('回家要做什麼', { exact: true })).toBeVisible();
  await expect(page.getByText('下一步／目前待辦', { exact: true })).toBeVisible();
  const parentUpdate = page.locator('.pp-parent-update__btn');
  await expect(parentUpdate).toBeVisible();
  await parentUpdate.click();
  await expect(page.getByText('學習評量重點更清楚', { exact: true })).toBeVisible();
  await expect(page.getByText('已核准的學習評量仍可從「學習」分頁逐堂展開查看；尚未完成老師複核時，頁面會清楚說明目前沒有可查看的評量內容。', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: '關閉', exact: true }).click();
  await expect(page.getByText('學習評量重點更清楚', { exact: true })).toBeHidden();

  const learningTab = page.getByRole('tab', { name: /學習/ });
  const scheduleTab = page.getByRole('tab', { name: /課表/ });
  const billingTab = page.getByRole('tab', { name: /帳務/ });
  await expect(learningTab).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByText('目前沒有需要處理的事項。', { exact: false })).toBeVisible();

  await page.getByRole('button', { name: /本週學習/ }).click();
  await expect(learningTab).toHaveAttribute('aria-selected', 'true');
  await page.getByRole('button', { name: /下次課程/ }).click();
  await expect(scheduleTab).toHaveAttribute('aria-selected', 'true');
  await page.getByRole('button', { name: /繳費狀態/ }).click();
  await expect(billingTab).toHaveAttribute('aria-selected', 'true');

  await scheduleTab.click();
  await expect(scheduleTab).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByText('近期無安排課程', { exact: true })).toBeVisible();

  await billingTab.click();
  await expect(billingTab).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByText('目前無待繳費項目', { exact: true })).toBeVisible();
  await expect(page.getByText('尚未綁定 LINE。', { exact: false })).toBeVisible();

  await learningTab.click();
  await expect(page.locator('[data-guide="parent-learning-empty"]')).toBeVisible();
  await expect(page.getByText('目前沒有已核准的學習評量', { exact: true })).toBeVisible();
  await expect(page.getByText('老師完成複核後，這裡會顯示每堂課的進度、作業與建議。', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: '查看課表', exact: true }).click();
  await expect(scheduleTab).toHaveAttribute('aria-selected', 'true');
  await learningTab.click();

  const feedbackCategory = page.getByRole('button', { name: '老師教學', exact: true });
  const feedbackStar = page.getByRole('button', { name: '1 顆星', exact: true });
  if (await feedbackCategory.isEnabled()) {
    await feedbackCategory.click();
    await expect(feedbackCategory).toHaveClass(/active/);
    await feedbackStar.click();
    await expect(feedbackStar).toHaveAttribute('aria-pressed', 'true');
  } else {
    await expect(feedbackCategory).toBeDisabled();
    await expect(feedbackStar).toBeDisabled();
    await expect(page.getByRole('textbox', { name: '建議內容' })).toBeDisabled();
  }

  const screenshot = await page.screenshot({ fullPage: true });
  await testInfo.attach(`parent-learning-${viewport.width}`, {
    body: screenshot,
    contentType: 'image/png',
  });

  const layout = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(layout.scrollWidth, String(viewport.width) + 'px layout overflow').toBeLessThanOrEqual(layout.clientWidth);
  expect(errors, 'page errors at ' + viewport.width + 'px:\n' + errors.join('\n')).toEqual([]);
  expect(failedResponses, 'parent API failures at ' + viewport.width + 'px:\n' + failedResponses.join('\n')).toEqual([]);

  await page.getByRole('button', { name: '登出' }).click();
  await expect(page.locator('[data-guide="parent-login-card"]')).toBeVisible();
}

test.describe('authenticated production Parent Portal smoke', () => {
  test.skip(!BASE || !SESSION_B64, 'protected smoke phase did not provide a parent session');

  test('desktop 1440px home and read-only tabs', async ({ page }, testInfo) => {
    await assertParentSurface(page, { width: 1440, height: 1000 }, testInfo);
  });

  test('mobile 390px home and read-only tabs', async ({ page }, testInfo) => {
    await assertParentSurface(page, { width: 390, height: 844 }, testInfo);
  });
});
