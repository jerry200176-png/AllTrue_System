// @ts-check
/**
 * DRAFT — install path: frontend/playwright.agreed-fee-isolated.config.js
 * Explicit AGREED_FEE_ISOLATED_* gate. Never uses SMOKE_BASE_URL / production.
 */
import { defineConfig, devices } from '@playwright/test';

const port = 5179;
const base = process.env.AGREED_FEE_ISOLATED_BASE_URL || `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './e2e',
  testMatch: /agreed-fee-isolated-fullstack\.spec\.js$/,
  timeout: 90_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: base,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  // Stack script already boots Vite+API; reuse when CI/stack left them up.
  webServer: process.env.AGREED_FEE_STACK_EXTERNAL === '1'
    ? undefined
    : {
        command: `npx vite --config vite.agreed-fee-isolated.config.js`,
        url: `http://127.0.0.1:${port}/agreed-fee-isolated.html`,
        reuseExistingServer: true,
        timeout: 120_000,
      },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
