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
    const url = new URL(request.url());
    if (mode === 'overdue-error'
      && path.endsWith('/class-sessions')
      && url.searchParams.get('end') !== localToday) {
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '補填提醒資料暫時無法載入' }) });
    }
    if (mode === 'error' && path.includes('/class-sessions')) {
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '今日課表暫時無法載入' }) });
    }
    if (path.includes('/class-sessions')) {
      const rows = mode === 'empty' ? [] : mode === 'time-order' ? [
        { id: 110, class_session_id: 110, student_id: 410, student_class_id: 210, branch_id: 1, session_date: localToday, start_time: '10:00', end_time: '12:00', student_name: '上午課程學生', subject_name: '數學', status: 'scheduled', learning_record_status: 'approved', learning_record_id: 310 },
        { id: 113, class_session_id: 113, student_id: 413, student_class_id: 213, branch_id: 1, session_date: localToday, start_time: '13:00', end_time: '15:00', student_name: '下午課程學生', subject_name: '化學', status: 'scheduled', learning_record_status: 'missing' },
      ] : [
        { id: 101, class_session_id: 101, student_id: 401, student_class_id: 201, branch_id: 1, session_date: localToday, start_time: '09:00', end_time: '10:00', student_name: mode === 'long' ? '測試學生超長姓名用於驗證課表操作區折行與可達性' : '測試學生甲', subject_name: mode === 'long' ? '英文進階閱讀與寫作' : '數學', status: 'scheduled', learning_record_status: 'changes_requested', learning_record_id: 301 },
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
      if (mode === 'feedback-error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '家長回覆資料暫時無法載入' }) });
      }
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
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps teacher actions reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installTeacherMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=teacher&mode=long');
      await expect(page.locator('[data-guide="teacher-home-today"]')).toBeVisible();

      const actionable = page.locator('.th-next-action__cta, .th-work-task__cta, .th-fill-btn, .th-report-btn, .icon-btn');
      await expect(actionable.first()).toBeVisible();
      const dimensions = await actionable.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.filter(({ height }) => height > 0).every(({ height }) => height >= 44)).toBe(true);
      const nextAction = page.locator('.th-next-action__cta');
      await nextAction.focus();
      await expect(nextAction).toBeFocused();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      await page.locator('.th-page').screenshot({
        path: `${process.env.TEACHER_DAILY_SHOT_DIR || 'test-results/teacher-daily'}/vue-teacher-long-${viewport.name}.png`,
      });
    });
  }

  test('keeps the teacher work queue explicit while data is loading', async ({ page }) => {
    await installTeacherMocks(page);
    await page.route('**/api/v1/**', async () => new Promise(() => {}));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=teacher&mode=loading');
    await expect(page.locator('.th-work-task--skeleton').first()).toBeVisible();
    await expect(page.getByText('正在整理今天的任務，等一下就會顯示。')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
    await page.locator('.th-page').screenshot({
      path: `${process.env.TEACHER_DAILY_SHOT_DIR || '/tmp/alltrue-teacher-after-20260909'}/vue-teacher-loading-390.png`,
    });
  });

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
    const companion = page.locator('[data-guide="teacher-home-companion"]');
    await expect(companion).toBeVisible();
    await expect(companion.getByRole('heading', { name: '先完成最重要的一件事' })).toBeVisible();
    await expect(companion.locator('img')).toHaveAttribute('alt', '');
    const queueLink = companion.getByRole('link', { name: '查看今日任務' });
    await expect(queueLink).toHaveAttribute('href', '#teacher-work-queue-title');
    await queueLink.click();
    await expect(page.locator('#teacher-work-queue-title')).toBeFocused();
    await expect(page.getByRole('heading', { name: '今天要完成' })).toBeVisible();
    const priorityDisclosure = page.locator('.th-priority-disclosure');
    await expect(priorityDisclosure.getByText('查看排序規則')).toBeVisible();
    await expect(priorityDisclosure.locator('.th-priority-rules')).toBeHidden();
    await priorityDisclosure.locator('summary').click();
    await expect(priorityDisclosure.getByRole('heading', { name: '今天的處理順序' })).toBeVisible();
    await expect(priorityDisclosure.locator('.th-priority-rules__item')).toHaveCount(3);
    await expect(priorityDisclosure.locator('.th-work-task')).toHaveCount(0);
    const nextAction = page.locator('[data-guide="teacher-next-action"]');
    await expect(nextAction).toBeVisible();
    await expect(nextAction.getByText('現在先做')).toBeVisible();
    await expect(nextAction.getByRole('heading', { name: '評量需要修改' })).toBeVisible();
    await expect(nextAction.getByRole('button', { name: '修改評量' })).toBeVisible();
    await expect(nextAction.locator('.th-next-action__cta')).toHaveClass(/at-btn--primary/);
    const secondaryCta = page.locator('.th-work-task__cta').first();
    await expect(secondaryCta).toBeVisible();
    await expect(secondaryCta).toHaveClass(/at-btn--ghost/);
    await expect(secondaryCta).not.toHaveClass(/at-btn--primary/);
    await expect(page.getByRole('button', { name: '開始點名' }).first()).toBeVisible();
    await expect(page.locator('.th-work-task').getByText('請假學生')).toHaveCount(0);
    const clockinCard = page.getByRole('button', { name: /今日打卡狀態/ });
    await expect(clockinCard).toHaveAttribute('type', 'button');
    await expect(clockinCard).toHaveAttribute('aria-describedby', 'teacher-clockin-status');
    await clockinCard.press('Space');
    await expect.poll(() => page.evaluate(() => window.__pilotLastNavigation)).toBe('attendance');
    await expect(page.getByRole('button', { name: '上一週' })).toHaveAttribute('aria-label', '上一週');
    await expect(page.getByRole('button', { name: '下一週' })).toHaveAttribute('aria-label', '下一週');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
    expect(await page.locator('.th-work-task__cta').first().isVisible()).toBeTruthy();
    expect(secondaryRequests).toHaveLength(0);
    await expect(page.locator('[data-guide="teacher-secondary-actions"]')).toBeVisible();
  });

  test('orders same-tier attendance and learning work by class time', async ({ page }) => {
    await installTeacherMocks(page, 'time-order');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=teacher&mode=time-order');

    const nextAction = page.locator('[data-guide="teacher-next-action"]');
    await expect(nextAction.getByRole('heading', { name: '待點名' })).toBeVisible();
    await expect(nextAction).toContainText('10:00–12:00');
    await expect(nextAction.getByRole('button', { name: '開始點名' })).toBeVisible();

    const followingTasks = page.locator('.th-work-task');
    await expect(followingTasks.first()).toContainText('13:00–15:00');
    await expect(followingTasks.first().getByRole('button', { name: '填寫評量' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(
      await page.evaluate(() => document.documentElement.clientWidth),
    );
  });

  test('shows a clear empty state without horizontal overflow', async ({ page }) => {
    await installTeacherMocks(page, 'empty');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=teacher&mode=empty');
    await expect(page.locator('[data-guide="teacher-home-companion"]')).toContainText('今天的課務完成了');
    await expect(page.locator('[data-guide="teacher-home-companion"]').getByRole('link', { name: '查看今日摘要' })).toBeVisible();
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
    await expect(page.locator('[data-guide="teacher-next-action"]')).toBeVisible();
    await expect(page.locator('[data-guide="teacher-next-action"] .th-next-action__cta')).toHaveClass(/at-btn--primary/);
    await expect(page.locator('.th-work-task__cta').first()).toHaveClass(/at-btn--ghost/);
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
    await expect(page.getByRole('alert')).toContainText('今天的工作清單尚未完整載入');
    await expect(page.getByText('今天沒有待完成工作')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '重新整理今日任務' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(overflow).toBeTruthy();
  });

  test('keeps actionable work visible when parent reply data fails', async ({ page }) => {
    await installTeacherMocks(page, 'feedback-error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=teacher');
    await expect(page.locator('[data-guide="teacher-next-action"]')).toBeVisible();
    await expect(page.getByRole('button', { name: '修改評量' })).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('家長回覆資料暫時無法載入');
    await expect(page.getByText('其他工作仍可繼續處理')).toBeVisible();
    await expect(page.getByText('今天的工作清單尚未完整載入')).toHaveCount(0);
  });

  test('keeps current actions visible when overdue learning data fails', async ({ page }) => {
    await installTeacherMocks(page, 'overdue-error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=teacher');
    await expect(page.locator('[data-guide="teacher-next-action"]')).toBeVisible();
    await expect(page.getByRole('button', { name: '修改評量' })).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('補填提醒資料暫時無法載入');
    await expect(page.getByText('其他工作仍可繼續處理')).toBeVisible();
    await expect(page.getByText('今天的工作清單尚未完整載入')).toHaveCount(0);
  });
});
