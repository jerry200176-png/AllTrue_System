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

    if (pageName === 'tuition' && p.includes('/alerts/tuition')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 701, student_name: '測試學生甲', subject: '數學', payment_status: 'unpaid', charge: 12000, paid_amount: 0, outstanding: 12000, schedule_mode: 'count', remaining_sessions: 4, course_start_date: '2026-08-01', course_end_date: '2026-10-31', days_until_settlement: 2 }]),
      });
    }

    if (pageName === 'tuition' && p.includes('/accounting/settled-courses')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ student_class_id: 701, course_ref: '測試課程 #701', student_name: '測試學生甲', subject: '數學', schedule_mode: 'count', paid_amount: 12000, last_paid_at: '2026-08-20', legacy_paid_without_invoice: false, has_exception: false, overpaid_total: 0 }], summary: { course_count: 1, paid_total: 12000, legacy_count: 0, exception_count: 0, overpaid_total: 0 } }),
      });
    }

    if (pageName === 'tuition' && p.includes('/accounting/payments')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], summary: { total_count: 0, total_amount: 0 } }),
      });
    }

    if (pageName === 'teachers' && p.includes('/teachers')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [
          { id: 7101, username: '測試老師甲', account: 'teacher-a', phone: '0912-345-678', branch_id: 1, branch_ids: [1, 2], status: 'active', employment_type: 'full_time', subject_ids: [1], subject_names: ['數學'], subject_level_scopes: [{ subject_id: 1, level: 'junior' }], rfid_by_branch: { '1': 'TEACHER-A-001', '2': 'TEACHER-A-002' } },
          { id: 7102, username: '測試老師乙', account: 'teacher-b', phone: '', branch_id: 1, branch_ids: [1], status: 'pending', employment_type: 'part_time', subject_ids: [], subject_names: [], subject_level_scopes: [], rfid: null },
          { id: 7103, username: '測試老師丙', account: 'teacher-c', phone: '', branch_id: 1, branch_ids: [1], status: 'suspended', employment_type: 'full_time', subject_ids: [], subject_names: [], subject_level_scopes: [], rfid: null },
        ] }),
      });
    }

    if (pageName === 'teachers' && p.includes('/subjects')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '數學' }]),
      });
    }

    if (pageName === 'teachers' && p.includes('/branches')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '大安分校' }, { id: 2, name: '石牌分校' }]),
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
        body: JSON.stringify({
          data: mode === 'dialog' ? [{
            id: 'notification-tuition-1',
            Type: 'tuition',
            Title: '測試繳費通知',
            Body: '家長已回報繳費，請登記後等待對帳。',
            SourceType: 'Invoice',
            Severity: 'high',
            read_at: null,
            ResolvedAt: null,
            Payload: { student_id: 2000, student_name: '測試學生甲', subject: '數學', charge: 1200 },
          }] : [],
          unread_count: mode === 'dashboard' || mode === 'dialog' ? 1 : 0,
          current_page: 1,
          last_page: 1,
          total: mode === 'dialog' ? 1 : 0,
        }),
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
              sessions_purchased: 12,
              sessions_used: 4,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師甲',
              days_of_week: [1],
              branch_name: '測試分校',
              room_name: '101教室',
              status: 'active',
            },
            {
              id: 5002,
              student_id: 2000,
              subject: 'English',
              remaining_sessions: 1,
              sessions_purchased: 8,
              sessions_used: 7,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師乙',
              days_of_week: [2],
              branch_name: '測試分校',
              room_name: '202教室',
              status: 'active',
            },
            {
              id: 5003,
              student_id: 2000,
              subject: 'Science',
              remaining_sessions: 0,
              sessions_purchased: 8,
              sessions_used: 8,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師丙',
              days_of_week: [3],
              branch_name: '測試分校',
              room_name: '303教室',
              status: 'inactive',
              closed_reason: 'completed',
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

  test('inbox tabs expose a linked keyboard workspace', async ({ page }) => {
    await openPilot(page, {
      pageName: 'inbox',
      mode: 'normal',
      viewport: { width: 390, height: 844 },
    });

    const casesTab = page.getByRole('tab', { name: '待辦案件' });
    const opsTab = page.getByRole('tab', { name: '營運通知' });
    await expect(casesTab).toHaveAttribute('id', 'notifications-tab-cases');
    await expect(casesTab).toHaveAttribute('aria-controls', 'notifications-panel-cases');
    await expect(casesTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#notifications-panel-cases')).toHaveAttribute('role', 'tabpanel');
    await expect(page.locator('#notifications-panel-cases')).toHaveAttribute('aria-labelledby', 'notifications-tab-cases');

    await opsTab.focus();
    await opsTab.press('Enter');
    await expect(opsTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#notifications-panel-ops')).toHaveAttribute('role', 'tabpanel');
    await expect(page.locator('#notifications-panel-ops')).toHaveAttribute('aria-labelledby', 'notifications-tab-ops');
  });

  test('inbox tuition report uses the shared modal contract', async ({ page }) => {
    await openPilot(page, {
      pageName: 'inbox',
      mode: 'dialog',
      viewport: { width: 390, height: 844 },
    });

    await page.getByRole('tab', { name: '營運通知' }).click();
    const reportButton = page.getByRole('button', { name: '標記已繳費' }).first();
    await expect(reportButton).toBeVisible();
    await reportButton.click();

    const dialog = page.getByRole('dialog', { name: '登記已回報' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog).toBeFocused();
    await expect(dialog.getByRole('button', { name: '關閉登記已回報視窗' })).toBeVisible();

    await dialog.press('Escape');
    await expect(dialog).toBeHidden();
  });

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`students course overview selects the next action @${vp.name}`, async ({ page }) => {
      fs.mkdirSync(outDir, { recursive: true });
      await openPilot(page, { pageName: 'students', mode: 'normal', viewport: vp });

      const tableWrap = page.locator('.table-scroll-wrap');
      const initialScrollLeft = await tableWrap.evaluate((el) => el.scrollLeft);
      const studentRow = page.locator('tr.student-row').first();
      await expect(studentRow).toHaveAttribute('tabindex', '0');
      await expect(studentRow).toHaveAttribute('aria-expanded', 'false');
      await expect(studentRow).toHaveAttribute('aria-controls', /student-course-detail-/);
      await studentRow.focus();
      await studentRow.press('Enter');
      await expect(studentRow).toHaveAttribute('aria-expanded', 'true');
      const workspace = page.getByTestId('student-course-workspace');
      await expect(workspace).toBeVisible({ timeout: 10_000 });
      await expect.poll(() => tableWrap.evaluate((el) => el.scrollLeft)).toBeLessThan(initialScrollLeft + 8);
      await expect(workspace.getByText('先看需要處理的課程')).toBeVisible();
      await expect(workspace.getByRole('heading', { name: '查看選定課程的完整資料' })).toBeVisible();
      await expect(workspace.locator('.student-course-overview__metric')).toHaveCount(3);
      await expect(workspace.locator('.student-course-overview__metric:nth-child(3) strong')).toHaveText('1');

      const english = workspace.locator('.student-course-picker__item[data-course-id="5002"]');
      const math = workspace.locator('.student-course-picker__item[data-course-id="5001"]');
      await expect(english.locator('button')).toHaveAttribute('aria-pressed', 'true');
      await expect(math.locator('button')).toHaveAttribute('aria-pressed', 'false');
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"]')).toBeVisible();
      await expect(workspace.locator('.student-course-card__next-step')).toContainText('先處理課程續報');
      await expect(workspace.getByRole('button', { name: '續報加購' })).toBeVisible();

      const historyToggle = page.locator('tr.course-detail-row').first().locator('.sl-history-toggle');
      await expect(historyToggle).toHaveAttribute('aria-expanded', 'false');
      await historyToggle.click();
      await expect(historyToggle).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('tr.course-detail-row').first().locator('.sl-history-body')).toBeVisible();

      await math.locator('button').click();
      await expect(math.locator('button')).toHaveAttribute('aria-pressed', 'true');
      await expect(english.locator('button')).toHaveAttribute('aria-pressed', 'false');
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]')).toBeVisible();
      await expect(workspace.locator('.student-course-card__next-step')).toContainText('課程資料已齊全');
      await expect(workspace.getByRole('button', { name: '編輯課程' })).toBeVisible();
      await expect.poll(() => tableWrap.evaluate((el) => el.scrollLeft)).toBeLessThan(initialScrollLeft + 8);

      await page.locator('.students-page').screenshot({
        path: path.join(outDir, `vue-students-course-overview-${vp.name}.png`),
      });
    });
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
      await expect(page.getByRole('tab', { name: '今天', exact: true })).toHaveAttribute('aria-controls', 'director-workbench-panel-focus');
      await expect(page.locator('#director-workbench-panel-focus')).toHaveAttribute('role', 'tabpanel');
      await expect(page.locator('#director-workbench-panel-focus')).toHaveAttribute('aria-labelledby', 'director-workbench-tab-focus');
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
      await expect(fullView).toHaveAttribute('aria-controls', 'director-workbench-panel-full');
      await expect(page.locator('#director-workbench-panel-full')).toHaveAttribute('role', 'tabpanel');
      await expect(page.locator('#director-workbench-panel-full')).toHaveAttribute('aria-labelledby', 'director-workbench-tab-full');
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

  test('course management keeps disclosure and tab focus relationships explicit', async ({ page }) => {
    await openPilot(page, { pageName: 'course', mode: 'normal', viewport: { width: 1440, height: 900 } });

    const groupToggle = page.locator('.student-group-toggle').first();
    await expect(groupToggle).toBeVisible({ timeout: 10_000 });
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(groupToggle).toHaveAttribute('aria-controls', /student-group-panel-courses/);
    await expect(page.getByRole('button', { name: '專注 測試學生甲', exact: true })).toBeVisible();

    await groupToggle.press('Space');
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[role="tabpanel"]')).toHaveCount(0);
    await groupToggle.press('Enter');
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'true');

    const courseTab = page.getByRole('tab', { name: '課程資料', exact: true }).first();
    const billingTab = page.getByRole('tab', { name: '帳務資料', exact: true }).first();
    await expect(courseTab).toHaveAttribute('aria-selected', 'true');
    await expect(courseTab).toHaveAttribute('aria-controls', /student-group-panel-courses/);
    await expect(courseTab).toHaveAttribute('tabindex', '0');
    await expect(billingTab).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('[role="tabpanel"]')).toBeVisible();
    await courseTab.press('ArrowRight');
    await expect(billingTab).toHaveAttribute('aria-selected', 'true');
    await expect(billingTab).toBeFocused();
    await expect(courseTab).toHaveAttribute('tabindex', '-1');
    await expect(billingTab).toHaveAttribute('tabindex', '0');
    await expect(page.locator('[role="tabpanel"]')).toHaveAttribute('aria-labelledby', /student-group-tab-billing/);
    await billingTab.press('ArrowLeft');
    await expect(courseTab).toBeFocused();
    await expect(courseTab).toHaveAttribute('aria-selected', 'true');
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

  test('billing top-level tabs show one selected panel', async ({ page }) => {
    await openPilot(page, { pageName: 'tuition', mode: 'normal', viewport: { width: 1440, height: 900 } });

    const receivables = page.locator('#tuition-accounting-tab-receivables');
    const settled = page.locator('#tuition-accounting-tab-settled');
    const payments = page.locator('#tuition-accounting-tab-payments');

    await expect(receivables).toHaveAttribute('aria-controls', 'tuition-accounting-panel-receivables');
    await expect(page.locator('#tuition-accounting-panel-receivables')).toHaveCount(1);
    await expect(page.locator('#tuition-accounting-panel-payments')).toHaveCount(0);
    await expect(page.locator('#tuition-accounting-panel-settled')).toHaveCount(0);

    await settled.click();
    await expect(page.locator('#tuition-accounting-panel-settled')).toBeVisible();
    await expect(page.locator('#tuition-accounting-panel-payments')).toHaveCount(0);
    await expect(page.locator('#tuition-accounting-panel-receivables')).toHaveCount(0);

    await payments.click();
    await expect(page.locator('#tuition-accounting-panel-payments')).toBeVisible();
    await expect(page.locator('#tuition-accounting-panel-settled')).toHaveCount(0);
  });

  test('teacher list tabs keep status workspace and RFID readable', async ({ page }) => {
    await openPilot(page, { pageName: 'teachers', mode: 'normal', viewport: { width: 390, height: 844 } });

    const active = page.locator('#teachers-tab-active');
    const pending = page.locator('#teachers-tab-pending');
    const suspended = page.locator('#teachers-tab-suspended');

    await expect(active).toHaveAttribute('aria-controls', 'teachers-panel-active');
    await expect(page.locator('#teachers-panel-active')).toBeVisible();
    await expect(page.locator('#teachers-panel-active')).toContainText('測試老師甲');
    await expect(page.locator('.status-tag.active')).toContainText('在職');
    await expect(page.locator('.rfid-tag').first()).toHaveText('TEACHER-A-001');
    const hasHorizontalOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(hasHorizontalOverflow).toBe(false);

    await pending.click();
    await expect(page.locator('#teachers-panel-pending')).toBeVisible();
    await expect(page.locator('#teachers-panel-pending')).toContainText('測試老師乙');
    await expect(page.locator('#teachers-panel-active')).toHaveCount(0);
    await expect(page.locator('.status-tag.pending')).toContainText('待審核');

    await suspended.click();
    await expect(page.locator('#teachers-panel-suspended')).toBeVisible();
    await expect(page.locator('#teachers-panel-suspended')).toContainText('測試老師丙');
    await expect(page.locator('#teachers-panel-pending')).toHaveCount(0);
    await expect(page.locator('.status-tag.suspended')).toContainText('停用');
  });

  test('director attendance keeps the action queue ahead of secondary context', async ({ page }) => {
    await openPilot(page, { pageName: 'attendance', mode: 'empty', viewport: { width: 1440, height: 900 } });

    const studentTab = page.locator('#attendance-tab-student');
    const teacherTab = page.locator('#attendance-tab-teacher');
    await expect(studentTab).toHaveAttribute('aria-selected', 'true');
    await expect(studentTab).toHaveAttribute('aria-controls', 'attendance-student-panel');
    await expect(page.locator('#attendance-student-panel')).toHaveAttribute('aria-labelledby', 'attendance-tab-student');
    await expect(page.locator('#attendance-student-panel')).toHaveAttribute('tabindex', '0');
    await expect(page.getByText('今日待點名堂次', { exact: true })).toBeVisible();
    await expect(page.locator('#attendance-student-panel > .att-secondary-summary')).not.toHaveAttribute('open', '');

    await teacherTab.click();
    await expect(teacherTab).toHaveAttribute('aria-selected', 'true');
    await expect(teacherTab).toHaveAttribute('aria-controls', 'attendance-teacher-panel');
    await expect(page.locator('#attendance-teacher-panel')).toHaveAttribute('aria-labelledby', 'attendance-tab-teacher');
    await expect(page.locator('#attendance-teacher-panel')).toHaveAttribute('tabindex', '0');
    await expect(page.getByRole('heading', { name: '先處理課表異常', exact: true })).toBeVisible();
    await expect(page.getByText('課表異常待處理', { exact: true })).toBeVisible();
    await expect(page.locator('#attendance-teacher-panel > .att-secondary-summary')).toHaveCount(2);
    await expect(page.locator('#attendance-teacher-panel > .att-secondary-summary').first()).not.toHaveAttribute('open', '');
  });
});
