// @ts-check
import { test, expect } from '@playwright/test';
import { openPageFromSidebarSearch } from './fixtures/openPageFromSidebarSearch.js';

/**
 * #4130 regression: the production acceptance reaches 科目數統計 through the shared
 * openPageFromSidebarSearch fixture. Run that same fixture against the real App
 * shell (mocked API) so a menu change that hides the page from it fails here in
 * seconds instead of timing out the production gate.
 */
test('director reaches 科目數統計 (報表與薪資 hub tab) through the acceptance navigation path', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const timelineRequests = [];
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    if (route.request().method() !== 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    if (p.endsWith('/me')) {
      await route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      return;
    }
    if (p.endsWith('/branches') || p.endsWith('/campuses')) {
      await route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      return;
    }
    if (p.includes('/notifications')) {
      await route.fulfill({ json: { data: [], unread_count: 0 } });
      return;
    }
    if (p.endsWith('/finance/subject-units/timeline')) {
      timelineRequests.push(url.search);
      await route.fulfill({ json: { scope: { role: 'director', campus_ids: [1] }, teacher_contributions: [], days: [] } });
      return;
    }
    await route.fulfill({ json: { data: [] } });
  });

  await page.goto('/pilot-mount.html?page=app');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');

  await openPageFromSidebarSearch(page, {
    role: 'director',
    pageKey: 'subject-units',
    readySelector: '[data-guide="subject-units-header"]',
  });

  await expect(page.locator('nav.hub-tabs').getByRole('button', { name: '科目數統計' })).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('.pin-gate-placeholder')).toHaveCount(0);
  await expect.poll(() => timelineRequests.length).toBeGreaterThan(0);
});
