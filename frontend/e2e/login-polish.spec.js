// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Login.vue DS polish smoke（Epic #687 Login pilot）
 * 不需帳密：只驗 DOM 契約、錯誤態、忘記密碼、focus-visible、reduced-motion。
 * 登入行為／API 不變；完整業務 smoke 仍見 smoke.spec.js。
 */

const BASE = process.env.SMOKE_BASE_URL || process.env.LOGIN_POLISH_BASE_URL || 'http://127.0.0.1:5173';

test.describe('Login polish smoke', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.removeItem('alltrue_session');
      localStorage.removeItem('parent_portal_token');
    });
    await page.goto(BASE + '/');
    await expect(page.locator('.login-wrap')).toBeVisible({ timeout: 30_000 });
  });

  test('default login shell keeps selectors used by e2e login()', async ({ page }) => {
    await expect(page.locator('.login-brand-bar')).toHaveCount(1);
    await expect(page.locator('.login-trust')).toContainText('教務專用');
    await expect(page.locator('.role-btn', { hasText: '老師' })).toBeVisible();
    await expect(page.locator('.role-btn', { hasText: '主任/櫃台' })).toBeVisible();
    await expect(page.locator('#login-account')).toBeVisible();
    await expect(page.locator('#login-password')).toBeVisible();
    await expect(page.locator('button.login-btn')).toBeVisible();
    // 去 emoji：角色鈕不應再含 emoji 圖示節點
    await expect(page.locator('.role-icon')).toHaveCount(0);
  });

  test('empty submit shows client error without leaving login', async ({ page }) => {
    await page.locator('button.login-btn').click();
    await expect(page.locator('.login-error')).toBeVisible();
    await expect(page.locator('#login-account')).toBeVisible();
  });

  test('forgot password mode round-trips', async ({ page }) => {
    await page.locator('button.login-footer-btn', { hasText: '忘記密碼' }).click();
    await expect(page.locator('#forgot-account')).toBeVisible();
    await expect(page.locator('#forgot-role')).toBeVisible();
    await expect(page.locator('button.login-btn')).toContainText('送出重設申請');
    await page.locator('button.login-footer-btn', { hasText: '返回登入' }).click();
    await expect(page.locator('#login-account')).toBeVisible();
  });

  test('keyboard focus-visible reaches account field', async ({ page }) => {
    await page.locator('body').click({ position: { x: 2, y: 2 } });
    for (let i = 0; i < 12; i++) {
      await page.keyboard.press('Tab');
      const focused = page.locator(':focus');
      if (await focused.evaluate((el) => el?.id === 'login-account').catch(() => false)) {
        await expect(focused).toHaveCSS('box-shadow', /rgba?\(/);
        return;
      }
    }
    throw new Error('Tab did not reach #login-account');
  });

  test('prefers-reduced-motion still renders login', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('.login-wrap')).toBeVisible();
    await expect(page.locator('button.login-btn')).toBeVisible();
  });
});
