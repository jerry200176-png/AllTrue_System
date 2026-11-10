// @ts-check
// 版本更新 on the shadcn-vue template (#4989): the real page on the pilot mount with the real generated notes; it calls no API.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../docs/design/evidence/raw');

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'mobile-wide', width: 412, height: 915 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop-compact', width: 1280, height: 900 },
  { name: 'desktop', width: 1440, height: 900 },
];

/** Widest visible 0-offset box-shadow spread in px: the template focus ring (`ring-3`). */
const focusRingWidth = (element) => Math.max(0, ...getComputedStyle(element).boxShadow
  .split(/,(?![^(]*\))/)
  .map((layer) => {
    const args = layer.match(/[a-z]+\(([^)]*)\)/)?.[1] ?? '';
    const alpha = args.includes('/') ? args.split('/')[1] : args.split(',')[3] ?? '1';
    const px = layer.replace(/[a-z]+\([^)]*\)/, '').match(/-?[\d.]+px/g)?.map((v) => Number.parseFloat(v)) ?? [];
    return Number.parseFloat(alpha) > 0 && px.length === 4 && px[0] === 0 && px[1] === 0 && px[2] === 0 ? px[3] : 0;
  }));

function watchPage(page) {
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  return async () => {
    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
    expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
  };
}

for (const viewport of viewports) {
  test(`release notes: disclosure controls remain usable at ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    const assertClean = watchPage(page);

    await page.goto('/pilot-mount.html?page=release-notes&role=director');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('版本更新');
    await expect(page.getByTestId('release-note-featured')).toBeVisible();
    await expect(page.getByRole('link', { name: '查看完整 CHANGELOG' })).toBeVisible();

    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `release-notes-${viewport.width}.png`), fullPage: true });

    const detail = page.locator('[data-testid="release-note"] details').first();
    const summary = detail.locator('summary');
    await summary.focus();
    await expect(summary).toBeFocused();
    expect((await summary.boundingBox())?.height).toBeGreaterThanOrEqual(44);
    expect(await summary.evaluate(focusRingWidth)).toBeGreaterThanOrEqual(3);

    await summary.click();
    await expect(detail).toHaveAttribute('open', '');
    await expect(detail.locator('section').first()).toBeVisible();
    await expect(detail.locator('li').first()).toBeVisible();
    await page.screenshot({ path: path.join(outDir, `release-notes-open-${viewport.width}.png`), fullPage: true });

    // Older notes are built on first open; a closed 操作細節 inside the open list keeps its chevron unturned.
    const older = page.getByTestId('release-notes-older');
    await expect(older.getByTestId('release-note')).toHaveCount(0);
    await older.locator('> summary').click();
    await expect(older.getByTestId('release-note').first()).toBeVisible();
    expect(await older.locator('[data-testid="release-note"] summary svg').first().evaluate((svg) => getComputedStyle(svg).rotate)).toBe('none');

    // Template floor: body text 16px or more, every control 44px or taller.
    const floor = await page.evaluate(() => ({
      body: Math.min(...[...document.querySelectorAll('[data-testid="release-notes-page"] p.tw\\:text-tpl-body, [data-testid="release-notes-page"] li')]
        .map((el) => Number.parseFloat(getComputedStyle(el).fontSize))),
      control: Math.min(...[...document.querySelectorAll('[data-testid="release-notes-page"] a, [data-testid="release-notes-page"] summary')]
        .filter((el) => el.getClientRects().length).map((el) => el.getBoundingClientRect().height)),
    }));
    expect(floor.body).toBeGreaterThanOrEqual(16);
    expect(floor.control).toBeGreaterThanOrEqual(44);
    await assertClean();
  });
}

test('release notes: empty state is rendered for a role with no approved notes', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const assertClean = watchPage(page);

  await page.goto('/pilot-mount.html?page=release-notes&role=student');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
  const empty = page.getByTestId('release-notes-empty');
  await expect(empty).toBeVisible();
  await expect(empty.getByRole('heading', { level: 2 })).toHaveText('目前尚無可顯示的更新內容');
  await expect(empty).toContainText('新的核准公告');
  await assertClean();
});
