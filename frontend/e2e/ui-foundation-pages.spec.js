// @ts-check
/**
 * Merge-acceptance visual evidence: real NotificationsCenter Vue page
 * with deterministic API mocks. Does not use production SMOKE credentials.
 *
 * Students pilot coverage lives in the stacked follow-up PR.
 */
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || path.resolve(__dirname, '../../../docs/design/evidence/raw');

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '768', width: 768, height: 1024 },
  { name: '1440', width: 1440, height: 900 },
];

const CASE_NORMAL = {
  id: 'case-1001',
  lane: 'case',
  title: '請假申請',
  student_name: '測試學生甲',
  summary: '數學 · 請假後待安排補課',
  reason_preview: '身體不適請假一天',
  status_label: '待確認補課',
  status_code: 'pending_arrange',
  priority: 'due_soon',
  overdue: false,
  occurred_at: '2026-07-20T10:00:00+08:00',
  due_at: '2026-07-22T18:00:00+08:00',
  action: { label: '安排補課' },
};

const CASE_LONG = {
  ...CASE_NORMAL,
  id: 'case-1002',
  student_name: '測試學生超長姓名用於驗證折行與資訊層級是否清楚',
  summary: '英文進階班 · 家長來電說明臨時身體不適，希望改到下週同一時段，並請老師協助補齊當週進度與作業說明，文字需要足夠長以驗證版面。',
  reason_preview: '這段事由非常非常長，用來驗證長內容在真實 Vue 頁面上的折行、間距與 primary action 是否仍然清楚，不應水平爆版。'.repeat(2),
  overdue: true,
  priority: 'overdue',
};

async function installApiMocks(page, mode) {
  const hang = mode === 'loading';
  let releaseHang;
  const hangPromise = hang
    ? new Promise((resolve) => { releaseHang = resolve; })
    : Promise.resolve();

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    const method = route.request().method();

    if (method !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    if (hang && p.includes('/action-inbox')) {
      await hangPromise;
    }

    if (p.endsWith('/action-inbox/count')) {
      const empty = mode === 'empty';
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          notifications_unread: empty ? 0 : 4,
          cases_unresolved: empty ? 1 : 2,
          cases_overdue: empty ? 0 : 1,
          cases_due_soon: empty ? 0 : 1,
          cases_candidate_ready: empty ? 0 : 1,
          urgent_total: empty ? 0 : 1,
          badge_total: empty ? 1 : 6,
        }),
      });
    }

    if (p.includes('/action-inbox') && !p.includes('/count')) {
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '案件狀態暫時無法更新' }) });
      }
      if (mode === 'empty') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            cases: { data: [], total: 0, current_page: 1, last_page: 1, per_page: 20, has_more: false },
            summary: { cases_unresolved: 0 },
          }),
        });
      }
      const cases = mode === 'long' ? [CASE_LONG, CASE_NORMAL] : [CASE_NORMAL, { ...CASE_NORMAL, id: 'case-1003', student_name: '測試學生乙', overdue: true, priority: 'overdue' }];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          cases: {
            data: cases,
            total: cases.length,
            current_page: 1,
            last_page: mode === 'disabled' ? 3 : 1,
            per_page: 20,
            has_more: mode === 'disabled',
          },
          summary: { cases_unresolved: cases.length, cases_candidate_ready: 1 },
        }),
      });
    }

    if (p.includes('/notifications')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], current_page: 1, last_page: 1, total: 0 }),
      });
    }

    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  return { releaseHang: () => releaseHang?.() };
}

async function openPilot(page, { mode, viewport }) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const mocks = await installApiMocks(page, mode);
  await page.goto(`/pilot-mount.html?page=inbox&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return mocks;
}

test.describe('UI foundation — inbox Vue page evidence', () => {
  test.describe.configure({ mode: 'serial' });

  const inboxModes = ['normal', 'loading', 'empty', 'error', 'long', 'disabled'];

  for (const vp of viewports) {
    for (const mode of inboxModes) {
      test(`inbox ${mode} @${vp.name}`, async ({ page }) => {
        fs.mkdirSync(outDir, { recursive: true });
        const mocks = await openPilot(page, { mode: mode === 'disabled' ? 'disabled' : mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.getByTestId('at-skeleton')).toBeVisible({ timeout: 10_000 });
          await page.locator('.notifications-page').screenshot({
            path: path.join(outDir, `vue-inbox-loading-${vp.name}.png`),
          });
          mocks.releaseHang();
          return;
        }

        if (mode === 'empty') {
          await expect(page.getByText('目前沒有待辦案件')).toBeVisible({ timeout: 10_000 });
        } else if (mode === 'error') {
          await expect(page.getByText('案件狀態暫時無法更新').first()).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('主任收件匣')).toBeVisible({ timeout: 10_000 });
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible({ timeout: 10_000 });
        }

        if (mode === 'disabled') {
          const prev = page.getByRole('button', { name: '上一頁' }).first();
          if (await prev.count()) {
            await expect(prev).toBeDisabled();
          } else {
            await expect(page.getByRole('button', { name: '安排補課' }).first()).toBeEnabled();
          }
        }

        await page.locator('.notifications-page').screenshot({
          path: path.join(outDir, `vue-inbox-${mode}-${vp.name}.png`),
        });
      });
    }
  }
});
