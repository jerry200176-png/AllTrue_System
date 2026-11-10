// @ts-check
/**
 * Overlay gallery (#4989): on each major page, click every visible non-destructive button, and when it opens
 * an overlay (role=dialog / alertdialog / [data-state=open]) screenshot it at 1280 and 375, then close it.
 * Screenshots go to <UI_FOUNDATION_SHOT_DIR>/overlays and ship in the CI evidence artifact; no baselines are committed.
 * Evidence only: this never asserts that a given page has an overlay.
 */
import { test } from '@playwright/test';
import { installApiMocks } from './fixtures/ui-foundation/apiMocks.js';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const outDir = path.join(
  process.env.UI_FOUNDATION_SHOT_DIR || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../docs/design/evidence/raw'),
  'overlays',
);
const pages = ['director', 'students', 'teachers', 'course', 'calendar', 'attendance', 'tuition', 'admissions', 'classroom', 'branch-management', 'line-integration', 'profile', 'question-bank'];
const widths = [{ w: 1280, h: 900 }, { w: 375, h: 812 }];
const MAX_CLICKS = 14;
const OPEN = '[role=dialog], [role=alertdialog], [role=menu], [data-state=open]';
const SKIP = /刪除|移除|作廢|停用|封存|登出|鎖帳|清除|撤銷|終止|拒絕|送出|確認付款/;

for (const name of pages) {
  for (const vp of widths) {
    test(`overlay gallery ${name} @${vp.w}`, async ({ page }) => {
      test.setTimeout(120_000);
      fs.mkdirSync(outDir, { recursive: true });
      await page.setViewportSize({ width: vp.w, height: vp.h });
      await installApiMocks(page, 'normal', name, null, undefined);
      const mount = `/pilot-mount.html?page=${name}&mode=normal`;
      await page.goto(mount);
      await page.waitForSelector('html[data-pilot-ready="1"]', { timeout: 15_000 }).catch(() => {});
      await page.waitForTimeout(500);
      for (let i = 0; i < MAX_CLICKS; i += 1) {
        const btn = page.locator('button:visible').nth(i);
        if (!(await btn.count())) break;
        const label = ((await btn.innerText().catch(() => '')) || (await btn.getAttribute('aria-label')) || '').trim();
        if (SKIP.test(label)) continue;
        await btn.click({ timeout: 2000 }).catch(() => {});
        const open = page.locator(OPEN).first();
        if (await open.isVisible({ timeout: 600 }).catch(() => false)) {
          await page.screenshot({ path: path.join(outDir, `${name}-${vp.w}-${String(i).padStart(2, '0')}.png`) });
          await page.keyboard.press('Escape');
          if (await open.isVisible({ timeout: 300 }).catch(() => false)) {
            await page.goto(mount); // not Esc-closable: reload instead of failing the gallery
            await page.waitForSelector('html[data-pilot-ready="1"]', { timeout: 15_000 }).catch(() => {});
          }
        }
      }
    });
  }
}
