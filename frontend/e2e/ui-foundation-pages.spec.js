// @ts-check
/**
 * Merge-acceptance visual evidence: real Vue pages (NotificationsCenter / StudentsList)
 * with deterministic API mocks. Does not use production SMOKE credentials.
 *
 * Static HTML harness under e2e/fixtures is design exploration only.
 */
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/raw');

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

function fakeStudents(count, { longName = false } = {}) {
  return Array.from({ length: count }, (_, i) => ({
    id: 2000 + i,
    name: longName && i === 0
      ? '測試學生長名字驗證用名稱避免真實個資'
      : `測試學生${String(i + 1).padStart(2, '0')}`,
    grade: 'J1',
    school: longName && i === 0 ? '測試國民中學附設高級中等學校名稱很長' : '測試國中',
    parent_name: longName && i === 0 ? '測試家長長名稱' : '測試家長',
    rfid: i % 3 === 0 ? null : `TEST${1000 + i}`,
    status: 'active',
    notes: '',
    line_bound: false,
  }));
}

async function installApiMocks(page, mode, pageName = '') {
  const hang = mode === 'loading';
  let manualBookingCheckCount = 0;
  let releaseHang;
  const hangPromise = hang
    ? new Promise((resolve) => { releaseHang = resolve; })
    : Promise.resolve();

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    const method = route.request().method();

    if (pageName === 'course' && mode === 'booking-race' && method === 'POST' && p.includes('/manual-sessions/check')) {
      manualBookingCheckCount += 1;
      if (manualBookingCheckCount === 1) {
        // Reproduce the stale response that used to overwrite the director's
        // newer date selection with an old 422 error.
        await new Promise((resolve) => setTimeout(resolve, 180));
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ can_add: false, message: '舊日期檢查失敗', conflict_type: 'past_session' }),
        });
      }
      await new Promise((resolve) => setTimeout(resolve, 10));
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ can_add: true, message: '可以預約', duration_minutes: 120, available_sessions: 1, conflict_type: 'none' }),
      });
    }

    if (method !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    if (hang && (p.includes('/action-inbox') || p.includes('/students') || (pageName === 'course' && p.includes('/student-classes')) || pageName === 'calendar' || pageName === 'discrepancy')) {
      await hangPromise;
    }

    if (pageName === 'calendar' && p.includes('/student-classes')) {
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '課表資料暫時無法載入' }) });
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 5101, student_id: 2000, student_name: mode === 'long' ? '測試學生超長姓名驗證行事曆卡片折行' : '測試學生甲', subject: 'Math', class_type: 'one_on_one', teacher_id: 3000, teacher_name: '測試老師', day_of_week: 1, start_time: '10:00', end_time: '12:00', duration_hours: 2, status: 'active', stop: 0 }], total: 1 }),
      });
    }

    if (pageName === 'calendar' && p.includes('/teachers')) {
      if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 3000, username: '測試老師', name: '測試老師', branch_id: 1, branch_ids: [1] }] }) });
    }

    if (pageName === 'calendar' && p.includes('/schedules')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }

    if (pageName === 'discrepancy' && p.includes('/schedule-discrepancies/summary')) {
      const total = mode === 'empty' ? 0 : 1;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ pending: total, acknowledged: 0, resolved: 0, withdrawn: 0 }) });
    }

    if (pageName === 'discrepancy' && p.includes('/schedule-discrepancies')) {
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '課表回報暫時無法載入' }) });
      if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      const studentName = mode === 'long' ? '測試學生超長姓名驗證回報卡片折行' : '測試學生甲';
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 6101, reporter_name: '測試老師', branch_name: '測試分校', student_name: studentName, session_date: '2026-08-03', time_range: '10:00–12:00', corrected_time_range: '11:00–13:00', discrepancy_type_label: '時段不符', status: 'pending', notes: mode === 'long' ? '這是一段很長的老師回報備註，用來確認卡片會自然折行，不會把處理動作推到畫面外。'.repeat(2) : '請確認調課後的時段。' }] }),
      });
    }

    if (mode === 'dashboard' && p.endsWith('/alerts/tuition')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 801, class_id: 801, student_id: 2001, student_name: '測試學生甲', subject: '數學', remaining_sessions: 1, alert_type: 'unpaid' }]),
      });
    }

    if (mode === 'dashboard' && p.includes('/director/operations-trust')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { decision_center: {
          score: 82,
          max: 100,
          status: 'yellow',
          headline: '有 1 項課務風險需要確認',
          decisions: [{ key: 'demo-risk', severity: 'warning', why: '測試課表需要主任確認。', next_step: '查看課表', action_label: '查看風險', target: 'course-mgmt', people_total: 0 }],
          policy_notes: [],
        }, generated_at: '2026-08-01T04:00:00Z' } }),
      });
    }

    if (mode === 'dashboard' && p.includes('/class-sessions')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 901, student_class_id: 801, student_name: '測試學生甲', teacher_name: '測試老師', start_time: '10:00', end_time: '11:00', status: 'scheduled', subject: '數學' }] }),
      });
    }

    if (mode === 'dashboard' && p.includes('/learning-records')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 1001, status: 'pending', student_name: '測試學生甲' }] }),
      });
    }

    if ((mode === 'dashboard' || pageName === 'course') && p.includes('/exception-workflows')) {
      if (pageName === 'course' && mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '家長請假待辦載入失敗' }) });
      }
      if (pageName === 'course' && mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { count: 0 } }) });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 27, status: 'open', student: { name: mode === 'long' ? '測試學生超長姓名用於驗證處理案件摘要折行' : '測試學生甲' }, class_session: { date: '2026-08-02', start_time: '10:00', end_time: '11:00' }, payload: { reason: mode === 'long' ? '這是一段很長的家長請假原因，用來驗證案件資訊可以折行且主要處理按鈕仍然可見。' : '身體不適' }, due_at: '2026-08-01T18:00:00+08:00' }] }),
      });
    }

    if (pageName === 'course' && p.includes('/student-classes')) {
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) });
      }
      if (mode === 'booking-race') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 5201,
              student_id: 2000,
              student_name: '測試學生甲',
              subject: '英文',
              teacher_id: 3000,
              teacher_name: '測試老師',
              class_type: 'one_on_three',
              scheduling_policy: 'manual_occurrence',
              payment_type: 'session',
              sessions_purchased: 8,
              sessions_used: 7,
              remaining_sessions: 1,
              status: 'active',
              day_of_week: 6,
              start_time: '13:00',
              end_time: '15:00',
              duration_hours: 2,
              branch_id: 1,
            }],
            total: 1,
          }),
        });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ ID: 801, StudentID: 2000, student_name: '測試學生甲', SubjectID: 1, Subject: '數學', ClassType: 'one_on_one', TeacherID: 3000, teacher_name: '測試老師', Stop: 0 }], total: 1 }),
      });
    }

    if (mode === 'dashboard' && p.includes('/schedule-discrepancies/summary')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 }),
      });
    }

    if (p.endsWith('/action-inbox/count')) {
      // Keep case lane for empty/error so empty/error UI is the case-lane states under test.
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
        body: JSON.stringify({ data: [], unread_count: mode === 'dashboard' ? 2 : 0, current_page: 1, last_page: 1, total: 0 }),
      });
    }

    if (p.includes('/students')) {
      if (mode === 'empty') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [], total: 0 }),
        });
      }
      const count = mode === 'dense' ? 40 : 8;
      const list = fakeStudents(count, { longName: mode === 'long' });
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: list, total: list.length }),
      });
    }

    if (p.includes('/student-classes')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 5001,
              student_id: 2000,
              subject: 'Math',
              remaining_sessions: 8,
              payment_type: 'session',
              status: 'active',
            },
          ],
        }),
      });
    }

    if (p.includes('/teachers') || p.includes('/subjects') || p.includes('/rooms') || p.includes('/temp-rfid')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) });
    }

    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  return { releaseHang: () => releaseHang?.() };
}

