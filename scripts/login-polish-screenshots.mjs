#!/usr/bin/env node
/**
 * Login.vue visual evidence: 390 / 768 / 1440 screenshots.
 *
 * Usage (Vite already running):
 *   node scripts/login-polish-screenshots.mjs before
 *   node scripts/login-polish-screenshots.mjs after
 *   LOGIN_POLISH_OUT=/abs/path node scripts/login-polish-screenshots.mjs after
 *   node scripts/login-polish-screenshots.mjs after http://127.0.0.1:5173/ ./docs/reviews/login-polish-1386
 *
 * Args: <before|after> [baseUrl] [outRoot]
 * Env:  LOGIN_POLISH_BASE_URL, LOGIN_POLISH_OUT
 * Default outRoot: <repo>/artifacts/login-polish/<phase> (gitignored)
 */
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');
const require = createRequire(join(root, 'frontend/package.json'));
const { chromium } = require('playwright');

const phase = process.argv[2] === 'after' ? 'after' : 'before';
const baseUrl = process.argv[3] || process.env.LOGIN_POLISH_BASE_URL || 'http://127.0.0.1:5173/';
const outRoot = resolve(
  process.argv[4]
    || process.env.LOGIN_POLISH_OUT
    || join(root, 'artifacts', 'login-polish', phase),
);
mkdirSync(outRoot, { recursive: true });

const widths = [390, 768, 1440];

async function shot(page, name) {
  for (const w of widths) {
    await page.setViewportSize({ width: w, height: w < 768 ? 844 : 900 });
    await page.waitForTimeout(200);
    const path = join(outRoot, `${name}-${w}.png`);
    await page.screenshot({ path, fullPage: true });
    console.log('wrote', path);
  }
}

const browser = await chromium.launch({ headless: true });
const page = await (await browser.newContext()).newPage();

await page.addInitScript(() => {
  localStorage.removeItem('alltrue_session');
  localStorage.removeItem('parent_portal_token');
});

await page.goto(baseUrl, { waitUntil: 'networkidle', timeout: 60_000 });
await page.locator('.login-wrap').waitFor({ timeout: 30_000 });

await shot(page, 'login-default');

await page.locator('button.login-btn').click();
await page.locator('.login-error').waitFor({ timeout: 5_000 });
await shot(page, 'login-error');

await page.locator('button.login-footer-btn', { hasText: '忘記密碼' }).click();
await page.locator('#forgot-account').waitFor({ timeout: 5_000 });
await shot(page, 'forgot-default');

await page.keyboard.press('Tab');
await page.waitForTimeout(150);
await shot(page, 'forgot-focus');

await page.emulateMedia({ reducedMotion: 'reduce' });
await page.locator('button.login-footer-btn', { hasText: '返回登入' }).click();
await page.locator('.login-wrap').waitFor();
await shot(page, 'login-reduced-motion');

await browser.close();
console.log('done', phase, outRoot);
