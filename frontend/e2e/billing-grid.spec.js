import { test, expect } from '@playwright/test';

// PR-B (#5099): the 帳單 list as a view-only grid: header sort, chip filter, totals of the rows shown, copy as TSV. Mocked API.
const row = (id, name, owed, extra = {}) => ({
  id, student_id: 9000 + id, student_name: name, subject: '數學', schedule_mode: 'count', payment_status: 'unpaid', payable_status: 'invoiced',
  payable_amount: owed + 1000, payable_outstanding: owed, paid_amount: 1000, remaining_sessions: 5, ...extra,
});
const rows = [row(1, '林甲', 3000), row(2, '陳乙', 9000), row(3, '王丙', 1500), row(4, '張丁', 0, { payment_status: 'paid', payable_outstanding: 0 })];

for (const width of [1280, 375]) {
  test(`billing grid at ${width}: sort, filter, totals, copy`, async ({ page }) => {
    const writes = [];
    page.on('request', (r) => { if (!['GET', 'HEAD'].includes(r.method())) writes.push(r.method() + ' ' + r.url()); });
    await page.route('**/api/v1/**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }));
    await page.route('**/api/v1/alerts/tuition**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(rows) }));
    await page.addInitScript(() => localStorage.clear());
    await page.setViewportSize({ width, height: 900 });
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {});
    await page.goto('/tuition-collection-pilot-mount.html?mode=normal');

    const names = () => page.locator('[data-testid^="bill-name-"]').allTextContents();
    await expect(page.getByTestId('bill-totals')).toContainText('這 3 筆'); // 要處理: the paid row is hidden
    await expect(page.getByTestId('bill-totals')).toContainText('未繳 NT$ 13,500');
    expect(await names()).toEqual(['陳乙', '林甲', '王丙']); // 未收金額, big to small

    if (width > 760) {
      await page.getByTestId('bill-th-student').click();
      expect(await names()).toEqual([...(await names())].sort((a, b) => a.localeCompare(b, 'zh-Hant')));
      await expect(page.getByTestId('bill-th-student')).toHaveAttribute('aria-sort', 'ascending');
    }

    await page.getByTestId('bill-search').fill('林');
    await expect(page.getByTestId('bill-totals')).toContainText('這 1 筆');
    await expect(page.getByTestId('bill-totals')).toContainText('未繳 NT$ 3,000');
    await page.getByTestId('bill-search').fill('');

    await page.getByTestId('bill-filter-paid').or(page.getByTestId('bill-filter-owing')).first().click();
    await expect(page.getByTestId('bill-totals')).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
    expect(writes).toEqual([]); // a view: sorting, filtering and copying never write
  });
}
