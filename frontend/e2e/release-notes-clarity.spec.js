// @ts-check
import { test, expect } from '@playwright/test';

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'mobile-wide', width: 412, height: 915 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop-compact', width: 1280, height: 900 },
  { name: 'desktop', width: 1440, height: 900 },
];

for (const viewport of viewports) {
  test(`release notes: disclosure controls remain usable at ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    const errors = [];
    const failedRequests = [];
    page.on('pageerror', (error) => errors.push(String(error)));
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    await page.goto('/release-notes-pilot-mount.html?role=director');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await expect(page.locator('.at-page-header__title')).toHaveText('版本更新');
    await expect(page.locator('.rn-featured')).toBeVisible();

    await page.screenshot({ path: `/tmp/release-notes-disclosure-before-${viewport.width}.png`, fullPage: true });

    const detail = page.locator('.rn-sections-details').first();
    const summary = detail.locator('summary');
    await summary.focus();
    await expect(summary).toBeFocused();
    const summaryStyle = await summary.evaluate((element) => {
      const style = getComputedStyle(element);
      return {
        height: element.getBoundingClientRect().height,
        outlineStyle: style.outlineStyle,
        outlineWidth: style.outlineWidth,
      };
    });
    expect(summaryStyle.height).toBeGreaterThanOrEqual(44);
    expect(summaryStyle.outlineStyle).toBe('solid');
    expect(Number.parseFloat(summaryStyle.outlineWidth)).toBeGreaterThanOrEqual(3);
    await page.screenshot({ path: `/tmp/release-notes-disclosure-after-${viewport.width}.png`, fullPage: true });

    await summary.click();
    await expect(detail).toHaveAttribute('open', '');
    await expect(detail.locator('.rn-section').first()).toBeVisible();
    await expect(detail.locator('li').first()).toBeVisible();

    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
    expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
  });
}

test('release notes: shared empty state is rendered for a role with no approved notes', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

  await page.goto('/release-notes-pilot-mount.html?role=student');
  await expect(page.locator('.at-empty')).toBeVisible();
  await expect(page.locator('.at-empty__title')).toHaveText('目前尚無可顯示的更新內容');
  await expect(page.locator('.at-empty__desc')).toContainText('新的核准公告');
  const layout = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
});
