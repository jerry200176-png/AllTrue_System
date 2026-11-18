import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

// Built preview app + stubbed /api/ (fake data), two viewports. Evidence only: no staging, no credentials.
export default defineConfig({
  testDir: '.',
  testMatch: 'receipt-move-preview.spec.js',
  timeout: 60_000,
  workers: 1,
  reporter: [['list'], ['json', { outputFile: fileURLToPath(new URL('../work/receipt-move-preview-results.json', import.meta.url)) }]],
  use: {
    baseURL: 'http://127.0.0.1:5191',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {},
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop-1536x784', use: { viewport: { width: 1536, height: 784 } } },
    { name: 'phone-375x812', use: { viewport: { width: 375, height: 812 } } },
  ],
  webServer: {
    cwd: fileURLToPath(new URL('..', import.meta.url)),
    command: 'npm run build:preview && npx vite preview -c vite.preview.config.js --host 127.0.0.1 --port 5191 --strictPort',
    url: 'http://127.0.0.1:5191',
    timeout: 120_000,
    reuseExistingServer: false,
  },
});
