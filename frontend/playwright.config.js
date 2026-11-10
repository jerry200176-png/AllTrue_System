// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * #547 / Epic #535 Phase 4.3 — 前端 UI smoke（最小集）。
 *
 * 設計原則：
 *  - 本機未設定 SMOKE_BASE_URL 時 spec 可 skip；正式 ui-smoke workflow
 *    先檢查必要 secrets，缺少時 job 失敗，不能以 skip 當作驗證通過。
 *  - 只跑 chromium；credentialed profile 關閉 capture，synthetic profile 保留分診產物。
 *  - .github/workflows/ui-smoke.yml 在 PR、workflow_dispatch、排程啟動；
 *    PR 在產品前端、smoke 定義、Playwright 設定、依賴或 workflow 有變動時
 *    執行瀏覽器測試。
 *
 * 本機跑：SMOKE_BASE_URL=https://daan.lifenet.com.tw \
 *         SMOKE_DIRECTOR_USER=... SMOKE_DIRECTOR_PASS=... \
 *         SMOKE_TEACHER_USER=... SMOKE_TEACHER_PASS=... \
 *         npm run test:e2e
 */
// #3767: login revokes the same user's active tokens from the same User-Agent
// (AuthController::revokeSameDeviceActiveTokens). Every run shares the stock
// headless-Chrome UA and the same SMOKE_* accounts, so parallel runs logged
// each other out. A per-run UA makes each run's session independent.
const runTag = process.env.GITHUB_RUN_ID
  ? `${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT || 1}`
  : `local-${process.pid}-${Date.now()}`;
const smokeUserAgent = `${devices['Desktop Chrome'].userAgent} AllTrueUISmoke/${runTag}`;
const privateSmoke = Boolean(process.env.SMOKE_BASE_URL);
// Pinned Playwright 1.64.1 otherwise adds failure DOM snapshots even with capture off.
if (privateSmoke) process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1';

export default defineConfig({
  testDir: './e2e',
  // Foundation page evidence uses playwright.ui-foundation.config.js + dedicated Vite mount.
  testIgnore: [
    /director-path-acceptance\.spec\.js$/, // Private acceptance uses its list-only config in the same smoke job.
    // Built fake-data preview on 5189; advisory ui-preview job runs its dedicated config.
    /parent-text-size\.spec\.js$/,
    // Executed by the required foundation suite against the dedicated Vue fixture server.
    /assessment-clarity\.spec\.js$/,
    /ui-foundation-pages\.spec\.js$/,
    /ui-foundation-a11y-axe\.spec\.js$/,
    /ui-foundation-role-matrix\.spec\.js$/,
    /ui-foundation-sidebar-navigation\.spec\.js$/,
    /ui-foundation-course-payment-entry\.spec\.js$/,
    /learning-records-polish\.spec\.js$/,
    /learning-records-preview\.spec\.js$/,
    /release-notes-clarity\.spec\.js$/,
    /teacher-daily-workflow\.spec\.js$/,
    /teacher-calendar-ux\.spec\.js$/,
    /calendar-split-slot-317\.spec\.js$/,
    /calendar-reschedule-cache-528\.spec\.js$/,
    /product-clarity-browser\.spec\.js$/,
    /admissions-workflow-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.ui-foundation.config.js.
    /admissions-clarity\.spec\.js$/,
    /bug-reports-clarity\.spec\.js$/,
    /profile-controls-clarity\.spec\.js$/,
    /question-bank-clarity\.spec\.js$/,
    /attendance-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.ui-foundation.config.js.
    /students-list-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.ui-foundation.config.js.
    /subject-settings-clarity\.spec\.js$/,
    /director-dashboard-shell-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in subject-units-playwright.config.js.
    /subject-units-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in teacher-eligibility-playwright.config.js.
    /teacher-eligibility-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.chat-accessibility.config.js.
    /chat-shell-clarity\.spec\.js$/,
    /chat-accessibility\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.tuition-collection.config.js.
    /tuition-collection-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.ui-foundation.config.js.
    /line-integration-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in playwright.ui-foundation.config.js.
    /branch-management-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server in the UI-foundation config.
    /binding-health-clarity\.spec\.js$/,
    /binding-management-clarity|nightly-reconcile-clarity|director-accounts-clarity|parttime-payroll-clarity\.spec\.js$/,
    // Uses the dedicated Vite fixture server and local PDF artifacts.
    /truefit-fixture-print\.spec\.js$/,
    // Vitest unit test for the acceptance read-only guard (vitest.config.js).
    /fixtures\/readOnlyAppGuard\.test\.js$/,
    // Node test-runner self-tests (privacy intentionally launches a failing synthetic browser test).
    /fixtures\/director(?:ExportGuard|Privacy)\.test\.js$/,
    // Uses only the loopback fixture served by playwright.overlay-layout.config.js.
    /overlay-layout\.spec\.js$/,
  ],
  timeout: 45_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: privateSmoke ? 'list' : process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  preserveOutput: privateSmoke ? 'never' : 'always',
  use: {
    baseURL: process.env.SMOKE_BASE_URL || 'http://localhost:5173',
    trace: privateSmoke ? 'off' : 'on-first-retry',
    screenshot: privateSmoke ? 'off' : 'only-on-failure',
    video: privateSmoke ? 'off' : 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'], userAgent: smokeUserAgent } },
  ],
});
