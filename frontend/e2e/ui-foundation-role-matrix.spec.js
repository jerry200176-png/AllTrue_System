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
