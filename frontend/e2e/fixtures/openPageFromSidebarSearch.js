// @ts-check
import { expect } from '@playwright/test';
import { findNavItem, getNavigationGroups } from '../../src/lib/navigationRegistry.js';
import { dismissOverlays } from './dismissOverlays.js';

/**
 * The label the desktop search shows for `pageKey`: a hub tab's own label, or
 * the sidebar item's label. Read from the shipped navigation registry so specs
 * follow the menu instead of a copy of it.
 *
 * @param {string} role
 * @param {string} pageKey app page key (?app_page=)
 * @returns {string | null}
 */
export function sidebarSearchLabelFor(role, pageKey) {
  const item = findNavItem(getNavigationGroups(role), pageKey);
  if (!item) return null;
  const tab = item.tabs ? item.tabs.find((entry) => entry.page === pageKey) : null;
  return tab ? tab.label : item.label;
}

/**
 * Open a staff page the way a desktop user reaches any menu entry: the sidebar
 * 搜尋／更多功能 trigger, type the entry's label, pick the feature result.
 *
 * #4130: the subject-units acceptance clicked a 科目數統計 sidebar row or a
 * 更多功能 entry. #3866 folded the page into the 報表與薪資 hub, so neither existed and
 * the click waited out the 45s test timeout on every run. Search reaches hub tabs
 * directly (App.vue activeGlobalSearchFeatureGroups) and, unlike clicking the hub,
 * never lands on its PIN-gated default tab (當月學收).
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ role: string, pageKey: string, readySelector: string }} target
 */
export async function openPageFromSidebarSearch(page, { role, pageKey, readySelector }) {
  const label = sidebarSearchLabelFor(role, pageKey);
  expect(label, `${pageKey} must be in the ${role} navigation registry`).toBeTruthy();
  await dismissOverlays(page);
  const trigger = page.locator('nav.sidebar-nav .sidebar-more-trigger').first();
  await expect(trigger, 'sidebar search trigger').toBeVisible({ timeout: 15_000 });
  await trigger.click();
  const panel = page.locator('.sidebar-more-panel');
  await expect(panel, 'sidebar search panel').toBeVisible({ timeout: 15_000 });
  await panel.locator('.sidebar-more-search-input').fill(String(label));
  const result = panel.locator('.global-search-item').filter({
    has: page.locator('.global-search-item-title').getByText(String(label), { exact: true }),
  }).first();
  await expect(result, `search result ${label}`).toBeVisible({ timeout: 15_000 });
  await result.click();
  await expect(page.locator(readySelector), `${pageKey} page`).toBeVisible({ timeout: 15_000 });
}
