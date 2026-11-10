import { test, expect } from '@playwright/test';

for (const width of [390, 1440]) {
  for (const rootSize of [16, 20]) {
    test(`parent settings persist text size at ${width}px / ${rootSize}px root`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 900 });
      await page.clock.setFixedTime(new Date('2026-11-08T04:00:00Z'));
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      // Even if a preview server config exists, force fake mode and block external traffic.
      await page.route('**/*', (route) => {
        const url = new URL(route.request().url());
        if (url.origin !== 'http://127.0.0.1:5189') return route.abort();
        if (url.pathname === '/api/preview-config') return route.fulfill({ json: {} });
        return route.continue();
      });
      const root = async () => page.addStyleTag({ content: `html { font-size: ${rootSize}px !important; }` });
      const home = () => page.getByRole('tab', { name: '首頁', exact: true });
      const settings = () => page.getByRole('button', { name: '設定', exact: true });
      const standard = () => page.getByRole('radio', { name: '標準', exact: true });
      const large = () => page.getByRole('radio', { name: '大', exact: true });
      const checkHome = async (size) => {
        await page.mouse.move(0, 0); // a settings click may otherwise hover a newly mounted home CTA
        await expect(page.locator('html')).toHaveAttribute('data-text-size', size);
        const buttons = page.locator('[data-guide="parent-home"] button');
        await expect(buttons).toHaveCount(3);
        // Wait for the real entrance transition before claiming visibility or taking evidence.
        for (const card of await page.locator('[data-guide="parent-home"] .blur-fade').all()) {
          await expect(card).toHaveCSS('filter', 'none');
          await expect(card).toHaveCSS('opacity', '1');
        }
        const metrics = await buttons.evaluateAll((nodes) => {
          const probe = document.createElement('span');
          probe.style.fontSize = 'var(--tpl-text-body)';
          document.body.append(probe);
          const body = getComputedStyle(probe).fontSize;
          const results = nodes.map((node) => {
            const pay = node.textContent.includes('繳費方式');
            probe.style.color = pay ? 'var(--ds-brand-glyph)' : 'var(--ui-secondary-foreground)';
            const color = getComputedStyle(probe).color;
            probe.style.backgroundColor = pay ? 'var(--ds-brand-sun)' : 'var(--ui-secondary)';
            const background = getComputedStyle(probe).backgroundColor;
            const actual = getComputedStyle(node);
            return { text: node.textContent, size: actual.fontSize, body, color: actual.color, expectedColor: color,
              background: actual.backgroundColor, expectedBackground: background, height: node.getBoundingClientRect().height };
          });
          probe.remove();
          return results;
        });
        for (const [index, metric] of metrics.entries()) {
          expect(metric.size).toBe(metric.body);
          expect(metric.color, metric.text).toBe(metric.expectedColor);
          await expect(buttons.nth(index)).toHaveCSS('background-color', metric.expectedBackground);
          metric.background = await buttons.nth(index).evaluate((node) => getComputedStyle(node).backgroundColor);
          expect(metric.height).toBeGreaterThanOrEqual(44);
        }
        await testInfo.attach(`${size}-metrics`, { body: JSON.stringify(metrics, null, 2), contentType: 'application/json' });
        await expect(page.getByRole('button', { name: '繳費方式', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        return metrics;
      };

      await page.goto('/?demo=parent');
      await root();
      const before = await checkHome('standard');
      await settings().focus();
      await page.keyboard.press('Enter');
      await expect(page.getByRole('region', { name: '設定', exact: true })).toBeVisible();
      await page.keyboard.press('Tab');
      await expect(standard()).toBeFocused();
      await page.keyboard.press('ArrowRight');
      await expect(large()).toBeFocused();
      await expect(large()).not.toHaveCSS('box-shadow', 'none');
      await expect(large()).toHaveAttribute('aria-checked', 'true');
      await page.keyboard.press('Tab');
      await expect(standard()).not.toBeFocused();
      await expect(large()).not.toBeFocused();
      await large().focus();
      await page.keyboard.press('ArrowDown'); // wraps
      await expect(standard()).toBeFocused();
      await page.keyboard.press('ArrowLeft'); // wraps backwards
      await expect(large()).toBeFocused();
      await page.keyboard.press('ArrowUp');
      await expect(standard()).toBeFocused();
      await large().click(); // touch/click path uses the same preference
      await expect(large()).toHaveAttribute('aria-checked', 'true');
      expect(await page.evaluate(() => localStorage.getItem('alltrue_text_size'))).toBe('large');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await page.screenshot({ path: testInfo.outputPath('settings-large.png'), fullPage: true });
      await home().focus();
      await page.keyboard.press('Enter');
      const after = await checkHome('large');
      expect(parseFloat(after[0].size)).toBeGreaterThan(parseFloat(before[0].size));
      await page.screenshot({ path: testInfo.outputPath('home-large.png'), fullPage: true });
      await settings().click();
      await expect(large()).toHaveAttribute('aria-checked', 'true');
      await settings().click(); // close settings via the existing toggle
      await checkHome('large');
      await page.reload();
      await root();
      await checkHome('large');
      await settings().click();
      await expect(large()).toHaveAttribute('aria-checked', 'true');
      await standard().focus();
      await page.keyboard.press('Space');
      await home().click();
      await checkHome('standard');
      expect(errors).toEqual([]);
    });
  }
}
