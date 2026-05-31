// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * #547 / Epic #535 Phase 4.3 — 前端 UI smoke（最小集）。
 *
 * 設計原則：
 *  - 預設「無 secrets 即 skip」：未設定 SMOKE_BASE_URL 時 spec 內 test.skip()，
 *    讓本檔可安全存在於 repo、不阻塞任何人；實際執行需 #537 提供登入 secrets。
 *  - 只跑 chromium，single retry，trace/screenshot on-failure 以利分診。
 *  - 不掛在每次 PR 的 CI（避免 Actions minutes 浪費，見 OPERATIONS_RUNBOOK §B2）；
 *    由 .github/workflows/ui-smoke.yml 以 workflow_dispatch 手動 / 排程觸發。
 *
 * 本機跑：SMOKE_BASE_URL=https://daan.lifenet.com.tw \
 *         SMOKE_DIRECTOR_USER=... SMOKE_DIRECTOR_PASS=... \
 *         SMOKE_TEACHER_USER=... SMOKE_TEACHER_PASS=... \
 *         npm run test:e2e
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 45_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.SMOKE_BASE_URL || 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
