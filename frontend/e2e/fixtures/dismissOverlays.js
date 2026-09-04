// @ts-check
/**
 * Dismiss login-time overlays that steal pointer events from sidebar / tabs.
 * Never use { force: true } on Vue-handled buttons — force skips the click
 * handler, so releaseNudgeOpen stays true and the layer keeps intercepting.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function dismissOverlays(page) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const brand = page.locator('.brand-idle-layer').first();
    if (await brand.isVisible().catch(() => false)) {
      await brand.click({ position: { x: 8, y: 8 } }).catch(() => {});
      await page.locator('.brand-idle-layer').waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {});
    }

    // Scope to the release-nudge layer — "稍後再看" also appears on role-onboarding
    // previews and must not steal the dismiss click away from the blocking overlay.
    const nudgeLater = page.locator('.release-nudge-layer').getByRole('button', { name: '稍後再看' });
    if (await nudgeLater.isVisible().catch(() => false)) {
      await nudgeLater.click();
      await page.locator('.release-nudge-layer').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }

    const onboardingLater = page.locator('.guide-tour-layer').getByRole('button', { name: '稍後再看' }).first();
    if (await onboardingLater.isVisible().catch(() => false)) {
      await onboardingLater.click().catch(() => {});
    }

    const guideClose = page.locator('.guide-tour-close').first();
    if (await guideClose.isVisible().catch(() => false)) {
      await guideClose.click().catch(() => {});
    }

    const nudgeVisible = await page.locator('.release-nudge-layer').isVisible().catch(() => false);
    const brandVisible = await page.locator('.brand-idle-layer').isVisible().catch(() => false);
    if (!nudgeVisible && !brandVisible) return;
  }
}
