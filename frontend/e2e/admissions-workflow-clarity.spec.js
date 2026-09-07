// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../../docs/design/evidence/admissions-clarity');
const viewports = [
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'desktop-1280', width: 1280, height: 800 },
];

const makeInq = (id, status, student_name, subject, owner_name, follow_up_at, next_action, extra = {}) => ({
  id, campus_id: 1, status, student_name, parent_phone: '******5678', subject, owner_id: owner_name ? 9001 : null, owner_name: owner_name || null,
  follow_up_at, next_action, last_action_at: '2026-09-07T08:00:00Z', created_at: '2026-09-07T08:00:00Z', ...extra,
});
const MOCK_INQUIRIES = [
  makeInq(101, 'new', '王***', 'Math', null, null, 'claim'),
  makeInq(102, 'contacted', '林***', 'English', 'E2E Director', '2026-09-07', 'schedule_trial'),
  makeInq(103, 'contacted', '陳***', 'Physics', 'E2E Director', '2026-09-01', 'schedule_trial'),
  makeInq(104, 'trial_scheduled', '張***', 'Science', 'E2E Director', null, 'record_result', { trial_student_class_id: 501 }),
  makeInq(105, 'trial_completed', '李***', 'Chinese', 'E2E Director', null, 'enroll', { trial_student_class_id: 502, trial_result: 'attended' }),
  makeInq(106, 'trial_completed', '黃***', 'Social', 'E2E Director', null, 'enroll_or_lost', { trial_student_class_id: 503, trial_result: 'no_show' }),
  makeInq(107, 'enrolled', '周***', 'Math', 'E2E Director', null, 'done', { trial_student_class_id: 504, enrolled_student_class_id: 601 }),
  makeInq(108, 'lost', '孫***', 'Biology', 'E2E Director', null, 'done'),
  makeInq(109, 'new', '歐陽長名測試學員超長名稱驗證不爆版', 'Chemistry', null, null, 'claim'),
];

function getMockDetail(id) {
  const item = MOCK_INQUIRIES.find(i => i.id === Number(id)) || MOCK_INQUIRIES[0];
  return {
    ...item, student_name: item.student_name.replace(/\*/g, '明'), parent_name: '家長家長', parent_phone: '0912-345-678', grade: 'J1', school_name: '大安國中',
    preferred_slots: ['平日晚上', '週六下午'], public_notes: item.id === 109 ? '長備註說明文字測試手機排版。'.repeat(20) : '希望加強數學',
    staff_notes: item.status === 'contacted' ? '已與家長電訪確認時段' : '',
    history: [{ event_type: 'admission_inquiry.submit', outcome: 'success', reason_code: 'submit', occurred_at: '2026-09-07T08:00:00Z' }],
  };
}

async function installAdmissionsMock(page, { empty = false } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url()), p = url.pathname, method = route.request().method();
    if (p.includes('/branches') || p.includes('/branch')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 1, name: '大安分校' }, { id: 2, name: '木柵分校' }]) });
    }
    if (p.includes('/teachers')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 10, name: '林立文 老師' }, { id: 11, name: '張雅婷 老師' }]) });
    }
    if (p.endsWith('/admission-inquiries') && method === 'GET') {
      const sf = url.searchParams.get('status');
      let data = empty ? [] : MOCK_INQUIRIES;
      if (sf) data = data.filter(i => i.status === sf);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, current_page: 1, last_page: 1, per_page: 50, total: data.length }) });
    }
    const detailMatch = p.match(/\/admission-inquiries\/(\d+)$/);
    if (detailMatch && method === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(getMockDetail(detailMatch[1])) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok' }) });
  });
}

