// @ts-check
// Visual regression (#4952). Run inside the Playwright Docker image: npm run test:visual[:update].
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  testMatch: /ui-foundation-pages\.spec\.js$/,
  grep: /@visual/,
  snapshotPathTemplate: 'e2e/__screenshots__/{arg}{ext}',
  timeout: 60_000,
  expect: {
    timeout: 15_000,
    toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.01 },
  },
  workers: 1,
  reporter: 'list',
  outputDir: 'test-results-visual',
  use: { baseURL: 'http://127.0.0.1:5177' },
  webServer: {
    command: 'npx vite --config vite.ui-foundation.config.js',
    url: 'http://127.0.0.1:5177/pilot-mount.html',
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
