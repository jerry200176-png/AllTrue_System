import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: /teacher-eligibility-clarity\.spec\.js$/,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  workers: 1,
  reporter: 'list',
  use: { baseURL: 'http://127.0.0.1:5177', screenshot: 'only-on-failure' },
  webServer: {
    command: 'npx vite --config ../vite.ui-foundation.config.js',
    url: 'http://127.0.0.1:5177/teacher-eligibility-pilot-mount.html',
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
