import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';

// Real app + built Tailwind CSS, existing fail-closed fake-data preview. No staging.
export default defineConfig({
  testDir: '.',
  testMatch: 'parent-text-size.spec.js',
  timeout: 60_000,
  workers: 1,
  reporter: [['list'], ['json', { outputFile: fileURLToPath(new URL('../work/parent-text-size-results.json', import.meta.url)) }]],
  use: {
    baseURL: 'http://127.0.0.1:5189',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {},
    screenshot: 'only-on-failure',
  },
  webServer: {
    cwd: fileURLToPath(new URL('..', import.meta.url)),
    command: 'npm run build:preview && npx vite preview -c vite.preview.config.js --host 127.0.0.1 --port 5189 --strictPort',
    url: 'http://127.0.0.1:5189',
    timeout: 120_000,
    reuseExistingServer: false,
  },
});
