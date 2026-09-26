// @ts-check
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  testMatch: /release-notes-clarity\.spec\.js$/,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5177',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'npx vite --config vite.ui-foundation.config.js',
    url: 'http://127.0.0.1:5177/release-notes-pilot-mount.html',
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
