// @ts-check
import { test, expect } from '@playwright/test';

/**
 * #983 role matrix: deterministic parent portal coverage.
 *
 * This mounts the real ParentPortal.vue and mocks only its read APIs, so the
 * role path runs on every UI-foundation PR without production credentials.
 */
test('parent portal: mobile home exposes announcements and billing status', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('parent_portal_token', 'e2e-parent-token');
  });

  const errors = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.includes('/parent/dashboard')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          student: { id: 2000, name: '測試學生甲', grade: 'J1', school: '測試國中', campus_id: 1, campus_name: '測試分校', line_linked: false },
          enrollments: [],
          cross_campus_access: 'actions',
          progress_summary: {
            week_label: '8/24–8/30',
            week_progress: { attended: 1, scheduled: 2 },
            next_session: { date: '2026-09-01', start_time: '10:00', end_time: '11:00', subject: '數學', is_today: false },
            payment: { paid_courses: 1, total_courses: 2, status: 'partial' },
            pending_total: 1,
          },
          announcements: [{ id: 9001, Title: '測試公告', Content: '這是給家長看的公告。', campus_name: '測試分校', created_at: '2026-08-28T09:00:00+08:00' }],
          learning_records: [],
          learning_records_meta: { total: 0, has_more: false },
          attendance_history: [],
          upcoming_sessions: [],
          classes: [{ id: 9401, subject: '數學', sessions_purchased: 8, used_sessions: 6, remaining_sessions: 2, paid: false, payment_status_label: '待繳費', status: 'active' }],
          remaining_sessions_total: 2,
          remaining_by_subject: { 數學: 2 },
          payment_alerts: [{ class_id: 9401, subject: '數學', remaining_sessions: 2, paid: false }],
          invoices: [],
          assessment_progress: { items: [] },
        }),
      });
    }
    if (url.pathname.includes('/parent/notification-preferences')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ learning_feedback_push: false }) });
    }
    if (route.request().method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto('/pilot-mount.html?page=parent');
  await expect(page.locator('[data-guide="parent-portal-root"]')).toBeVisible();
  await expect(page.locator('[data-guide="parent-student-card"]')).toContainText('測試學生甲');
  await expect(page.locator('[data-guide="parent-progress-hub"] .pp-hub-title')).toContainText('進度中心');
  await expect(page.getByText('公告', { exact: true })).toBeVisible();
  await expect(page.getByText('這是給家長看的公告。', { exact: true })).toBeVisible();

  await page.locator('.pp-tab').filter({ hasText: '帳務' }).click();
  await expect(page.getByText('繳費提醒', { exact: true })).toBeVisible();
  await expect(page.getByText('剩餘 2 堂', { exact: true })).toBeVisible();

  const layout = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
});

