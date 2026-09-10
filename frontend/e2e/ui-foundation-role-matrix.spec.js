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
  const failedRequests = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
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

  const learningTab = page.locator('#parent-tab-learning');
  await learningTab.focus();
  await expect(learningTab).toBeFocused();
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('#parent-tab-schedule')).toBeFocused();
  const focusStyle = await page.locator('#parent-tab-schedule').evaluate((el) => {
    const style = getComputedStyle(el);
    return { outlineStyle: style.outlineStyle, outlineWidth: style.outlineWidth, minHeight: el.getBoundingClientRect().height };
  });
  expect(focusStyle.outlineStyle).toBe('solid');
  expect(Number.parseFloat(focusStyle.outlineWidth)).toBeGreaterThanOrEqual(3);
  expect(focusStyle.minHeight).toBeGreaterThanOrEqual(52);

  await page.locator('.pp-tab').filter({ hasText: '帳務' }).click();
  await expect(page.getByText('繳費提醒', { exact: true })).toBeVisible();
  await expect(page.getByText('剩餘 2 堂', { exact: true })).toBeVisible();

  const layout = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
});

test('parent portal: primary navigation remains usable through loading and error states', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('parent_portal_token', 'e2e-parent-state-token');
  });

  const errors = [];
  const failedRequests = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  let releaseDashboard;
  const dashboardResponse = new Promise((resolve) => { releaseDashboard = resolve; });
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.includes('/parent/dashboard')) {
      await dashboardResponse;
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '測試中的資料服務暫時無法使用' }) });
    }
    if (url.pathname.includes('/parent/notification-preferences')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ learning_feedback_push: false }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto('/pilot-mount.html?page=parent');
  await expect(page.locator('.pp-skeleton-card').first()).toBeVisible();
  await page.screenshot({ path: '/tmp/parent-tab-focus-loading-390.png', fullPage: false });
  releaseDashboard();
  await expect(page.getByRole('alert')).toContainText('目前無法載入家長資料');
  await expect(page.getByRole('button', { name: '重新載入', exact: true })).toBeVisible();
  await page.screenshot({ path: '/tmp/parent-tab-focus-error-390.png', fullPage: false });

  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
});

async function runPopulatedParentPortal(page, viewport) {
  await page.setViewportSize(viewport);
  await page.addInitScript(() => {
    localStorage.setItem('parent_portal_token', 'e2e-parent-populated-token');
  });

  const dashboardCalls = [];
  const mutationCalls = [];
  const errors = [];
  const failedRequests = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
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
    Comment: '這是一段較長的繁體中文學習建議：下次課堂會先複習錯題，再逐步進入新單元；回家後也請陪孩子整理解題步驟，若有不清楚的地方，可以在這裡留言給老師。',
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
  const attention = page.locator('[data-guide="parent-attention-card"]');
  await expect(attention).toBeVisible();
  const attentionItems = attention.locator('.pp-attention-item');
  expect(await attentionItems.count()).toBeGreaterThan(0);
  for (let index = 0; index < await attentionItems.count(); index += 1) {
    const item = attentionItems.nth(index);
    const box = await item.boundingBox();
    expect(box?.height, `attention item ${index} height at ${viewport.width}`).toBeGreaterThanOrEqual(52);
    await item.focus();
    await expect(item).toBeFocused();
    const focusStyle = await item.evaluate((el) => {
      const style = getComputedStyle(el);
      return { outlineStyle: style.outlineStyle, outlineWidth: style.outlineWidth };
    });
    expect(focusStyle.outlineStyle, `attention item ${index} focus at ${viewport.width}`).toBe('solid');
    expect(Number.parseFloat(focusStyle.outlineWidth), `attention item ${index} focus width at ${viewport.width}`).toBeGreaterThanOrEqual(3);
  }
  await page.screenshot({ path: `/tmp/parent-attention-after-${viewport.width}.png`, fullPage: true });
  await attentionItems.first().click();
  await expect(page.locator('#parent-tab-learning')).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('Tab');
  await page.screenshot({ path: `/tmp/parent-header-after-${viewport.width}.png`, fullPage: false });

  for (const selector of ['.pp-btn-logout', '.pp-chip:not([disabled])', '#parent-campus-scope']) {
    const control = page.locator(selector).first();
    await expect(control).toBeVisible();
    const box = await control.boundingBox();
    expect(box?.width, `${selector} width at ${viewport.width}`).toBeGreaterThanOrEqual(44);
    expect(box?.height, `${selector} height at ${viewport.width}`).toBeGreaterThanOrEqual(44);
    await control.focus();
    await expect(control).toBeFocused();
    const focusStyle = await control.evaluate((el) => {
      const style = getComputedStyle(el);
      return { outlineStyle: style.outlineStyle, outlineWidth: style.outlineWidth };
    });
    expect(focusStyle.outlineStyle, `${selector} focus style at ${viewport.width}`).toBe('solid');
    expect(Number.parseFloat(focusStyle.outlineWidth), `${selector} focus width at ${viewport.width}`).toBeGreaterThanOrEqual(3);
  }

  const learningTab = page.locator('#parent-tab-learning');
  await learningTab.focus();
  await expect(learningTab).toBeFocused();
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('#parent-tab-schedule')).toBeFocused();
  await page.screenshot({ path: `/tmp/parent-tab-focus-after-${viewport.width}.png`, fullPage: false });
  const focusStyle = await page.locator('#parent-tab-schedule').evaluate((el) => {
    const style = getComputedStyle(el);
    return { outlineStyle: style.outlineStyle, outlineWidth: style.outlineWidth, minHeight: el.getBoundingClientRect().height };
  });
  expect(focusStyle.outlineStyle).toBe('solid');
  expect(Number.parseFloat(focusStyle.outlineWidth)).toBeGreaterThanOrEqual(3);
  expect(focusStyle.minHeight).toBeGreaterThanOrEqual(52);
  await learningTab.click();

  const firstRecord = page.locator('.pp-report').first();
  await expect(firstRecord).toBeVisible();
  await firstRecord.locator('.pp-expand-icon').click();
  await expect(firstRecord.getByText('完成分數應用題與錯題訂正。', { exact: true })).toBeVisible();
  await expect(firstRecord).toContainText('這是一段較長的繁體中文學習建議');
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
  expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  expect(failedRequests, `失敗請求：\n${failedRequests.join('\n')}`).toEqual([]);
}

