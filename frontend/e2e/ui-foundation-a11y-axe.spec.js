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
// Most color-contrast nodes are muted text #64748d on #f6f9fc (4.49:1, needs 4.5)
// and orange #ef6c00 with white (3.08:1).
const BASELINE = {
  teacher: { 'color-contrast': 3 },
  students: { 'color-contrast': 4 },
  admissions: { 'color-contrast': 11 },
  calendar: { 'color-contrast': 21 },
  chat: { 'color-contrast': 2 },
  parent: { 'color-contrast': 1 },
  attendance: { 'color-contrast': 11, label: 2 },
  profile: { 'color-contrast': 2, label: 1 },
  'attendance&role=teacher': { 'color-contrast': 10, label: 5, 'select-name': 1 },
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
