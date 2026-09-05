// @ts-check
/**
 * Dismiss login-time overlays that steal pointer events from sidebar / tabs.
 * Never use { force: true } on Vue-handled buttons — force skips the click
 * handler, so releaseNudgeOpen stays true and the layer keeps intercepting.
 *
 * Smoke hits production UI; role-onboarding V1.1 can open after login settles,
 * so this helper retries and waits briefly for late overlays.
 *
 * @param {import('@playwright/test').Page} page
 */
export async function dismissOverlays(page) {
  for (let attempt = 0; attempt < 8; attempt += 1) {
    const brand = page.locator('.brand-idle-layer').first();
    if (await brand.isVisible().catch(() => false)) {
      await brand.click({ position: { x: 8, y: 8 } }).catch(() => {});
      await page.locator('.brand-idle-layer').waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {});
    }

    // Role-onboarding V1.1 launch prompt (production may open this for smoke users).
    const onboardingLater = page.locator('.onboarding-launch-layer button.guide-tour-btn', { hasText: '稍後再看' }).first();
    if (await onboardingLater.isVisible().catch(() => false)) {
      await onboardingLater.click().catch(() => {});
      await page.locator('.onboarding-launch-layer').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }

    // Scope release-nudge dismiss — same label appears on onboarding launch.
    const nudgeLater = page.locator('.release-nudge-layer button.release-nudge-btn', { hasText: '稍後再看' }).first();
    if (await nudgeLater.isVisible().catch(() => false)) {
      await nudgeLater.click();
      await page.locator('.release-nudge-layer').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    } else if (await page.locator('.release-nudge-layer').isVisible().catch(() => false)) {
      await page.locator('.release-nudge-layer').click({ position: { x: 4, y: 4 } }).catch(() => {});
      await page.locator('.release-nudge-layer').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }

    const guideClose = page.locator('.guide-tour-close').first();
    if (await guideClose.isVisible().catch(() => false)) {
      await guideClose.click().catch(() => {});
    }

    const completion = page.locator('button.guide-tour-btn-primary', { hasText: '開始工作' }).first();
    if (await completion.isVisible().catch(() => false)) {
      await completion.click().catch(() => {});
    }

    const nudgeVisible = await page.locator('.release-nudge-layer').isVisible().catch(() => false);
    const brandVisible = await page.locator('.brand-idle-layer').isVisible().catch(() => false);
    const onboardingVisible = await page.locator('.onboarding-launch-layer').isVisible().catch(() => false);
    if (!nudgeVisible && !brandVisible && !onboardingVisible) {
      // Late async open after first paint — one short settle then re-check once.
      if (attempt === 0) {
        await page.waitForTimeout(400);
        continue;
      }
      return;
    }
    await page.waitForTimeout(200);
  }
}
