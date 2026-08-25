import { defineConfig } from '@playwright/test';
import baseConfig from './playwright.config.js';

// Production acceptance must never retain screenshots, video, traces, or an
// HTML report: the pages contain student/payment PII.
export default defineConfig({
  ...baseConfig,
  reporter: [['line']],
  retries: 0,
  use: {
    ...baseConfig.use,
    screenshot: 'off',
    video: 'off',
    trace: 'off',
  },
});
