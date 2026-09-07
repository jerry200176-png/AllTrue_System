// @ts-check
import { test, expect } from '@playwright/test';

const session = {
  access_token: 'e2e-teacher-token',
  token: 'e2e-teacher-token',
  user: { id: 9001, role: 'teacher', name: 'E2E Teacher', must_change_password: false },
};

function getMondayOfCurrentWeek() {
  const d = new Date();
  const day = d.getDay();
  const monday = new Date(d.setDate(d.getDate() - day + (day === 0 ? -6 : 1)));
  return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
}

async function installCalendarMocks(page, { mode = 'normal' } = {}) {
  await page.addInitScript((sess) => {
    localStorage.setItem('alltrue_session', JSON.stringify(sess));
    localStorage.setItem('app_branch', '1');
    localStorage.setItem('notifications_sound_enabled', '0');
  }, session);

  const mondayStr = getMondayOfCurrentWeek();
  const courses = mode === 'empty' ? [] : [
    { id: 101, student_id: 1, student_name: '王小明', teacher_id: 9001, teacher_name: 'E2E Teacher', subject: 'math', class_type: 'one_on_one', day_of_week: 1, start_time: '14:00', end_time: '16:00', duration_hours: 2, status: 'active', room_id: 'A101', weeks: [1, 2, 3, 4, 5] },
    { id: 102, student_id: 2, student_name: '測試長姓名學生用來驗證課表卡片排版折行與彈窗資訊是否正常顯示不爆版', teacher_id: 9001, teacher_name: 'E2E Teacher', subject: '國中會考英文總複習衝刺高分保證班', class_type: 'one_on_two', day_of_week: 1, start_time: '16:30', end_time: '18:30', duration_hours: 2, status: 'active', room_id: 'B202', weeks: [1, 2, 3, 4, 5] },
  ];
  const sessions = mode === 'empty' ? [] : [
    { id: 201, class_session_id: 201, student_id: 1, student_class_id: 101, branch_id: 1, session_date: mondayStr, start_time: '14:00', end_time: '16:00', student_name: '王小明', subject_name: '數學', status: 'attended', roll_call_status: 'done' },
  ];

  await page.route('**/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (mode === 'loading') return;
    if (path.includes('/student-classes')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: courses, meta: { total: courses.length, last_page: 1 } }) });
    if (path.includes('/class-sessions')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ api_kind: 'projection', completeness: 'full', data: sessions, by_class: {} }) });
    if (path.includes('/schedules')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    if (path.includes('/teachers')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 9001, username: 'E2E Teacher', name: 'E2E Teacher' }] }) });
    if (path.includes('/students')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 1, name: '王小明' }, { id: 2, name: '測試長姓名學生用來驗證課表卡片排版折行與彈窗資訊是否正常顯示不爆版' }] }) });
    if (path.includes('/branches')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 1, name: '台北總部' }] }) });
    if (path.includes('/learning-records')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Teacher "我的課表" Desktop & Mobile UX Verification', () => {
  const viewports = [
    { name: 'desktop', width: 1280, height: 800 },
    { name: 'tablet-768', width: 768, height: 1024 },
    { name: 'mobile-412', width: 412, height: 915 },
    { name: 'mobile-390', width: 390, height: 844 },
  ];

  for (const vp of viewports) {
    test(`verifies sticky headers, today indicator, and no horizontal overflow on ${vp.name} (${vp.width}x${vp.height})`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await installCalendarMocks(page);

      await page.goto('/pilot-mount.html?page=calendar&role=teacher');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
      await page.waitForSelector('.at-page-header__title');

      // 1. Verify title identifies as teacher schedule
      await expect(page.locator('.at-page-header__title')).toContainText('我的課表');

      // 2. Verify weekday headers exist and "Today" indicator
      const gridWrapper = page.locator('.week-overview-grid-wrapper');
      await expect(gridWrapper).toBeVisible();
      const dayHeaders = page.locator('.day-col-header');
      await expect(dayHeaders.first()).toBeVisible();

      const todayPill = page.locator('.day-col-today-pill, .day-tab-today-pill');
      if (await todayPill.count() > 0) await expect(todayPill.first()).toHaveText('今天');

      // 3. Verify sticky weekday headers while vertically scrolling
      await gridWrapper.evaluate((el) => { el.scrollTop = 350; });
      await page.waitForTimeout(100);
      const scrolledHeaderBox = await dayHeaders.first().boundingBox();
      const wrapperBox = await gridWrapper.boundingBox();
      if (wrapperBox && scrolledHeaderBox) {
        expect(scrolledHeaderBox.y).toBeLessThanOrEqual(wrapperBox.y + 10);
        expect(scrolledHeaderBox.y).toBeGreaterThanOrEqual(wrapperBox.y - 10);
      }

      // 4. Verify no horizontal overflow of the root document
      const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(hasHorizontalOverflow).toBe(false);

      // 5. Verify course card touch target sizing (>= 43px effective touch target)
      const courseBlocks = page.locator('.course-block');
      await expect(courseBlocks.first()).toBeVisible();
      const firstCardBox = await courseBlocks.first().boundingBox();
      if (firstCardBox) expect(firstCardBox.height).toBeGreaterThanOrEqual(43);

      // 6. Verify teacher course card click opens detail modal with correct permissions
      await courseBlocks.first().click();
      const modal = page.locator('.session-edit-modal');
      await expect(modal).toBeVisible();
      await expect(modal.locator('h3')).toHaveText('單堂詳細資訊');

      // Details displayed & financial privacy guarded
      await expect(courseBlocks.first()).toContainText('王小明');
      await expect(modal.locator('.ss-input')).toHaveAttribute('placeholder', '王小明');
      await expect(modal).toContainText('分校#1 · 教室 A101');
      await expect(modal).toContainText('點名狀態');
      await expect(modal.locator('text=本堂費用')).toHaveCount(0);
      await expect(modal.locator('text=一堂課費用')).toHaveCount(0);
      await expect(modal.locator('text=時段與費用')).toHaveCount(0);
      await expect(modal.locator('text=繳費狀態')).toHaveCount(0);

      // Forbidden actions strictly unavailable, safe actions present
      await expect(modal.locator('.action-btn.leave')).toHaveCount(0);
      await expect(modal.locator('.action-btn.reschedule')).toHaveCount(0);
      await expect(modal.locator('.action-btn.substitute')).toHaveCount(0);
      await expect(modal.locator('.action-btn.cancel-session')).toHaveCount(0);
      await expect(modal.locator('.actions button.danger')).toHaveCount(0);
      await expect(modal.locator('[data-testid="calendar-goto-attendance"]')).toBeVisible();
      await expect(modal.locator('[data-testid="calendar-goto-learning"]')).toBeVisible();

      // Modal fits viewport and closes cleanly
      const modalBox = await modal.boundingBox();
      if (modalBox) expect(modalBox.width).toBeLessThanOrEqual(vp.width + 2);
      await modal.locator('button.ghost', { hasText: '關閉' }).click();
      await expect(modal).not.toBeVisible();
    });
  }

  test('verifies long student and course names display without horizontal break', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await installCalendarMocks(page);
    await page.goto('/pilot-mount.html?page=calendar&role=teacher');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await page.waitForSelector('.at-page-header__title');

    const longCard = page.locator('.course-block').filter({ hasText: '測試長姓名學生' });
    await expect(longCard).toBeVisible();
    await longCard.click();
    const modal = page.locator('.session-edit-modal');
    await expect(modal).toBeVisible();
    await expect(modal.locator('.ss-input')).toHaveAttribute('placeholder', '測試長姓名學生用來驗證課表卡片排版折行與彈窗資訊是否正常顯示不爆版');
    await expect(modal).toContainText('分校#1 · 教室 B202');
    const modalBox = await modal.boundingBox();
    if (modalBox) expect(modalBox.width).toBeLessThanOrEqual(392);
    await modal.locator('button.ghost', { hasText: '關閉' }).click();
  });

  test('verifies empty state message and return CTA for teacher', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await installCalendarMocks(page, { mode: 'empty' });
    await page.goto('/pilot-mount.html?page=calendar&role=teacher');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await page.waitForSelector('.at-page-header__title');

    const emptyState = page.locator('.week-overview-empty');
    await expect(emptyState).toBeVisible();
    await expect(emptyState).toContainText('本週尚無排課紀錄');
    await expect(emptyState).toContainText('若有授課安排需求，請聯繫分校行政或主任。');
    await expect(emptyState.locator('button', { hasText: '回到今天' })).toBeVisible();
  });
});
