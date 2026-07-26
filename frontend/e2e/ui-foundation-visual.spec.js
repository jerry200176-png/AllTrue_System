// @ts-check
/**
 * Design exploration only — static HTML harness screenshots.
 * Merge-acceptance evidence lives in ui-foundation-pages.spec.js (real Vue pages).
 */
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const harness = path.resolve(__dirname, 'fixtures/ui-foundation/ui-foundation-harness.html');
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || '/opt/cursor/artifacts/ui-foundation-pilot';

const enabled = process.env.UI_FOUNDATION_VISUAL === '1';

test.describe('UI foundation static harness (exploration only)', () => {
  test.skip(!enabled, 'Set UI_FOUNDATION_VISUAL=1 for exploration harness captures');

  test('static harness is reachable from fixtures (not public/)', async ({ page }) => {
    fs.mkdirSync(outDir, { recursive: true });
    expect(harness.includes(`${path.sep}public${path.sep}`)).toBe(false);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`file://${harness}`);
    await expect(page.locator('[data-scene="inbox-normal"]')).toBeVisible();
    await page.locator('[data-scene="inbox-normal"]').screenshot({
      path: path.join(outDir, 'exploration-inbox-normal-1440.png'),
    });
  });
});
