// @ts-check
import { test, expect } from '@playwright/test';

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '1440', width: 1440, height: 900 },
];

async function openLearningPilot(page, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    const path = new URL(request.url()).pathname;
    if (path.includes('/learning-records')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 501, student_id: 1, student_name: '測試學生甲', student_class_label: 'J1', Subject: '數學', teacher_name: '測試老師', SessionDate: '2026-08-01', StartTime: '10:00', Status: 'pending', body: '待主任確認本次學習進度。' },
            { id: 502, student_id: 1, student_name: '測試學生甲', student_class_label: 'J1', Subject: '英文', teacher_name: '測試老師', SessionDate: '2026-07-31', StartTime: '14:00', Status: 'approved', body: '完成閱讀理解練習。' },
            { id: 503, student_id: 2, student_name: '測試學生乙名稱較長以驗證折行', student_class_label: 'J2', Subject: '自然', teacher_name: '測試老師', SessionDate: '2026-07-30', StartTime: '16:00', Status: 'changes_requested', body: '' },
          ],
          total: 3,
          current_page: 1,
          last_page: 1,
          status_counts: { pending: 1, changes_requested: 1, approved: 1, rejected: 0 },
        }),
      });
    }
    if (path.includes('/notifications')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], unread_count: 0 }) });
    }
    if (path.includes('/students') || path.includes('/teachers') || path.includes('/subjects') || path.includes('/parent-feedback')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });
  await page.goto(`/pilot-mount.html?page=learning&mode=polish`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
}

for (const viewport of viewports) {
  test(`learning records director polish @${viewport.name}`, async ({ page }) => {
    await openLearningPilot(page, viewport);
    await expect(page.getByText('學習評量表', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('button', { name: '待主任核准', exact: false }).first()).toBeVisible();
    await expect(page.locator('.lr-group, .lr-record-card').first()).toBeVisible({ timeout: 15_000 });
    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      records: document.querySelectorAll('.lr-group, .lr-record-card').length,
      activePills: document.querySelectorAll('.lr-review-tabs .lr-tab.active').length,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    expect(layout.records).toBeGreaterThan(0);
    expect(layout.activePills).toBe(1);
    await page.locator('.lr-page').screenshot({ path: `/tmp/learning-records-polish-${viewport.name}.png` });
  });
}
