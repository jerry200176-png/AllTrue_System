// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Login.vue DS polish smoke（Epic #687 Login pilot）
 * 不需帳密：DOM 契約、radio 語意、錯誤態、忘記密碼、focus-visible、reduced-motion。
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
    await expect(page.locator('.role-icon')).toHaveCount(0);
  });

  test('role radios expose checked state and arrow-key move', async ({ page }) => {
    const teacher = page.locator('input.role-input[value="teacher"]');
    const director = page.locator('input.role-input[value="director"]');
    await expect(director).toBeChecked();
    await expect(teacher).not.toBeChecked();

    await director.focus();
    await page.keyboard.press('ArrowLeft');
    await expect(teacher).toBeChecked();
    await expect(director).not.toBeChecked();
    await expect(page.locator('.role-btn.active', { hasText: '老師' })).toBeVisible();

    // Selected must not keep a persistent focus ring after blur
    await page.locator('#login-account').click();
    const activeBox = page.locator('.role-btn.active');
    const shadow = await activeBox.evaluate((el) => getComputedStyle(el).boxShadow);
    expect(shadow === 'none' || shadow === '').toBeTruthy();
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

  test('prefers-reduced-motion zeros interactive transitions', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('.login-wrap')).toBeVisible();
    const btn = page.locator('button.login-btn');
    await expect(btn).toBeVisible();
    const duration = await btn.evaluate((el) => getComputedStyle(el).transitionDuration);
    // "0s" or "0s, 0s" depending on multi-property shorthand
    expect(duration.split(',').every((part) => part.trim() === '0s' || part.trim() === '0ms')).toBeTruthy();
  });
});
