// @ts-check
/**
 * DRAFT — proposed path: frontend/playwright.agreed-fee-isolated.config.js
 * Explicit env gate. Never uses SMOKE_BASE_URL / production.
 */
import { defineConfig, devices } from '@playwright/test';

const port = 5179;

export default defineConfig({
  testDir: './e2e',
  testMatch: /agreed-fee-isolated-fullstack\.spec\.js$/,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.AGREED_FEE_ISOLATED_BASE_URL || `http://127.0.0.1:${port}`,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  webServer: {
    // After landing: vite.agreed-fee-isolated.config.js serving a page that
    // mounts AgreedFeeCorrectionModal and proxies /api → :8000 (or absolute API URL).
    command: `npx vite --host 127.0.0.1 --port ${port} --strictPort --config vite.agreed-fee-isolated.proposed.config.js`,
    url: `http://127.0.0.1:${port}/agreed-fee-isolated.html`,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
