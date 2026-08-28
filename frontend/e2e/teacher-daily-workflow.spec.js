// @ts-check
import { test, expect } from '@playwright/test';

const session = {
  access_token: 'e2e-teacher-token',
  token: 'e2e-teacher-token',
  user: { id: 9001, role: 'teacher', name: '測試老師', must_change_password: false },
};

async function installTeacherMocks(page, mode = 'normal') {
  const localToday = await page.evaluate(() => {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    const path = new URL(request.url()).pathname;
    if (mode === 'error' && path.includes('/class-sessions')) {
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '今日課表暫時無法載入' }) });
    }
    if (path.includes('/class-sessions')) {
      const rows = mode === 'empty' ? [] : [
        { id: 101, class_session_id: 101, student_id: 401, student_class_id: 201, branch_id: 1, session_date: localToday, start_time: '09:00', end_time: '10:00', student_name: '測試學生甲', subject_name: '數學', status: 'scheduled', learning_record_status: 'changes_requested', learning_record_id: 301 },
        { id: 102, class_session_id: 102, student_id: 402, student_class_id: 202, branch_id: 1, session_date: localToday, start_time: '10:30', end_time: '11:30', student_name: '測試學生乙', subject_name: '英文', status: 'scheduled', learning_record_status: 'missing' },
        { id: 103, class_session_id: 103, student_id: 403, student_class_id: 203, branch_id: 1, session_date: localToday, start_time: '13:00', end_time: '14:00', student_name: '請假學生', subject_name: '自然', status: 'leave_requested', learning_record_status: 'missing' },
      ];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ api_kind: 'projection', completeness: 'full', data: rows, by_class: {} }),
      });
    }
    if (path.includes('/teacher-attendance/today')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'normal', sign_in_dt: `${localToday} 08:40:00`, first_class_start_time: '09:00' }) });
    }
    if (path.includes('/me/learning-pending-summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ total: 2, changes_requested_learning_records: 1 }) });
    }
    if (path.includes('/me/awaiting-reply-count')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ awaiting_reply_count: mode === 'empty' ? 0 : 1 }) });
    }
    if (path.includes('/learning-progress-summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ summary: { expected_sessions: 2, completed_sessions: 1, completion_rate_pct: 50, streak_days: 0 }, by_day: [] }) });
    }
    if (path.includes('/learning-record-feedbacks/analytics')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: null }) });
    }
    if (path.includes('/adoption/task-tracker')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }
    if (path.includes('/teachers') || path.includes('/engagement/ranks-for')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });
  await page.addInitScript((value) => localStorage.setItem('alltrue_session', JSON.stringify(value)), session);
}

test.describe('Teacher daily workflow real Vue page', () => {
  test('prioritizes actionable work and excludes leave-requested sessions', async ({ page }) => {
    await installTeacherMocks(page);
    const secondaryRequests = [];
    page.on('request', (request) => {
      if (request.url().includes('/learning-progress-summary') || request.url().includes('/feedbacks/analytics')) {
        secondaryRequests.push(request.url());
      }
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=teacher');
    await expect(page.getByRole('heading', { name: '今天要完成' })).toBeVisible();
    const priorityDisclosure = page.locator('.th-priority-disclosure');
    await expect(priorityDisclosure.getByText('查看排序規則')).toBeVisible();
    await expect(priorityDisclosure.locator('.th-priority-rules')).toBeHidden();
    await priorityDisclosure.locator('summary').click();
    await expect(priorityDisclosure.getByRole('heading', { name: '今天的處理順序' })).toBeVisible();
    await expect(priorityDisclosure.locator('.th-priority-rules__item')).toHaveCount(3);
    await expect(priorityDisclosure.locator('.th-work-task')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '修改評量' })).toBeVisible();
    await expect(page.getByRole('button', { name: '開始點名' }).first()).toBeVisible();
    await expect(page.locator('.th-work-task').getByText('請假學生')).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
    expect(await page.locator('.th-work-task__cta').first().isVisible()).toBeTruthy();
    expect(secondaryRequests).toHaveLength(0);
    await page.locator('.th-secondary summary').click();
    await expect.poll(() => secondaryRequests.length).toBeGreaterThan(0);
  });

  test('shows a clear empty state without horizontal overflow', async ({ page }) => {
    await installTeacherMocks(page, 'empty');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=teacher&mode=empty');
    await expect(page.getByText('今天沒有待完成工作')).toBeVisible();
    await expect(page.getByRole('button', { name: '查看本週課表' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
  });

  test('keeps the first attendance action visible on mobile', async ({ page }) => {
    await installTeacherMocks(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=attendance&role=teacher');
    await expect(page.getByRole('heading', { name: '先完成今日點名' })).toBeVisible();
    await expect(page.getByRole('button', { name: '開始點名' }).first()).toBeVisible();
    await expect(page.locator('[data-guide="attendance-pending-list"]')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
  });

  test('keeps the work queue readable on desktop', async ({ page }) => {
    await installTeacherMocks(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/pilot-mount.html?page=teacher');
    await expect(page.getByRole('heading', { name: '今天要完成' })).toBeVisible();
    await expect(page.getByRole('button', { name: '修改評量' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
  });

  test('surfaces a schedule error without hiding the workbench', async ({ page }) => {
    await installTeacherMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=teacher');
    await expect(page.getByRole('heading', { name: '今天要完成' })).toBeVisible();
    await expect(page.getByText('無法載入課表，請稍後重試')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
  });
});
