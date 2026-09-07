// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const VIEWPORTS = [
  { name: '390_mobile', width: 390, height: 844 },
  { name: '768_tablet', width: 768, height: 1024 },
  { name: '1440_desktop', width: 1440, height: 900 },
];

const auditLog = [];

test.afterAll(() => {
  const logPath = path.resolve(__dirname, '../../artifacts/product-clarity-browser-evidence.json');
  fs.mkdirSync(path.dirname(logPath), { recursive: true });
  fs.writeFileSync(logPath, JSON.stringify(auditLog, null, 2), 'utf8');
  console.log(`\n=== REAL BROWSER EVIDENCE AUDIT LOG (${auditLog.length} steps recorded) ===`);
  for (const entry of auditLog) {
    console.log(`[${entry.route} | ${entry.viewport}] ${entry.action} -> ${entry.actualResult} (errors: ${entry.errors.length})`);
  }
});

function setupErrorTracking(page) {
  const errors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') errors.push(`Console: ${msg.text()}`); });
  page.on('pageerror', (err) => { errors.push(`Page: ${err.message}`); });
  page.on('requestfailed', (req) => { errors.push(`Network: ${req.method()} ${req.url()}`); });
  return errors;
}

async function installUnifiedMocks(page, { failTuitionFirst = false } = {}) {
  let tuitionAttempts = 0;
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;

    if (p.endsWith('/action-inbox/count')) {
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ notifications_unread: 2, cases_unresolved: 1, cases_open: 1, cases_overdue: 0, cases_due_soon: 1, cases_candidate_ready: 1, urgent_total: 1, badge_total: 3 }),
      });
    }
    if (p.endsWith('/action-inbox')) {
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          cases: {
            data: [{ id: 'c1', lane: 'case', title: '請假申請', student_name: '測試學生甲', summary: '英文進階班 · 臨時請假一次', reason_preview: '流感發燒在家休養', status_label: '待確認補課', status_code: 'pending_arrange', priority: 'due_soon', overdue: false, occurred_at: '2026-09-07T09:00:00+08:00', due_at: '2026-09-08T18:00:00+08:00' }],
            total: 1, current_page: 1, last_page: 1, has_more: false,
          },
          summary: { cases_unresolved: 1, cases_candidate_ready: 1 },
        }),
      });
    }
    if (p.endsWith('/notifications')) {
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 801, Title: '合約堂數變動通知', Body: '學生合約已調整', Type: 'StudentClass', SourceType: 'StudentClass', Severity: 'normal', read_at: null, created_at: '2026-09-07T08:30:00+08:00' },
            { id: 802, Title: '學費繳款提醒', Body: '當月份學費待收繳', Type: 'Invoice', SourceType: 'Invoice', Severity: 'normal', read_at: null, created_at: '2026-09-07T08:00:00+08:00' },
          ],
          current_page: 1, last_page: 1, total: 2,
        }),
      });
    }
    if (p.includes('/schedule-discrepancies/summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 }) });
    }
    if (p.includes('/schedule-discrepancies')) {
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          data: [{ id: 701, reporter_id: 301, reporter_name: null, branch_id: 101, branch_name: null, student_name: '測試學生甲', session_date: '2026-09-07', time_range: '14:00–16:00', corrected_time_range: '14:30–16:30', discrepancy_type: 'wrong_time', discrepancy_type_label: '上課時間有誤', status: 'pending', class_session_id: 8888, notes: '現場提早半小時上課。' }],
          meta: { current_page: 1, last_page: 1, total: 1 },
        }),
      });
    }
    if (p.includes('/branch-monthly-tuition')) {
      tuitionAttempts += 1;
      if (failTuitionFirst && tuitionAttempts === 1) {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '連線逾時，無法取得學收資料' }) });
      }
      return route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { student_class_id: 101, student_name: '測試學生甲', subject: '國中數學', teacher_name: '王老師', class_type: 'one_on_one', monthly_sessions: 4, rate: 1500, monthly_tuition: 6000, last_paid_at: '2026-09-01' },
            { student_class_id: 102, student_name: '測試學生乙', subject: '高中英文', teacher_name: '李老師', class_type: 'one_on_two', monthly_sessions: 4, rate: 1200, monthly_tuition: 4800, last_paid_at: '2026-09-02' },
          ],
          summary: { total_students: 2, total_sessions: 8, total_tuition: 10800 },
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
        }),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Product Clarity — Real Browser Verification', () => {
  for (const vp of VIEWPORTS) {
    test(`1. Notifications Center — ${vp.name}`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await installUnifiedMocks(page);

      await page.goto('/pilot-mount.html?page=inbox');
      await page.waitForSelector('.at-page-header');
      await page.waitForSelector('.leave-case-item');

      // Check redundant instruction removed
      const redundantSteps = await page.locator('.leave-case-step').count();
      expect(redundantSteps).toBe(0);
      auditLog.push({ route: 'inbox', viewport: vp.name, action: 'Inspect redundant instruction divs', expectedResult: '0', actualResult: `${redundantSteps} (PASS)`, errors: [...errors] });

      // Check header guidance preserved
      const headerGuidance = await page.locator('.leave-case-list__intro').textContent();
      expect(headerGuidance).toContain('先選補課時段，再核准請假');

      // Check translated SourceType labels
      await page.click('#notifications-tab-ops');
      await page.waitForSelector('.notification-item');
      expect(await page.locator('text=來源：課程合約').first().isVisible()).toBe(true);
      expect(await page.locator('text=來源：學費帳單').first().isVisible()).toBe(true);
      expect(await page.locator('text=來源：StudentClass').count()).toBe(0);
      expect(await page.locator('text=來源：Invoice').count()).toBe(0);
      auditLog.push({ route: 'inbox', viewport: vp.name, action: 'Verify SourceType translation', expectedResult: 'Chinese labels', actualResult: 'PASS', errors: [...errors] });
    });

    test(`2. Schedule Discrepancy — ${vp.name}`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await installUnifiedMocks(page);

      await page.goto('/pilot-mount.html?page=discrepancy');
      await page.waitForSelector('.sdp-sop-card');

      // Progressive disclosure of SOP card
      const sopCard = page.locator('.sdp-sop-card');
      expect(await sopCard.evaluate((el) => el.hasAttribute('open'))).toBe(false);
      await page.click('.sdp-sop-card summary');
      expect(await sopCard.evaluate((el) => el.hasAttribute('open'))).toBe(true);
      await page.click('.sdp-sop-card summary');
      expect(await sopCard.evaluate((el) => el.hasAttribute('open'))).toBe(false);
      auditLog.push({ route: 'discrepancy', viewport: vp.name, action: 'Toggle SOP details card', expectedResult: 'Expands/collapses', actualResult: 'PASS', errors: [...errors] });

      // Check fallbacks & terminology
      if (vp.width > 768) {
        await page.waitForSelector('.sdp-table');
        const teacherCell = await page.locator('.sdp-table tbody td').nth(1).textContent();
        const branchCell = await page.locator('.sdp-table tbody td').nth(2).textContent();
        expect(teacherCell?.trim()).toBe('未提供老師姓名');
        expect(branchCell?.trim()).toBe('未指定分校');

        await page.click('.sdp-table tbody button.ghost');
        await page.waitForSelector('.sdp-detail-grid');
        expect(await page.locator('.sdp-detail-label:has-text("堂次編號")').isVisible()).toBe(true);
        expect(await page.locator('.sdp-detail-label:has-text("堂次 ID")').count()).toBe(0);
        auditLog.push({ route: 'discrepancy', viewport: vp.name, action: 'Inspect desktop fallback labels & domain term', expectedResult: '未提供老師姓名, 堂次編號', actualResult: 'PASS', errors: [...errors] });
      } else {
        await page.waitForSelector('.sdp-mobile');
        const cardText = await page.locator('.sdp-mcard').first().textContent();
        expect(cardText).toContain('未提供老師姓名');
        expect(cardText).toContain('未指定分校');
        auditLog.push({ route: 'discrepancy', viewport: vp.name, action: 'Inspect mobile card fallback labels', expectedResult: '未提供老師姓名, 未指定分校', actualResult: 'PASS', errors: [...errors] });
      }
    });

    test(`3. Tuition Report — ${vp.name}`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await installUnifiedMocks(page, { failTuitionFirst: true });

      await page.goto('/pilot-mount.html?page=tuition-report');

      // Observe 500 error & retry button
      const errorAlert = page.locator('.tr-error[role="alert"]');
      await expect(errorAlert).toBeVisible();
      const retryBtn = page.locator('.tr-retry-btn');
      await expect(retryBtn).toBeVisible();
      expect(await retryBtn.textContent()).toBe('再試一次');
      auditLog.push({ route: 'tuition-report', viewport: vp.name, action: 'Observe error state with retry button', expectedResult: 'Alert banner + retry button', actualResult: 'PASS', errors: [...errors] });

      // Click retry and verify recovery
      await retryBtn.click();
      await page.waitForSelector('.tr-stats');
      await expect(errorAlert).not.toBeVisible();
      const statsText = await page.locator('.tr-stats').textContent();
      expect(statsText).toContain('2 人');
      expect(statsText).toContain('8 堂');
      expect(statsText).toContain('10,800');
      expect(await page.locator('.tr-table tbody tr').count()).toBe(2);
      auditLog.push({ route: 'tuition-report', viewport: vp.name, action: 'Click retry and verify recovery', expectedResult: 'Data loaded successfully', actualResult: 'PASS', errors: [...errors] });
    });
  }
});
