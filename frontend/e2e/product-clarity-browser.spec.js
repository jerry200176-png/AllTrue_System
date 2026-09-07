// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const shotDir = path.resolve(__dirname, '../../docs/screenshots');

if (!fs.existsSync(shotDir)) {
  fs.mkdirSync(shotDir, { recursive: true });
}

const VIEWPORTS = [
  { name: '390_mobile', width: 390, height: 844 },
  { name: '768_tablet', width: 768, height: 1024 },
  { name: '1440_desktop', width: 1440, height: 900 },
];

/**
 * Audit log recording real browser verification evidence:
 * route, viewport, action performed, expected result, actual result, console/network errors
 */
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
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      errors.push(`Console error: ${msg.text()}`);
    }
  });
  page.on('pageerror', (err) => {
    errors.push(`Page error: ${err.message}`);
  });
  page.on('requestfailed', (req) => {
    errors.push(`Network failed: ${req.method()} ${req.url()}`);
  });
  return errors;
}

test.describe('Product Clarity — Real Browser Verification', () => {

  for (const vp of VIEWPORTS) {
    test(`1. Notifications Center — ${vp.name} (${vp.width}x${vp.height})`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });

      await page.route('**/api/v1/**', async (route) => {
        const url = new URL(route.request().url());
        const p = url.pathname;

        if (p.endsWith('/action-inbox/count')) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              notifications_unread: 2,
              cases_unresolved: 1,
              cases_open: 1,
              cases_overdue: 0,
              cases_due_soon: 1,
              cases_candidate_ready: 1,
              urgent_total: 1,
              badge_total: 3,
            }),
          });
        }

        if (p.endsWith('/action-inbox')) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              cases: {
                data: [
                  {
                    id: 'case-demo-1',
                    lane: 'case',
                    title: '請假申請',
                    student_name: '測試學生甲',
                    summary: '英文進階班 · 臨時身體不適請假一次',
                    reason_preview: '流感發燒在家休養，預計週五返校',
                    status_label: '待確認補課',
                    status_code: 'pending_arrange',
                    priority: 'due_soon',
                    overdue: false,
                    occurred_at: '2026-09-07T09:00:00+08:00',
                    due_at: '2026-09-08T18:00:00+08:00',
                  },
                ],
                total: 1,
                current_page: 1,
                last_page: 1,
                has_more: false,
              },
              summary: { cases_unresolved: 1, cases_candidate_ready: 1 },
            }),
          });
        }

        if (p.endsWith('/notifications')) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              data: [
                {
                  id: 801,
                  Title: '合約堂數變動通知',
                  Body: '學生測試甲課程合約已由主任核准調整',
                  Type: 'StudentClass',
                  SourceType: 'StudentClass',
                  Severity: 'normal',
                  read_at: null,
                  created_at: '2026-09-07T08:30:00+08:00',
                },
                {
                  id: 802,
                  Title: '學費繳款提醒',
                  Body: '當月份學雜費待收繳',
                  Type: 'Invoice',
                  SourceType: 'Invoice',
                  Severity: 'normal',
                  read_at: null,
                  created_at: '2026-09-07T08:00:00+08:00',
                },
              ],
              current_page: 1,
              last_page: 1,
              total: 2,
            }),
          });
        }

        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      });

      await page.goto('/pilot-mount.html?page=inbox');
      await page.waitForSelector('.at-page-header');
      await page.waitForSelector('.leave-case-item');

      // Check 1: Redundant repeated loop instruction removed
      const redundantSteps = await page.locator('.leave-case-step').count();
      expect(redundantSteps).toBe(0);

      auditLog.push({
        route: '/pilot-mount.html?page=inbox',
        viewport: vp.name,
        action: 'Inspect leave case items for redundant .leave-case-step divs',
        expectedResult: '0 occurrences (removed to reduce visual repetition)',
        actualResult: `0 occurrences found (PASS)`,
        errors: [...errors],
      });

      // Check 2: Header high-level guidance preserved
      const headerGuidance = await page.locator('.leave-case-list__intro').textContent();
      expect(headerGuidance).toContain('先選補課時段，再核准請假');

      auditLog.push({
        route: '/pilot-mount.html?page=inbox',
        viewport: vp.name,
        action: 'Verify high-level process guidance remains in section intro',
        expectedResult: 'Section header contains "先選補課時段，再核准請假"',
        actualResult: 'Preserved correctly in header intro (PASS)',
        errors: [...errors],
      });

      // Check 3: Translated SourceType labels in operations notifications tab
      await page.click('#notifications-tab-ops');
      await page.waitForSelector('.notification-item');

      const contractLabel = await page.locator('text=來源：課程合約').first().isVisible();
      const invoiceLabel = await page.locator('text=來源：學費帳單').first().isVisible();
      expect(contractLabel).toBe(true);
      expect(invoiceLabel).toBe(true);

      const rawStudentClass = await page.locator('text=來源：StudentClass').count();
      const rawInvoice = await page.locator('text=來源：Invoice').count();
      expect(rawStudentClass).toBe(0);
      expect(rawInvoice).toBe(0);

      auditLog.push({
        route: '/pilot-mount.html?page=inbox',
        viewport: vp.name,
        action: 'Switch to operations notifications and verify SourceType translation',
        expectedResult: 'Displays "來源：課程合約" and "來源：學費帳單", 0 raw PascalCase',
        actualResult: 'Chinese labels verified, 0 raw backend model leaks (PASS)',
        errors: [...errors],
      });

      // Check 4: Capture sanitized desktop screenshot for README showcase
      if (vp.width === 1440) {
        await page.click('#notifications-tab-cases');
        await page.waitForSelector('.leave-case-item');
        await page.screenshot({ path: path.join(shotDir, 'notifications-center-desktop.png'), fullPage: false });
      }
    });

    test(`2. Schedule Discrepancy — ${vp.name} (${vp.width}x${vp.height})`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });

      await page.route('**/api/v1/**', async (route) => {
        const url = new URL(route.request().url());
        const p = url.pathname;

        if (p.includes('/schedule-discrepancies/summary')) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 }),
          });
        }

        if (p.includes('/schedule-discrepancies')) {
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              data: [
                {
                  id: 701,
                  reporter_id: 301,
                  reporter_name: null, // Fallback test
                  branch_id: 101,
                  branch_name: null,   // Fallback test
                  student_name: '測試學生甲',
                  session_date: '2026-09-07',
                  time_range: '14:00–16:00',
                  corrected_time_range: '14:30–16:30',
                  discrepancy_type: 'wrong_time',
                  discrepancy_type_label: '上課時間有誤',
                  status: 'pending',
                  class_session_id: 8888,
                  notes: '現場提早半小時上課，需要同步調整系統堂次時間以利記錄。',
                },
              ],
              meta: { current_page: 1, last_page: 1, total: 1 },
            }),
          });
        }

        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      });

      await page.goto('/pilot-mount.html?page=discrepancy');
      await page.waitForSelector('.sdp-sop-card');

      // Check 1: Progressive disclosure of SOP banner
      const sopCard = page.locator('.sdp-sop-card');
      const isOpenInitial = await sopCard.evaluate((el) => el.hasAttribute('open'));
      expect(isOpenInitial).toBe(false);

      auditLog.push({
        route: '/pilot-mount.html?page=discrepancy',
        viewport: vp.name,
        action: 'Verify initial state of SOP details card',
        expectedResult: 'Collapsed by default (open attribute is false)',
        actualResult: `Initial open=${isOpenInitial} (PASS)`,
        errors: [...errors],
      });

      // Expand SOP
      await page.click('.sdp-sop-card summary');
      const isOpenAfterClick = await sopCard.evaluate((el) => el.hasAttribute('open'));
      expect(isOpenAfterClick).toBe(true);

      // Collapse SOP
      await page.click('.sdp-sop-card summary');
      const isOpenAfterSecondClick = await sopCard.evaluate((el) => el.hasAttribute('open'));
      expect(isOpenAfterSecondClick).toBe(false);

      auditLog.push({
        route: '/pilot-mount.html?page=discrepancy',
        viewport: vp.name,
        action: 'Toggle SOP details card expand and collapse via summary',
        expectedResult: 'Expands on click 1, collapses on click 2',
        actualResult: `Expanded=${isOpenAfterClick}, Collapsed=${!isOpenAfterSecondClick} (PASS)`,
        errors: [...errors],
      });

      // Check 2: Responsive viewports & fallback labels
      if (vp.width > 768) {
        // Desktop table view
        await page.waitForSelector('.sdp-table');
        const teacherCell = await page.locator('.sdp-table tbody td').nth(1).textContent();
        const branchCell = await page.locator('.sdp-table tbody td').nth(2).textContent();
        expect(teacherCell?.trim()).toBe('未提供老師姓名');
        expect(branchCell?.trim()).toBe('未指定分校');
        expect(teacherCell).not.toContain('#301');
        expect(branchCell).not.toContain('#101');

        // Check drawer label
        await page.click('.sdp-table tbody button.ghost');
        await page.waitForSelector('.sdp-detail-grid');
        const sessionLabel = await page.locator('.sdp-detail-label:has-text("堂次編號")').isVisible();
        expect(sessionLabel).toBe(true);
        const legacyIdLabel = await page.locator('.sdp-detail-label:has-text("堂次 ID")').count();
        expect(legacyIdLabel).toBe(0);

        auditLog.push({
          route: '/pilot-mount.html?page=discrepancy',
          viewport: vp.name,
          action: 'Inspect desktop table fallback labels and drawer domain terminology',
          expectedResult: '"未提供老師姓名", "未指定分校", label "堂次編號", no raw IDs',
          actualResult: `Teacher: ${teacherCell?.trim()}, Branch: ${branchCell?.trim()}, Term: 堂次編號 (PASS)`,
          errors: [...errors],
        });

        // Capture sanitized desktop screenshot for README showcase
        await page.screenshot({ path: path.join(shotDir, 'schedule-discrepancy-desktop.png'), fullPage: false });
      } else {
        // Mobile / Tablet card view
        await page.waitForSelector('.sdp-mobile');
        const mobileCard = page.locator('.sdp-mcard').first();
        await expect(mobileCard).toBeVisible();
        const cardText = await mobileCard.textContent();
        expect(cardText).toContain('未提供老師姓名');
        expect(cardText).toContain('未指定分校');
        expect(cardText).not.toContain('#301');
        expect(cardText).not.toContain('#101');

        auditLog.push({
          route: '/pilot-mount.html?page=discrepancy',
          viewport: vp.name,
          action: 'Inspect mobile card fallback labels',
          expectedResult: 'Cards show "未提供老師姓名" and "未指定分校" without raw IDs',
          actualResult: 'Mobile card fallbacks displayed cleanly (PASS)',
          errors: [...errors],
        });
      }
    });

    test(`3. Tuition Report — ${vp.name} (${vp.width}x${vp.height})`, async ({ page }) => {
      const errors = setupErrorTracking(page);
      await page.setViewportSize({ width: vp.width, height: vp.height });

      let attempts = 0;

      await page.route('**/api/v1/**', async (route) => {
        const url = new URL(route.request().url());
        const p = url.pathname;

        if (p.includes('/branch-monthly-tuition')) {
          attempts += 1;
          if (attempts === 1) {
            // First attempt: simulate 500 error
            return route.fulfill({
              status: 500,
              contentType: 'application/json',
              body: JSON.stringify({ message: '連線逾時，無法取得學收資料' }),
            });
          }
          // Subsequent attempt: successful load with clean demo rows
          return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              data: [
                {
                  student_class_id: 101,
                  student_name: '測試學生甲',
                  subject: '國中數學',
                  teacher_name: '王老師',
                  class_type: 'one_on_one',
                  monthly_sessions: 4,
                  rate: 1500,
                  monthly_tuition: 6000,
                  last_paid_at: '2026-09-01',
                },
                {
                  student_class_id: 102,
                  student_name: '測試學生乙',
                  subject: '高中英文',
                  teacher_name: '李老師',
                  class_type: 'one_on_two',
                  monthly_sessions: 4,
                  rate: 1200,
                  monthly_tuition: 4800,
                  last_paid_at: '2026-09-02',
                },
                {
                  student_class_id: 103,
                  student_name: '測試學生丙超長姓名用於驗證排版寬度與數字對齊',
                  subject: '國中理化進階實驗班',
                  teacher_name: '張老師',
                  class_type: 'tutoring',
                  monthly_sessions: 8,
                  rate: 900,
                  monthly_tuition: 7200,
                  last_paid_at: null,
                },
              ],
              summary: {
                total_students: 3,
                total_sessions: 16,
                total_tuition: 18000,
              },
              meta: { current_page: 1, last_page: 1, per_page: 50, total: 3 },
            }),
          });
        }

        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      });

      await page.goto('/pilot-mount.html?page=tuition-report');

      // Check 1: Actionable error state with retry button on first attempt
      const errorAlert = page.locator('.tr-error[role="alert"]');
      await expect(errorAlert).toBeVisible();
      const errorText = await page.locator('.tr-error-msg').textContent();
      expect(errorText).toContain('載入失敗');

      const retryBtn = page.locator('.tr-retry-btn');
      await expect(retryBtn).toBeVisible();
      expect(await retryBtn.textContent()).toBe('再試一次');

      auditLog.push({
        route: '/pilot-mount.html?page=tuition-report',
        viewport: vp.name,
        action: 'Observe initial 500 error state banner',
        expectedResult: 'Alert banner with role="alert", error message, and "再試一次" retry button',
        actualResult: `Error banner visible with retry button (PASS)`,
        errors: [...errors],
      });

      // Check 2: Click retry button and verify state transition to success
      await retryBtn.click();
      await page.waitForSelector('.tr-stats');

      // Verify error alert is dismissed
      await expect(errorAlert).not.toBeVisible();

      // Verify summary numbers
      const statsText = await page.locator('.tr-stats').textContent();
      expect(statsText).toContain('3 人');
      expect(statsText).toContain('16 堂');
      expect(statsText).toContain('18,000');

      // Verify rows rendered
      const rows = await page.locator('.tr-table tbody tr').count();
      expect(rows).toBe(3);

      auditLog.push({
        route: '/pilot-mount.html?page=tuition-report',
        viewport: vp.name,
        action: 'Click "再試一次" retry button and verify recovery',
        expectedResult: 'Error cleared, summary stats and 3 table rows rendered with formatted tuition',
        actualResult: `Loaded successfully: 3 rows, total tuition 18,000 (PASS)`,
        errors: [...errors],
      });

      // Check 3: Capture sanitized desktop screenshot for README showcase
      if (vp.width === 1440) {
        await page.screenshot({ path: path.join(shotDir, 'tuition-report-desktop.png'), fullPage: false });
      }
    });
  }
});
