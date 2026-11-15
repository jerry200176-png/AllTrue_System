// @ts-check
/**
 * Automated WCAG 2.1 A/AA scan (axe-core) of real Vue pages on the pilot mount,
 * with every API call answered by an empty list.
 *
 * Ratchet: BASELINE holds today's serious/critical violations (rule id → node count)
 * and must match exactly. More nodes or a new rule fails as a regression; fewer
 * nodes fails too, asking you to lower the entry, so fixed debt cannot come back.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const BLOCKING_IMPACTS = new Set(['serious', 'critical']);
// Mobile reveals controls hidden on desktop (e.g. .att-mobile-only). Same baseline at both widths today.
const VIEWPORTS = [{ name: 'desktop', width: 1280, height: 900 }, { name: 'mobile', width: 390, height: 844 }];

// Baseline 2026-10-03 (axe-core 4.13 via @axe-core/playwright 4.13).
// Remaining color-contrast nodes are hardcoded hex / non-token colors (tokens now AA-safe).
const BASELINE = {
  'course-manager': {},
  'course-manager&tab=sessions': {},
  'course-manager&tab=settings': {},
  'course-manager&tab=billing': {},
  teacher: {},
  director: {},
  students: {},
  admissions: {},
  calendar: { 'color-contrast': 8 },
  chat: { 'color-contrast': 1 },
  parent: { 'color-contrast': 1 },
  attendance: { 'color-contrast': 2 },
  profile: {},
  login: {},
  'attendance&role=teacher': { 'color-contrast': 2 },
  'release-notes': {},
  discrepancy: {},
  'branch-health': {},
  'subject-settings': {},
  classroom: {},
  'binding-conflicts': {},
  'binding-health': {},
};

for (const [name, allowed] of Object.entries(BASELINE)) for (const vp of VIEWPORTS) {
  test(`axe WCAG A/AA: ${name} (${vp.name}) has no new serious/critical violations`, async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.route('**/api/**', (route) => route.fulfill({ contentType: 'application/json', body: '{"data":[]}' }));
    await page.goto(`/pilot-mount.html?page=${name}`);
    await expect(page.locator('[data-pilot-ready="1"]')).toHaveCount(1);
    await page.waitForLoadState('networkidle');

    const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    expect(pageErrors, 'page threw while mounting, so the scan is not trustworthy').toEqual([]);
    const blocking = violations.filter((v) => BLOCKING_IMPACTS.has(v.impact ?? ''));
    const regressions = blocking
      .filter((v) => v.nodes.length > (allowed[v.id] ?? 0))
      .map((v) => ({
        rule: v.id,
        impact: v.impact,
        nodes: v.nodes.length,
        allowed: allowed[v.id] ?? 0,
        help: v.helpUrl,
        targets: v.nodes.slice(0, 5).map((n) => n.target.join(' ')),
      }));
    expect(regressions).toEqual([]);
    const found = Object.fromEntries(blocking.map((v) => [v.id, v.nodes.length]));
    const staleBaseline = Object.entries(allowed)
      .filter(([rule, count]) => (found[rule] ?? 0) < count)
      .map(([rule, count]) => `${name}.${rule}: baseline ${count}, now ${found[rule] ?? 0}; lower BASELINE`);
    expect(staleBaseline).toEqual([]);
  });
}

// #5334: an empty page has no table rows, so it cannot detect invalid row ARIA.
for (const width of [375, 1280]) {
  test(`populated student disclosures retain table semantics and pass axe @${width}`, async ({ page }, info) => {
    const writes = [];
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/api/**', route => {
      const request = route.request();
      const url = new URL(request.url());
      if (request.method() !== 'GET') {
        writes.push({ method: request.method(), path: url.pathname });
        return route.abort();
      }
      const students = [
        { id: 2000, name: '合成學生甲', grade: 'J1', status: 'active', line_bound: false },
        { id: 2001, name: '合成學生乙', grade: 'J1', status: 'paused', line_bound: false },
      ];
      return route.fulfill({ json: url.pathname === '/api/v1/students' ? { data: students, total: 2 } : { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=students');
    const rows = page.getByTestId('student-row');
    await expect(rows).toHaveCount(2);
    const row = rows.first();
    const phone = row.getByTestId('student-row-toggle');
    const desktop = row.getByTestId('student-course-disclosure');
    const scan = async phase => {
      const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
      const blocking = violations.filter(v => BLOCKING_IMPACTS.has(v.impact ?? ''));
      await info.attach(`student-axe-${phase}`, { body: JSON.stringify(blocking), contentType: 'application/json' });
      expect(blocking.map(v => ({ rule: v.id, nodes: v.nodes.map(n => ({ target: n.target, detail: n.failureSummary })) }))).toEqual([]);
    };
    await scan('collapsed');
    await expect(row).not.toHaveAttribute('aria-expanded');
    await expect(row).not.toHaveAttribute('role');
    await expect(row).toHaveAttribute('data-expanded', 'false');
    for (const control of [phone, desktop]) {
      await expect(control).toHaveAttribute('aria-expanded', 'false');
      await expect(control).toHaveAttribute('aria-controls', 'student-course-detail-2000');
    }
    await row.focus();
    await row.press('Enter');
    await expect(row).toHaveAttribute('data-expanded', 'true');
    await expect(page.locator('#student-course-detail-2000')).toBeVisible();
    for (const control of [phone, desktop]) await expect(control).toHaveAttribute('aria-expanded', 'true');
    await expect(row).toBeFocused();
    await scan('expanded');
    await row.press('Space');
    await expect(row).toHaveAttribute('data-expanded', 'false');
    const visibleControl = width < 768 ? phone : desktop;
    await visibleControl.focus();
    await visibleControl.press('Enter');
    await expect(row).toHaveAttribute('data-expanded', 'true');
    await expect(visibleControl).toBeFocused();
    await visibleControl.click();
    await expect(row).toHaveAttribute('data-expanded', 'false');
    await expect(page.getByTestId('student-select').first()).not.toBeChecked();
    await page.getByTestId('student-select').first().click();
    await expect(row).toHaveAttribute('data-expanded', 'false');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    expect(writes).toEqual([]);
    expect(errors).toEqual([]);
  });
}
