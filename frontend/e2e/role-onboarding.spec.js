import { test, expect } from '@playwright/test';
import { getRoleOnboardingSteps, ROLE_ONBOARDING_VERSION } from '../src/lib/roleOnboarding.js';

// Explicit local opt-in; all APIs are intercepted and no production login is used.
const localUrl = process.env.ONBOARDING_LOCAL_URL;
test.skip(!localUrl || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(localUrl), 'Requires local Vite');

for (const role of ['director', 'teacher']) {
  for (const width of [390, 1440]) {
    test(`${role} mission: launch, practice, spotlight and completion at ${width}px`, async ({ page }, testInfo) => {
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.setViewportSize({ width, height: 900 });
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await page.addInitScript(({ role }) => {
        localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'local-e2e-only', user: { id: 9001, role, name: '教學測試' } }));
        localStorage.setItem('app_branch', '1');
      }, { role });
      await page.route('**/api/**', (route) => {
        const path = new URL(route.request().url()).pathname;
        let data = { data: [] };
        if (path === '/api/v1/me') data = { id: 9001, name: '教學測試', role, campuses: [1], engagement: { rank_key: 'private_second', rank_label: '二等兵', xp_total: 12, role_track: role === 'teacher' ? 'teacher' : 'staff' } };
        if (path.includes('/class-sessions')) data = { api_kind: 'projection', completeness: 'full', data: [], by_class: {} };
        if (path.includes('/branches')) data = { data: [{ id: 1, name: '測試分校' }] };
        return route.fulfill({ json: data });
      });
      await page.goto(localUrl);
      await page.locator('.account-menu > summary').click();
      await page.getByText('重新觀看新手教學', { exact: true }).click();
      await expect(page.locator('.onboarding-launch-scene')).toBeVisible();
      await page.locator('.onboarding-launch-rank .ers-rank-trigger').click();
      await expect(page.locator('.rank-modal-card')).toBeVisible();
      await page.locator('.rank-modal-close').click();
      await page.screenshot({ path: testInfo.outputPath('launch.png'), animations: 'disabled' });
      await page.getByRole('button', { name: '開始導覽', exact: true }).click();
      const steps = getRoleOnboardingSteps(role);
      for (const [index, step] of steps.entries()) {
        await expect(page.locator('#guide-tour-title')).toHaveText(step.title);
        await expect(page.locator(`${step.target}.guide-tour-highlighted`)).toBeAttached();
        await page.getByRole('button', { name: '開始這一步 · 收起提示' }).click();
        await expect(page.locator('.guide-tour-popover-layer')).toHaveCount(0);
        await expect(page.locator('body')).not.toHaveCSS('position', 'fixed');
        await expect(page.locator('.guide-tour-highlighted')).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(page.locator('.onboarding-coach')).toContainText(step.title);
        if (index === 0) await page.screenshot({ path: testInfo.outputPath('practice.png'), animations: 'disabled' });
        await page.getByRole('button', { name: '查看提示／繼續任務' }).click();
        await expect(page.locator('#guide-tour-title')).toHaveText(step.title);
        await page.getByRole('button', { name: index === steps.length - 1 ? '完成任務' : '我完成了，下一步', exact: true }).click();
      }
      await expect(page.locator('.onboarding-complete-card')).toBeVisible();
      const state = await page.evaluate((role) => JSON.parse(localStorage.getItem(`alltrue_role_onboarding:${role}:9001`)), role);
      expect(state).toMatchObject({ status: 'completed', version: ROLE_ONBOARDING_VERSION });
      await expect(page.locator('.onboarding-complete-rank')).toContainText('XP 12');
      await page.screenshot({ path: testInfo.outputPath('completion.png'), animations: 'disabled' });
      await page.getByRole('button', { name: '開始工作', exact: true }).click();
      await expect(page.locator('.onboarding-complete-card')).toHaveCount(0);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      expect(errors).toEqual([]);
    });
  }
}