async function runPopulatedParentPortal(page, viewport) {
  await page.setViewportSize(viewport);
  await page.addInitScript(() => {
    localStorage.setItem('parent_portal_token', 'e2e-parent-populated-token');
  });

  const dashboardCalls = [];
  const mutationCalls = [];
  let selectedStudentId = 2000;
  let replyPosted = false;
  const recordOne = {
    id: 7101,
    Subject: '數學',
    SessionDate: '2026-09-08',
    StartTime: '16:00',
    EndTime: '17:00',
    teacher_name: '測試老師',
    Performance: 'good',
    HomeworkStatus: 'assigned',
    Content: '完成分數應用題與錯題訂正。',
    Comment: '下次課堂會先複習錯題，再進入新單元。',
    NextWeekTestScope: '分數四則運算',
    parent_feedback: {
      id: 8101,
      content: '孩子回家後有練習錯題。',
      updated_at: '2026-09-08T11:00:00Z',
      has_unread_reply: true,
      replies: [{ id: 9101, author_role: 'teacher', author_name: '測試老師', content: '收到，謝謝家長回饋。', created_at: '2026-09-09T10:00:00Z' }],
    },
  };
  const recordTwo = {
    id: 7102,
    Subject: '英文',
    SessionDate: '2026-09-01',
    StartTime: '16:00',
    EndTime: '17:00',
    teacher_name: '測試老師',
    Performance: 'needs_attention',
    HomeworkStatus: 'missing',
    Content: '閱讀短文與單字練習。',
    Comment: '請回家複習本週單字。',
    NextWeekTestScope: 'Unit 3 vocabulary',
    parent_feedback: null,
  };

  const dashboardFor = (pageNumber, campusId = null) => ({
    student: { id: selectedStudentId, name: selectedStudentId === 2000 ? '測試學生甲' : '測試學生乙', grade: 'J1', school: '測試國中', campus_id: campusId || (selectedStudentId === 2000 ? 1 : 2), campus_name: campusId === '2' || selectedStudentId === 2001 ? '石牌分校' : '大安分校', line_linked: false },
    students: [{ id: 2000, name: '測試學生甲' }, { id: 2001, name: '測試學生乙' }],
    identity_group_id: 77,
    cross_campus_access: 'actions',
    enrollments: [
      { student_id: 2000, campus_id: 1, campus_name: '大安分校' },
      { student_id: 2000, campus_id: 2, campus_name: '石牌分校' },
    ],
    progress_summary: {
      week_label: '9/7–9/13',
      week_progress: { attended: 1, scheduled: 2 },
      next_session: { date: '2026-09-15', start_time: '16:00', end_time: '17:00', subject: '數學', is_today: false },
      payment: { paid_courses: 1, total_courses: 1, status: 'paid' },
      pending_total: 1,
      pending_actions: [{ key: 'feedback', count: 1 }],
    },
    learning_records: pageNumber === 1 ? [recordOne] : [recordTwo],
    learning_records_meta: { page: pageNumber, per_page: 10, total: 2, has_more: pageNumber === 1 },
    assessment_progress: { items: [] },
    attendance_history: [],
    upcoming_sessions: [{ id: 6201, SessionDate: '2026-09-20', StartTime: '16:00', EndTime: '17:00', Status: 'scheduled', Subject: '數學' }],
    classes: [],
    remaining_sessions_total: 0,
    remaining_by_subject: {},
    payment_alerts: [],
    invoices: [],
    announcements: [],
  });

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.includes('/parent/dashboard')) {
      const pageNumber = Number(url.searchParams.get('lr_page') || 1);
      const campusId = url.searchParams.get('campus_id');
      dashboardCalls.push({ page: pageNumber, scope: url.searchParams.get('scope'), campusId: url.searchParams.get('campus_id') });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(dashboardFor(pageNumber, campusId)) });
    }
    if (url.pathname.includes('/parent/notification-preferences')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ learning_feedback_push: false }) });
    }
    if (url.pathname.includes('/parent/learning-records/7101/feedback/reply')) {
      replyPosted = true;
      mutationCalls.push({ path: url.pathname, method: request.method(), body: request.postDataJSON() });
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ reply: { id: 9201, created_at: '2026-09-10T10:00:00Z' } }) });
    }
    if (url.pathname.includes('/parent/learning-records/7101/feedback')) {
      const feedback = {
        ...recordOne.parent_feedback,
        replies: replyPosted
          ? [...recordOne.parent_feedback.replies, { id: 9201, author_role: 'parent', author_name: '我（家長）', content: '謝謝老師，我們會繼續複習。', created_at: '2026-09-10T10:00:00Z' }]
          : recordOne.parent_feedback.replies,
      };
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ feedback }) });
    }
    if (url.pathname.includes('/parent/switch-student')) {
      selectedStudentId = 2001;
      mutationCalls.push({ path: url.pathname, method: request.method(), body: request.postDataJSON() });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'e2e-parent-student-2', student: { id: 2001 }, students: [{ id: 2000, name: '測試學生甲' }, { id: 2001, name: '測試學生乙' }] }) });
    }
    if (url.pathname.includes('/parent/sessions/6201/leave')) {
      mutationCalls.push({ path: url.pathname, method: request.method(), body: request.postDataJSON() });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: '請假申請已送出。', session: { id: 6201, status: 'leave_requested' } }) });
    }
    if (request.method() !== 'GET') {
      mutationCalls.push({ path: url.pathname, method: request.method(), body: request.postDataJSON() });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto('/pilot-mount.html?page=parent');
  await expect(page.locator('[data-guide="parent-portal-root"]')).toBeVisible();
  await expect(page.getByText('數學', { exact: true }).first()).toBeVisible();

  const firstRecord = page.locator('.pp-report').first();
  await expect(firstRecord).toBeVisible();
  await firstRecord.locator('.pp-expand-icon').click();
  await expect(firstRecord.getByText('完成分數應用題與錯題訂正。', { exact: true })).toBeVisible();
  await expect(firstRecord.getByRole('textbox', { name: '回覆老師' })).toBeVisible();

  await firstRecord.getByRole('textbox', { name: '回覆老師' }).fill('謝謝老師，我們會繼續複習。');
  await firstRecord.getByRole('button', { name: '回覆老師', exact: true }).click();
  await expect.poll(() => mutationCalls.some((call) => call.path.endsWith('/feedback/reply'))).toBe(true);
  await expect(firstRecord).toContainText('謝謝老師，我們會繼續複習。');
  await page.screenshot({ path: `/tmp/parent-portal-populated-${viewport.width}.png`, fullPage: true });

  await page.getByRole('button', { name: /載入更多/ }).click();
  await expect(page.locator('.pp-report')).toHaveCount(2);
  await expect(page.locator('.pp-lr-subject-heading').filter({ hasText: '英文' })).toBeVisible();
  expect(dashboardCalls.some((call) => call.page === 2)).toBe(true);

  await page.getByRole('tab', { name: /課表/ }).click();
  await page.getByRole('button', { name: '請假', exact: true }).click();
  await page.getByPlaceholder('例如：身體不適、學校活動、臨時有事').fill('測試資料：家庭行程');
  await page.getByRole('button', { name: '送出請假', exact: true }).click();
  await expect(page.getByText('請假申請已送出。', { exact: true })).toBeVisible();
  expect(mutationCalls.some((call) => call.path.endsWith('/sessions/6201/leave'))).toBe(true);

  await page.getByRole('tab', { name: /學習/ }).click();
  await page.locator('#parent-campus-scope').selectOption('2');
  await expect.poll(() => dashboardCalls.at(-1)?.campusId).toBe('2');
  await expect(page.locator('[data-guide="parent-student-card"]')).toContainText('石牌分校');
  await page.getByRole('button', { name: '測試學生乙', exact: true }).click();
  await expect(page.locator('[data-guide="parent-student-card"]')).toContainText('測試學生乙');
  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
}

for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
  test(`parent portal: populated assessment interactions ${name}`, async ({ page }) => {
    await runPopulatedParentPortal(page, viewport);
  });
}
