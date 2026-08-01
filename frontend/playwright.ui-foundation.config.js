// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Local / CI config for UI foundation page-level evidence.
 * Serves e2e/fixtures/ui-foundation via vite.ui-foundation.config.js (not production dist).
 */
export default defineConfig({
  testDir: './e2e',
  testMatch: /(?:ui-foundation-pages|learning-records-polish)\.spec\.js$/,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://127.0.0.1:5177',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'npx vite --config vite.ui-foundation.config.js',
    url: 'http://127.0.0.1:5177/pilot-mount.html',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
