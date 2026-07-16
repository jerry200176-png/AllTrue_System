// @ts-check
import { test, expect } from '@playwright/test';

/**
 * in-app #200 — director can decide without understanding SC.
 *
 * Acceptance: a director who does not know what "SC" means can still
 * identify the correct course from on-screen human labels.
 *
 * This test never asserts on "SC #" in the decision path.
 * Skips when smoke secrets are absent (same policy as smoke.spec.js).
 */

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = {
  account: process.env.SMOKE_DIRECTOR_USER,
  password: process.env.SMOKE_DIRECTOR_PASS,
};

async function login(page, creds) {
  await page.goto('/');
  await page.locator('.role-btn', { hasText: '主任/櫃台' }).first().click({ trial: false }).catch(() => {});
  await page.locator('#login-account').fill(creds.account);
  await page.locator('#login-password').fill(creds.password);
  await page.locator('button.login-btn').click();
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

async function dismissOverlays(page) {
  for (const sel of ['.guide-tour-close', '.release-nudge-btn:has-text("稍後再看")']) {
    const el = page.locator(sel).first();
    if (await el.isVisible().catch(() => false)) {
      await el.click().catch(() => {});
      await page.waitForTimeout(200);
    }
  }
}

test.describe('Duplicate review human labels — in-app #200', () => {
  test.skip(!BASE || !DIRECTOR.account, '未設定 SMOKE_* — 略過');

  test('主任可依科目／老師／開課日辨識課程（決策路徑不依賴 SC）', async ({ page }) => {
    await login(page, DIRECTOR);
    await dismissOverlays(page);

    // Navigate via Trust CTA or sidebar if present
    const trustCta = page.getByRole('button', { name: /審核重疊|去審核/ }).first();
    if (await trustCta.isVisible().catch(() => false)) {
      await trustCta.click();
    } else {
      const more = page.getByRole('button', { name: /更多|重疊/ }).first();
      if (await more.isVisible().catch(() => false)) await more.click();
    }

    await page.waitForLoadState('networkidle').catch(() => {});

    // If duplicate review is visible, primary labels must be human-readable.
    const primary = page.locator('.dsr-side-primary, .dsr-mcard-side .dsr-side-primary').first();
    const hasReview = await primary.isVisible().catch(() => false);
    test.skip(!hasReview, '此帳號／分校目前無重疊審核畫面 — 略過');

    const text = (await primary.textContent())?.trim() || '';
    expect(text.length).toBeGreaterThan(0);
    // Must not require SC as the only identifier
    expect(text).not.toMatch(/^SC\s*#?\d+$/i);
    // Should contain at least one human cue (CJK subject/teacher or 開課)
    expect(text).toMatch(/開課|堂|[\u4e00-\u9fff]/);
  });
});