test('parent portal: header remains clear for empty and long Traditional Chinese data', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    localStorage.setItem('parent_portal_token', 'e2e-parent-header-token');
  });

  const errors = [];
  const failedRequests = [];
  await page.on('pageerror', (error) => errors.push(String(error)));
  await page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.includes('/parent/dashboard')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          student: { id: 2000, name: '這是一個需要換行顯示的超長學生姓名測試資料', grade: '國三', school: '這是一個需要換行顯示的超長學校名稱', campus_id: 1, campus_name: '臺北市中心分校' },
          students: [{ id: 2000, name: '這是一個需要換行顯示的超長學生姓名測試資料' }, { id: 2001, name: '另一位學生' }],
          enrollments: [{ student_id: 2000, campus_id: 1, campus_name: '臺北市中心分校' }, { student_id: 2000, campus_id: 2, campus_name: '新北市新店區第二分校' }],
          cross_campus_access: 'actions',
          progress_summary: { week_label: '9/7–9/13', week_progress: { attended: 0, scheduled: 0 }, payment: { paid_courses: 0, total_courses: 0, status: 'none' }, pending_total: 0 },
          learning_records: [], learning_records_meta: { total: 0, has_more: false }, attendance_history: [], upcoming_sessions: [], classes: [], remaining_sessions_total: 0, remaining_by_subject: {}, payment_alerts: [], invoices: [], announcements: [], assessment_progress: { items: [] },
        }),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto('/pilot-mount.html?page=parent');
  await expect(page.locator('[data-guide="parent-student-card"]')).toBeVisible();
  await expect(page.locator('#parent-student-switcher-label')).toHaveText(/切換學生/);
  await expect(page.locator('#parent-campus-switcher-label')).toHaveText(/分校範圍/);
  await expect(page.locator('.pp-attention-empty')).toHaveAttribute('role', 'status');
  for (const selector of ['.pp-btn-logout', '.pp-chip:not([disabled])', '#parent-campus-scope']) {
    const box = await page.locator(selector).first().boundingBox();
    expect(box?.width, `${selector} width`).toBeGreaterThanOrEqual(44);
    expect(box?.height, `${selector} height`).toBeGreaterThanOrEqual(44);
  }
  await page.screenshot({ path: '/tmp/parent-header-after-long-390.png', fullPage: false });
  const layout = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(errors).toEqual([]);
  expect(failedRequests).toEqual([]);
});

for (const [name, viewport] of [
  ['mobile', { width: 390, height: 844 }],
  ['mobile-wide', { width: 412, height: 915 }],
  ['tablet', { width: 768, height: 1024 }],
  ['desktop-compact', { width: 1280, height: 900 }],
  ['desktop', { width: 1440, height: 900 }],
]) {
  test(`parent portal: populated assessment interactions ${name}`, async ({ page }) => {
    await runPopulatedParentPortal(page, viewport);
  });
}
