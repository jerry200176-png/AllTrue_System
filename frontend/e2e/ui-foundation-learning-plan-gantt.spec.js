// @ts-check
// #4970 step 4b: layout contract of the learning-plan Gantt in a real browser (synthetic data, fixed "today").
import { test, expect } from '@playwright/test';
import { mockLearningPlanApi, TODAY } from './fixtures/ui-foundation/learning-plan-gantt-data.js';

async function open(page, { mode = 'plan', size = 'standard' } = {}) {
  await page.clock.setFixedTime(TODAY);
  await mockLearningPlanApi(page, mode);
  await page.goto(`/learning-plan-gantt-pilot-mount.html?size=${size}`);
  await page.getByRole('tab', { name: '學習計畫' }).click();
}

for (const viewport of [{ width: 375, height: 800 }, { width: 1536, height: 784 }]) {
  for (const size of ['standard', 'large']) {
    test(`gantt layout @${viewport.width} ${size}`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await open(page, { size });
      const scroll = page.getByTestId('gantt-scroll');
      await expect(scroll).toBeVisible();
      await expect(page.getByTestId('gantt-row')).toHaveCount(12);

      // On load the today line is inside the visible track; a wide dialog fits the whole 18-week plan without scrolling.
      const visible = () => page.evaluate(() => {
        const box = document.querySelector('[data-testid="gantt-scroll"]').getBoundingClientRect();
        const left = box.left + document.querySelector('[data-testid="gantt-title"]').parentElement.getBoundingClientRect().width;
        const line = document.querySelector('[data-testid="today-line"]').getBoundingClientRect().left;
        const el = document.querySelector('[data-testid="gantt-scroll"]');
        const bars = [...document.querySelectorAll('[data-testid="gantt-bar"]')].map((b) => b.getBoundingClientRect());
        return { todayInside: line >= left && line <= box.right, overflowX: el.scrollWidth - el.clientWidth, barsVisible: bars.filter((r) => r.right > left && r.left < box.right).length };
      });
      await expect.poll(async () => (await visible()).todayInside).toBe(true);
      if (viewport.width >= 1280) {
        const v = await visible();
        expect(v.overflowX).toBeLessThanOrEqual(1);
        expect(v.barsVisible).toBe(12);
      }

      // Nothing outside the chart container scrolls sideways (page and profile dialog).
      const overflow = await page.evaluate(() => {
        const dialog = document.querySelector('[role="dialog"]');
        return { page: document.documentElement.scrollWidth - innerWidth, dialog: dialog.scrollWidth - dialog.clientWidth };
      });
      expect(overflow.page).toBeLessThanOrEqual(0);
      expect(overflow.dialog).toBeLessThanOrEqual(0);

      // Unit titles: at most two lines, and every title carries its full text as a tooltip.
      const titles = await page.getByTestId('gantt-title').evaluateAll((els) => els.map((el) => ({
        tip: el.getAttribute('title'), text: el.textContent.replace(/^\d+\.\s*/, '').trim(),
        lines: Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight)),
      })));
      expect(titles).toHaveLength(12);
      for (const t of titles) {
        expect(t.tip).toBe(t.text);
        expect(t.lines).toBeLessThanOrEqual(2);
      }

      // Rows share one height and every bar sits inside its own row.
      const rows = await page.getByTestId('gantt-row').evaluateAll((els) => els.map((row) => {
        const r = row.getBoundingClientRect();
        const bar = row.querySelector('[data-testid="gantt-bar"]').getBoundingClientRect();
        return { h: Math.round(r.height), inside: bar.top >= r.top && bar.bottom <= r.bottom && bar.left >= r.left && bar.right <= r.right + 1 };
      }));
      expect(new Set(rows.map((r) => r.h)).size).toBe(1);
      expect(rows.every((r) => r.inside)).toBe(true);

      // The title column is sticky: after scrolling the chart sideways it stays at the container's left edge.
      await scroll.evaluate((el) => { el.scrollLeft = 300; });
      const pos = await page.evaluate(() => {
        const box = document.querySelector('[data-testid="gantt-scroll"]');
        const cell = document.querySelector('[data-testid="gantt-title"]').parentElement;
        return { sticky: getComputedStyle(cell).position, scrolled: box.scrollLeft, delta: Math.abs(cell.getBoundingClientRect().left - box.getBoundingClientRect().left) };
      });
      expect(pos.sticky).toBe('sticky');
      if (viewport.width < 1280) expect(pos.scrolled).toBeGreaterThan(0);
      expect(pos.delta).toBeLessThanOrEqual(2);
    });
  }
}

test('gantt states, markers and the read-only and empty variants', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await open(page);
  const states = await page.getByTestId('gantt-bar').evaluateAll((els) => els.map((e) => e.dataset.state));
  expect(states.filter((s) => s === 'done')).toHaveLength(4);
  expect(states.filter((s) => s === 'behind')).toHaveLength(2);
  expect(states.filter((s) => s === 'in_progress')).toHaveLength(1);
  expect(states.filter((s) => s === 'upcoming')).toHaveLength(5);
  // Today and the next exam are labelled and do not overlap each other.
  const chips = await page.getByTestId('gantt-scroll').locator('span.tw\\:rounded-full').evaluateAll((els) => els.map((e) => e.getBoundingClientRect()));
  expect(chips).toHaveLength(2);
  expect(chips[0].right).toBeLessThanOrEqual(chips[1].left);
});

test('read-only (403) hides the write controls; empty state offers the template dialog', async ({ browser }) => {
  const ro = await browser.newPage({ viewport: { width: 375, height: 800 } });
  await open(ro, { mode: 'readonly' });
  await ro.getByRole('checkbox').nth(5).click(); // units 1-4 are already done
  await expect(ro.getByText('你不是這門課的授課老師')).toBeVisible();
  await expect(ro.getByRole('checkbox')).toHaveCount(0);
  await expect(ro.getByTestId('gantt-bar')).toHaveCount(12);
  await ro.close();

  const empty = await browser.newPage({ viewport: { width: 375, height: 800 } });
  await open(empty, { mode: 'empty' });
  await empty.getByRole('button', { name: '套用教材範本' }).click();
  await expect(empty.getByRole('dialog', { name: '套用教材範本' })).toBeVisible();
  await expect(empty.getByRole('combobox', { name: '教材範本' })).toBeVisible();
  await empty.close();
});