async function openPilot(page, { pageName, mode, viewport }) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const mocks = await installApiMocks(page, mode, pageName);
  await page.goto(`/pilot-mount.html?page=${pageName}&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return mocks;
}

test.describe('UI foundation — real Vue page evidence', () => {
  test.describe.configure({ mode: 'serial' });

  const inboxModes = ['normal', 'loading', 'empty', 'error', 'long', 'disabled'];
  const studentModes = ['normal', 'empty', 'dense', 'long', 'focus', 'mobile-scroll'];

  for (const vp of viewports) {
    for (const mode of inboxModes) {
      test(`inbox ${mode} @${vp.name}`, async ({ page }) => {
        fs.mkdirSync(outDir, { recursive: true });
        const mocks = await openPilot(page, { pageName: 'inbox', mode: mode === 'disabled' ? 'disabled' : mode, viewport: vp });

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
            // Single-page mock still exposes primary CTA; assert disabled sync button on ops lane is N/A.
            // Ensure case CTA remains enabled while pager prev (if present) is disabled — already checked.
            await expect(page.getByRole('button', { name: '安排補課' }).first()).toBeEnabled();
          }
        }

        await page.locator('.notifications-page').screenshot({
          path: path.join(outDir, `vue-inbox-${mode}-${vp.name}.png`),
        });
      });
    }

    for (const mode of studentModes) {
      test(`students ${mode} @${vp.name}`, async ({ page }) => {
        fs.mkdirSync(outDir, { recursive: true });
        const apiMode = mode === 'focus' || mode === 'mobile-scroll' ? (mode === 'mobile-scroll' ? 'dense' : 'normal') : mode;
        await openPilot(page, { pageName: 'students', mode: apiMode, viewport: vp });

        await expect(page.getByText('學生管理')).toBeVisible({ timeout: 10_000 });

        if (mode === 'empty') {
          await expect(page.getByText('目前沒有進行中的學生/課程').or(page.getByText('目前無學生資料'))).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible({ timeout: 10_000 });
        }

        if (mode === 'focus') {
          const addBtn = page.getByRole('button', { name: '新增學生' }).first();
          await addBtn.focus();
          await expect(addBtn).toBeFocused();
        }

        if (mode === 'mobile-scroll' && vp.name === '390') {
          const wrap = page.locator('.table-scroll-wrap');
          await expect(wrap).toBeVisible();
          const scrollWidth = await wrap.evaluate((el) => el.scrollWidth);
          const clientWidth = await wrap.evaluate((el) => el.clientWidth);
          expect(scrollWidth).toBeGreaterThanOrEqual(clientWidth);
          await wrap.evaluate((el) => { el.scrollLeft = Math.min(120, el.scrollWidth); });
        }

        await page.locator('.students-page').screenshot({
          path: path.join(outDir, `vue-students-${mode}-${vp.name}.png`),
        });
      });
    }
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`director workbench @${vp.name}`, async ({ page }) => {
      const secondaryRequests = [];
      page.on('request', (request) => {
        if (/\/v1\/adoption\/(task-tracker|activity-log|weekly-metrics)/.test(request.url())) secondaryRequests.push(request.url());
      });
      await openPilot(page, { pageName: 'director', mode: 'normal', viewport: vp });

      await expect(page.getByText('主任總覽', { exact: true })).toBeVisible({ timeout: 10_000 });
      await expect(page.getByText('今日摘要', { exact: true })).toBeVisible();
      const layout = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        primaryCtaCount: document.querySelectorAll('.director-task__action').length,
        primaryDecisionCount: document.querySelectorAll('.director-task').length,
        hiddenLegacyWorkbench: Boolean(document.querySelector('.workbench')),
      }));
      expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
      expect(layout.hiddenLegacyWorkbench).toBe(false);
      expect(layout.primaryDecisionCount).toBeLessThanOrEqual(7);
      await expect(page.getByRole('tab', { name: '今天', exact: true })).toHaveAttribute('aria-selected', 'true');
      if (layout.primaryCtaCount > 0) {
        await expect(page.locator('.director-task__action').first()).toBeVisible();
        if (vp.width <= 768) {
          const ctaBox = await page.locator('.director-task__action').first().boundingBox();
          expect(ctaBox?.height || 0, '手機主任待辦主要操作必須有至少 44px 觸控高度').toBeGreaterThanOrEqual(44);
        }
      }
      expect(secondaryRequests).toEqual([]);
      fs.mkdirSync(outDir, { recursive: true });
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-focus-${vp.name}.png`) });

      const fullView = page.getByRole('tab', { name: '完整營運', exact: true });
      await fullView.click();
      await expect(page.locator('.director-workbench-v2__full')).toBeVisible();
      await expect.poll(() => secondaryRequests.length).toBeGreaterThan(0);
      await expect(page.getByText('近期紀錄與分析', { exact: true })).toBeVisible();
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-full-${vp.name}.png`) });
    });
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`course leave workflow ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'course', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.getByRole('status', { name: '課程資料載入中' })).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
          return;
        }

        if (mode === 'error') {
          await expect(page.getByRole('alert')).toContainText('家長請假待辦載入失敗');
        } else if (mode === 'empty') {
          await expect(page.getByText('目前沒有待處理的家長請假')).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('家長請假待處理', { exact: true })).toBeVisible({ timeout: 10_000 });
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible();
          const action = page.getByRole('button', { name: '處理這筆請假' }).first();
          await expect(action).toBeVisible();
          await action.click();
          await expect.poll(() => page.evaluate(() => window.__pilotLastNavigation)).toEqual({
            target: 'director',
            section: 'exception-workflows',
            workflowId: 27,
          });
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.course-page').screenshot({
            path: path.join(outDir, `vue-course-leave-${mode}-${vp.name}.png`),
          });
        }
      });
    }
  }

  test('manual booking keeps the latest date after a stale 422 response', async ({ page }) => {
    await openPilot(page, { pageName: 'course', mode: 'booking-race', viewport: { width: 1440, height: 900 } });

    const addNextButton = page.locator('button.manual-occurrence-action').filter({ hasText: '新增下一堂' }).first();
    await expect(addNextButton).toBeVisible({ timeout: 10_000 });
    await addNextButton.click();

    const dateInput = page.locator('.manual-session-modal input[type="date"]');
    await expect(dateInput).toBeVisible();
    // Ensure the initial request is in flight before changing the date.
    await expect.poll(() => page.locator('.manual-session-state').count()).toBeGreaterThan(0);

    const latestDate = await dateInput.inputValue().then((value) => {
      const date = new Date(`${value}T12:00:00`);
      date.setDate(date.getDate() + 1);
      return date.toISOString().slice(0, 10);
    });
    await dateInput.fill(latestDate);
    await dateInput.press('Tab');

    await expect(page.getByText('可以預約', { exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('button', { name: '建立這一堂', exact: true })).toBeEnabled();
    // Let the deliberately delayed old 422 complete; it must not replace the
    // result for the date the director currently sees.
    await page.waitForTimeout(240);
    await expect(page.getByText('可以預約', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '建立這一堂', exact: true })).toBeEnabled();
  });

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`calendar view ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'calendar', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.locator('.calendar-loading-bar')).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
        } else if (mode === 'error') {
          await expect(page.getByRole('alert')).toContainText('課表資料暫時無法載入', { timeout: 10_000 });
        } else {
          await expect(page.getByText('排課與調課', { exact: true })).toBeVisible({ timeout: 10_000 });
          await expect(page.getByRole('button', { name: '回到今天的課表' })).toBeVisible();
          if (mode === 'empty') {
            await expect(page.getByText('目前無老師資料', { exact: false })).toBeVisible();
          } else {
            await expect(page.getByText('測試老師', { exact: false }).first()).toBeVisible();
          }
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.smart-cal-top').screenshot({ path: path.join(outDir, `vue-calendar-${mode}-${vp.name}.png`) });
        }
      });
    }
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`schedule discrepancy ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'discrepancy', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.locator('.sdp-state-loading')).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
        } else if (mode === 'error') {
          await expect(page.locator('.sdp-state-error')).toContainText('課表回報暫時無法載入', { timeout: 10_000 });
        } else {
          await expect(page.getByText('課表回報管理', { exact: true })).toBeVisible({ timeout: 10_000 });
          if (mode === 'empty') {
            await expect(page.getByText('目前沒有待處理的回報', { exact: true })).toBeVisible();
          } else {
            const action = vp.width <= 768
              ? page.locator('.sdp-mcard').first().getByRole('button', { name: '接手處理', exact: true })
              : page.getByRole('button', { name: '接手處理', exact: true }).first();
            await expect(action).toBeVisible();
            await action.click();
            const detail = vp.width <= 768
              ? page.locator('.sdp-mcard').first().locator('.sdp-resolve-form')
              : page.locator('.sdp-detail').first();
            await expect(detail).toBeVisible();
          }
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.sdp-page').screenshot({ path: path.join(outDir, `vue-discrepancy-${mode}-${vp.name}.png`) });
        }
      });
    }
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`director workbench with urgent tasks @${vp.name}`, async ({ page }) => {
      await openPilot(page, { pageName: 'director', mode: 'dashboard', viewport: vp });
      await expect(page.getByText('主任總覽', { exact: true })).toBeVisible({ timeout: 10_000 });
      const riskDisclosure = page.locator('.director-risk-disclosure');
      await expect(riskDisclosure.getByText('查看為什麼這些工作排在前面', { exact: true })).toBeVisible();
      await riskDisclosure.locator('summary').click();
      await expect(riskDisclosure.locator('.director-top-risks').getByText('家長請假待主任處理', { exact: true })).toBeVisible();
      const ctas = page.locator('.director-task__action');
      expect(await ctas.count()).toBeGreaterThan(0);
      await expect(ctas.first()).toBeVisible();
      fs.mkdirSync(outDir, { recursive: true });
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-urgent-focus-${vp.name}.png`) });
      const leaveTask = page.getByRole('button', { name: '開始處理', exact: true });
      await leaveTask.click();
      await expect(page.getByText('家長請假', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: '尋找補課時段', exact: true })).toBeVisible();
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-urgent-${vp.name}.png`) });
    });
  }

  // director-workbench-v2's 完整營運 view renders #schedule-sec/#evals-sec/#payments-sec
  // (surface-panel cards) with zero prior test coverage of their empty-state and count
  // wiring. (Note: PR #1515's AtCard/AtEmpty work-grid markup at the same IDs, further
  // down this file, is dead — permanently wrapped in `<template v-if="false">` since a
  // later redesign introduced director-workbench-v2; it never renders.)
  test('director 完整營運 cards show empty state with zero counts', async ({ page }) => {
    await openPilot(page, { pageName: 'director', mode: 'empty', viewport: { width: 1440, height: 900 } });
    await expect(page.getByText('主任總覽', { exact: true })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('tab', { name: '完整營運', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__full')).toBeVisible();

    await expect(page.locator('#schedule-sec .surface-panel__count')).toHaveText('0 堂');
    await expect(page.locator('#schedule-sec')).toContainText('今天沒有課程。');

    await expect(page.locator('#evals-sec .surface-panel__count')).toHaveText('0 筆');
    await expect(page.locator('#evals-sec')).toContainText('目前沒有待審核評量。');

    await expect(page.locator('#payments-sec .surface-panel__count')).toHaveText('0');
    await expect(page.locator('#payments-sec')).toContainText('目前沒有需要跟進的繳費提醒。');
  });

  test('director 完整營運 cards show counts and rows with data', async ({ page }) => {
    await openPilot(page, { pageName: 'director', mode: 'dashboard', viewport: { width: 1440, height: 900 } });
    await expect(page.getByText('主任總覽', { exact: true })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('tab', { name: '完整營運', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__full')).toBeVisible();

    await expect(page.locator('#schedule-sec .surface-panel__count')).toHaveText('1 堂');
    await expect(page.locator('#schedule-sec .director-schedule-row')).toHaveCount(1);

    await expect(page.locator('#evals-sec .surface-panel__count')).toHaveText('1 筆');
    await expect(page.locator('#evals-sec .director-evaluation-row')).toHaveCount(1);

    await expect(page.locator('#payments-sec .surface-panel__count')).toHaveText('1');
    await expect(page.locator('#payments-sec .director-payment-row')).toHaveCount(1);
  });
});
