// Focused synthetic rendering check. Does not change the release/CI configurations.
import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './e2e', testMatch: 'overlay-layout.spec.js', workers: 1, retries: 0,
  reporter: 'list', outputDir: '../work/overlay-layout-results',
  use: { baseURL: 'http://127.0.0.1:5189', reducedMotion: 'reduce', launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } },
  webServer: {
    command: 'node e2e/fixtures/ui-foundation/overlay-layout-server.mjs',
    url: 'http://127.0.0.1:5189/overlay-layout.html', reuseExistingServer: false,
  },
});
