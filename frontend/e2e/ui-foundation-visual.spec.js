// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const harness = path.resolve(__dirname, '../public/ui-foundation-harness.html');
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || '/opt/cursor/artifacts/ui-foundation-pilot';

const enabled = process.env.UI_FOUNDATION_VISUAL === '1';

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '768', width: 768, height: 1024 },
  { name: '1440', width: 1440, height: 900 },
];

const scenes = [
  'inbox-normal',
  'inbox-loading',
  'inbox-empty',
  'inbox-error',
  'inbox-long',
  'students-normal',
  'students-empty',
  'students-error',
  'students-dense',
];

test.describe('UI foundation visual harness', () => {
  test.skip(!enabled, 'Set UI_FOUNDATION_VISUAL=1 to capture foundation screenshots');

  for (const vp of viewports) {
    test(`capture ${vp.name}`, async ({ page }) => {
      fs.mkdirSync(outDir, { recursive: true });
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto(`file://${harness}`);
      await expect(page.locator('[data-scene="inbox-normal"]')).toBeVisible();

      for (const scene of scenes) {
        const loc = page.locator(`[data-scene="${scene}"]`);
        await loc.scrollIntoViewIfNeeded();
        await loc.screenshot({
          path: path.join(outDir, `after-${scene}-${vp.name}.png`),
        });
      }

      await page.locator('#focus-primary').focus();
      await expect(page.locator('#focus-primary')).toBeFocused();
      await page.locator('[data-scene="students-normal"]').screenshot({
        path: path.join(outDir, `after-students-focus-${vp.name}.png`),
      });

      await page.locator('[data-scene="inbox-long"] .btn[disabled]').first().screenshot({
        path: path.join(outDir, `after-disabled-${vp.name}.png`),
      });
    });
  }
});
