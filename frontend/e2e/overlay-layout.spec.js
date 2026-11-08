import { test, expect } from '@playwright/test';
const fixture = '/overlay-layout.html';
const dialog = '[data-slot="dialog-content"]';
const alert = '[role="alertdialog"]';
const footer = '[data-slot="dialog-footer"]';
const rect = (l) => l.evaluate((el) => {
  const r = el.getBoundingClientRect();
  return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
});
const settled = (l) => l.evaluate(async (el) => {
  await Promise.all(el.getAnimations({ subtree: true }).map((animation) => animation.finished));
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => new URL(route.request().url()).hostname === '127.0.0.1'
    ? route.continue() : route.abort());
});
const z = (l) => l.evaluate((el) => +getComputedStyle(el).zIndex);
for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }, { width: 375, height: 480 }]) {
  test.describe(`${viewport.width}x${viewport.height}`, () => {
    test.use({ viewport, isMobile: viewport.width < 640, hasTouch: viewport.width < 640 });
    test('bounded three-row form, body-only scroll, sticky footer and keyboard navigation', async ({ page }, info) => {
      await page.goto(fixture);
      await page.getByRole('button', { name: '開啟表單', exact: true }).click();
      const panel = page.locator(dialog), foot = panel.locator(footer);
      await expect(page.locator('#field-1')).toBeFocused();
      await settled(panel);
      await page.screenshot({ path: info.outputPath('form-open.png') });
      const layout = await panel.evaluate((el) => ({
        display: getComputedStyle(el).display,
        rows: getComputedStyle(el).gridTemplateRows.split(' ').length,
        maxHeight: parseFloat(getComputedStyle(el).maxHeight),
      }));
      expect(layout.display).toBe('grid');
      expect(layout.rows).toBe(3);
      expect(layout.maxHeight).toBeCloseTo(viewport.height * 0.9, 0);
      const bounds = await rect(panel), before = await rect(foot);
      expect(before.bottom).toBeCloseTo(bounds.bottom - 1, 0);
      expect(bounds.height).toBeLessThanOrEqual(viewport.height * 0.9 + 1);
      expect(bounds.y).toBeGreaterThanOrEqual(0);
      expect(bounds.bottom).toBeLessThanOrEqual(viewport.height);
      expect(bounds.x).toBeGreaterThanOrEqual(0);
      expect(bounds.right).toBeLessThanOrEqual(viewport.width);
      await expect(foot).toHaveCSS('position', 'sticky');
      const body = panel.locator('form > div').nth(1);
      await expect(body).toHaveCSS('overflow-y', 'auto');
      expect((await rect(body)).bottom).toBeLessThanOrEqual(before.y + 1);
      await body.evaluate((el) => { el.scrollTop = el.scrollHeight; });
      expect(await body.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
      expect((await rect(foot)).y).toBeCloseTo(before.y, 0);
      await expect(page.locator('#field-30')).toBeInViewport();
      await page.screenshot({ path: info.outputPath('form-scrolled.png') });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      expect(await page.evaluate(() => scrollY)).toBe(0);
      await page.locator('#field-1').focus();
      for (let i = 0; i < 29; i++) await page.keyboard.press('Tab');
      await expect(page.locator('#field-30')).toBeFocused();
      await expect(page.locator('#field-30')).toBeInViewport();
      await page.keyboard.press('Tab');
      await expect(foot.getByRole('button', { name: '取消', exact: true })).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(foot.getByRole('button', { name: '儲存', exact: true })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(page.getByTestId('submitted')).toHaveText('1');
      for (let i = 0; i < 3; i++) await page.keyboard.press('Tab');
      expect(await panel.evaluate((el) => el.contains(document.activeElement))).toBe(true);
      await page.screenshot({ path: info.outputPath('form.png') });
      await page.keyboard.press('Escape');
      await expect(panel).toHaveCount(0);
    });
    test('dirty confirmation stacks above form, defaults to safe focus and restores editing', async ({ page }) => {
      await page.goto(`${fixture}?dirty`);
      await page.getByRole('button', { name: '開啟表單', exact: true }).click();
      await expect(page.locator('#field-1')).toBeFocused();
      await page.keyboard.press('Escape');
      const confirm = page.locator(alert), safe = confirm.getByRole('button', { name: '繼續編輯' });
      await expect(safe).toBeFocused();
      expect(await z(confirm)).toBeGreaterThan(await z(page.locator(dialog)));
      await page.keyboard.press('Shift+Tab');
      expect(await confirm.evaluate((el) => el.contains(document.activeElement))).toBe(true);
      await safe.click();
      await expect(confirm).toHaveCount(0);
      await expect(page.locator('#field-1')).toBeFocused();
      await page.keyboard.press('Escape');
      await page.locator(alert).getByRole('button', { name: '放棄變更', exact: true }).click();
      await expect(page.locator(dialog)).toHaveCount(0);
    });
    for (const kind of ['舊視窗', '詳情']) {
      test(`shared confirmation above ${kind} settles promise and restores focus`, async ({ page }) => {
        await page.goto(fixture);
        await page.getByRole('button', { name: `開啟${kind}`, exact: true }).click();
        const opener = page.getByRole('button', { name: `${kind}內確認`, exact: true });
        if (kind === '詳情') {
          const sheet = page.locator('[data-slot="sheet-content"]');
          await settled(sheet);
          expect((await rect(sheet)).width).toBeCloseTo(viewport.width < 640 ? viewport.width : 512, 0);
        }
        await opener.click();
        const confirm = page.locator(alert);
        await expect(confirm.getByRole('button', { name: '取消', exact: true })).toBeFocused();
        const below = page.locator(kind === '舊視窗' ? '.modal-overlay' : '[data-slot="sheet-content"]');
        expect(await z(confirm)).toBeGreaterThan(await z(below));
        const b = await rect(confirm.getByRole('button', { name: '繼續', exact: true }));
        expect(await page.evaluate(({ x, y }) => !!document.elementFromPoint(x, y)?.closest('[role="alertdialog"]'),
          { x: b.x + b.width / 2, y: b.y + b.height / 2 })).toBe(true);
        await confirm.getByRole('button', { name: '取消', exact: true }).click();
        await expect(page.getByTestId('result')).toHaveText('false');
        await expect(opener).toBeFocused();
        await opener.click();
        await confirm.getByRole('button', { name: '繼續', exact: true }).click();
        await expect(page.getByTestId('result')).toHaveText('true');
        await expect(opener).toBeFocused();
      });
    }
  });
}
