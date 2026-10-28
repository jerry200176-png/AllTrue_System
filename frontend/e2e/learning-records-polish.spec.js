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
            { id: 501, student_id: 1, student_name: '測試學生甲', student_class_label: 'J1', Subject: '數學', teacher_name: '測試老師', SessionDate: '2026-08-01', StartTime: '10:00', Status: 'pending', Progress: '' },
            { id: 502, student_id: 1, student_name: '測試學生甲', student_class_label: 'J1', Subject: '英文', teacher_name: '測試老師', SessionDate: '2026-07-31', StartTime: '14:00', Status: 'approved', Progress: '完成閱讀理解練習。' },
            { id: 503, student_id: 2, student_name: '測試學生乙名稱較長以驗證折行', student_class_label: 'J2', Subject: '自然', teacher_name: '測試老師', SessionDate: '2026-07-30', StartTime: '16:00', Status: 'changes_requested', Progress: '' },
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

async function assertDirectorStatusTypography(locator) {
  const statusTypography = await locator.locator('.status-tag, .fill-badge').evaluateAll((elements) => (
    elements.map((element) => ({
      fontSize: Number.parseFloat(getComputedStyle(element).fontSize),
      fontWeight: Number.parseInt(getComputedStyle(element).fontWeight, 10),
      text: (element.textContent || '').trim(),
    }))
  ));
  expect(statusTypography.length).toBeGreaterThanOrEqual(2);
  for (const typography of statusTypography) {
    expect(typography.fontSize).toBeGreaterThanOrEqual(13);
    expect(typography.fontWeight).toBeGreaterThanOrEqual(700);
    expect(['未填', '已填', '待審核']).not.toContain(typography.text);
  }
}

for (const viewport of viewports) {
  test(`learning records director polish @${viewport.name}`, async ({ page }) => {
    await openLearningPilot(page, viewport);
    await expect(page.getByText('學習評量表', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('tab', { name: '待主任核准', exact: false }).first()).toBeVisible();
    await page.getByRole('button', { name: '卡片', exact: true }).click();
    // In-app #417: phones always render cards, so the no-op 列表 choice must not be offered.
    await expect(page.getByRole('button', { name: '列表', exact: true })).toHaveCount(viewport.width <= 640 ? 0 : 1);
    await expect(page.locator('.lr-group, .lr-record-card').first()).toBeVisible({ timeout: 15_000 });
    const pendingCard = page.locator('.lr-record-card').filter({ hasText: '測試學生甲' }).first();
    await expect(pendingCard.getByText('審核：待主任核准', { exact: true })).toBeVisible();
    await expect(pendingCard.getByText('評量內容未填', { exact: true })).toBeVisible();
    await assertDirectorStatusTypography(pendingCard);
    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      records: document.querySelectorAll('.lr-group, .lr-record-card').length,
      activePills: document.querySelectorAll('.lr-review-tabs .lr-tab.active').length,
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    expect(layout.records).toBeGreaterThan(0);
    expect(layout.activePills).toBe(1);

    // Desktop default is list/table; lock the same fill vs review nouns there (#2715 / #2983).
    // Director "pending" queue also includes changes_requested rows client-side.
    if (viewport.width >= 760) {
      await page.getByRole('button', { name: '列表', exact: true }).click();
      await expect(page.locator('.lr-table-scroll').first()).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('.lr-table-scroll .fill-badge').filter({ hasText: '評量內容未填' }).first()).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('.lr-table-scroll .status-tag').filter({ hasText: /^審核：/ }).first()).toBeVisible();

      const pendingGroup = page.locator('.lr-group').filter({ hasText: '測試學生甲' });
      await pendingGroup.locator('.at-disclosure__toggle').click();
      await expect(pendingGroup.locator('.fill-badge').filter({ hasText: '評量內容未填' })).toBeVisible({ timeout: 15_000 });
      await expect(pendingGroup.locator('.status-tag').filter({ hasText: '審核：待主任核准' })).toBeVisible();
      await assertDirectorStatusTypography(pendingGroup);

      const tableLayout = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      expect(tableLayout.scrollWidth).toBeLessThanOrEqual(tableLayout.clientWidth);
    }

    await page.locator('.lr-page').screenshot({ path: `/tmp/learning-records-polish-${viewport.name}.png` });
  });
}

// Student groups exist only in the desktop list view; phones always get flat record cards (#417).
test('#4488 student group cards expand in place from the keyboard @1440', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await openLearningPilot(page, viewports[1]);
  await page.getByRole('button', { name: '列表', exact: true }).click();
  await page.locator('#lr-review-tab-all').click();
  const group = page.locator('.lr-group').filter({ hasText: '測試學生甲' });
  const toggle = group.locator('.at-disclosure__toggle');
  const body = group.locator('.at-disclosure__body');
  await expect(group).toBeVisible({ timeout: 15_000 });
  // The name still filters by student and is its own button beside the toggle.
  await expect(group.getByRole('button', { name: '測試學生甲', exact: true })).toBeVisible();
  await expect(toggle.locator('button')).toHaveCount(0);

  // Reach the toggle with Tab from the name button, as a keyboard user would.
  await group.getByRole('button', { name: '測試學生甲', exact: true }).focus();
  await page.keyboard.press('Tab');
  await expect(toggle).toBeFocused();
  if (await toggle.getAttribute('aria-expanded') === 'true') await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(body).toHaveAttribute('inert', '');
  await expect(group.locator('.lr-table-scroll')).toBeHidden();
  // Collapsed card keeps its count and status, shows stacked edges, and keeps a visible focus ring.
  await expect(group.locator('.lr-group-count')).toHaveText('2 筆');
  await expect(group.locator('.lr-group-pending')).toBeVisible();
  await expect(toggle).toBeFocused();
  expect(await toggle.evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');
  expect(await group.evaluate((el) => getComputedStyle(el, '::after').opacity)).toBe('1');

  await page.keyboard.press('Space');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(group.locator('.lr-table-scroll').first()).toBeVisible();
  expect(await group.evaluate((el) => getComputedStyle(el, '::after').opacity)).toBe('0');
  // Reduced motion: no height slide (the global rule leaves at most 0.01ms).
  expect(Number.parseFloat(await body.evaluate((el) => getComputedStyle(el).transitionDuration))).toBeLessThan(0.001);

  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
});