test.describe('Admissions Workflow Clarity Browser Verification', () => {
  test.beforeAll(async () => {
    if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true });
  });

  test('Zero inquiries state renders clear workflow guide and actions across all 3 viewports', async ({ page }) => {
    await installAdmissionsMock(page, { empty: true });
    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=empty');
      await page.waitForSelector('.admission-empty');

      await expect(page.locator('h1')).toHaveText('新生問班');
      await expect(page.locator('.admission-empty h2')).toHaveText('目前沒有新詢問');
      await expect(page.locator('.admission-empty-flow')).toBeVisible();
      await expect(page.locator('.admission-flow-step').nth(0)).toContainText('家長送出需求');
      await expect(page.locator('.admission-flow-step').nth(1)).toContainText('主任電訪確認');
      await expect(page.locator('.admission-flow-step').nth(2)).toContainText('安排體驗試聽');
      await expect(page.locator('.admission-flow-step').nth(3)).toContainText('試聽轉正報名');
      await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '複製公開問班連結' })).toBeVisible();
      await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '查看公開問班表單' })).toBeVisible();
      await expect(page.locator('.admission-empty-hint-card')).toContainText('家長若透過 LINE、電話或現場來訪？');

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
      expect(overflow).toBe(true);
      await page.screenshot({ path: path.join(outDir, `empty-state-${vp.name}.png`), fullPage: true });
    }
  });

  test('Populated queue renders urgency badges, pipeline stepper, and stage CTAs across viewports', async ({ page }) => {
    await installAdmissionsMock(page, { empty: false });
    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions');
      await page.waitForSelector('.admission-staff-grid');

      await expect(page.locator('.admission-queue-item')).toHaveCount(9);
      await expect(page.locator('.admission-queue-item').nth(0)).toContainText('王***');
      await expect(page.locator('.admission-queue-item').nth(0)).toContainText('******5678');
      await expect(page.locator('.admission-stat-chip.urgent')).toContainText('需盡速聯絡');
      await expect(page.locator('.admission-stat-chip.unassigned')).toContainText('待認領');

      await expect(page.locator('.admission-pipeline-wrapper')).toBeVisible();
      await expect(page.locator('.admission-pipeline-node').nth(0)).toHaveClass(/current/);
      await expect(page.locator('.admission-direct-trial-toggle')).toBeVisible();

      await page.locator('.admission-queue-item').nth(1).click();
      await expect(page.locator('.admission-pipeline-wrapper')).toBeVisible();
      await expect(page.locator('.admission-pipeline-node').nth(1)).toHaveClass(/current/);
      await expect(page.locator('.admission-action-buttons').getByRole('button', { name: '建立試聽（帶入學生資料）' })).toBeVisible();

      await page.locator('.admission-queue-item').nth(3).click();
      await expect(page.locator('.admission-pipeline-node').nth(2)).toHaveClass(/current/);
      await expect(page.locator('.admission-action-buttons').getByRole('button', { name: '儲存結果' })).toBeVisible();

      await page.locator('.admission-queue-item').nth(4).click();
      await expect(page.locator('.admission-pipeline-node').nth(3)).toHaveClass(/current/);
      await expect(page.locator('.admission-action-buttons').getByRole('button', { name: '轉正式報名' })).toBeVisible();

      await page.locator('.admission-queue-item').nth(6).click();
      await expect(page.locator('.admission-pipeline-node').nth(4)).toHaveClass(/current/);
      await expect(page.locator('.admission-detail').getByText('已連結正式課程')).toBeVisible();

      await page.locator('.admission-queue-item').nth(7).click();
      await expect(page.locator('.admission-detail').getByText('此詢問已結案（暫不繼續）')).toBeVisible();

      await page.locator('.admission-queue-item').nth(8).click();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
      expect(overflow).toBe(true);
      await page.screenshot({ path: path.join(outDir, `populated-queue-${vp.name}.png`), fullPage: true });
    }
  });

  test('Public link copy feedback works and copies expected campus-specific route', async ({ page, context }) => {
    await installAdmissionsMock(page, { empty: true });
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=empty&branch=1');
    await page.waitForSelector('.admission-empty');

    const copyBtn = page.locator('.admission-empty-actions').getByRole('button', { name: '複製公開問班連結' });
    await copyBtn.click();
    await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '已複製問班連結！' })).toBeVisible();

    const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboardText).toContain('#/admissions?branch=1');
    expect(clipboardText).not.toContain('token');
    expect(clipboardText).not.toContain('jwt');
    expect(clipboardText).not.toContain('director');
  });

  test('Standalone public page consumes branch context and preselects campus', async ({ page }) => {
    await installAdmissionsMock(page, { empty: true });

    await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=public&branch=2#/admissions?branch=2');
    await page.waitForSelector('#admission-campus');
    await expect(page.locator('#admission-campus')).toHaveValue('2');
    await expect(page.locator('.admission-branch-preset-hint')).toContainText('已為您預選「木柵分校」');

    await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=public&branch=1#/admissions?branch=1');
    await page.waitForSelector('#admission-campus');
    await expect(page.locator('#admission-campus')).toHaveValue('1');
    await expect(page.locator('.admission-branch-preset-hint')).toContainText('已為您預選「大安分校」');
  });
});
