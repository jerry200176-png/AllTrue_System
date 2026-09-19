// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const BASE = process.env.SMOKE_BASE_URL;
const BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 16);
const CURRENT_STAFF_RELEASE = latestReleaseVersionForRole('director');

function readSession() {
  const encoded = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!encoded) return null;
  try { return JSON.parse(Buffer.from(encoded, 'base64').toString('utf8')); } catch { return null; }
}

const SESSION = readSession();

async function installSession(page) {
  await page.addInitScript(({ session, branch, releaseVersion }) => {
    localStorage.setItem('alltrue_session', JSON.stringify(session));
    localStorage.setItem('app_branch', String(branch));
    localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
    sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
    window.__calendarPrintAcceptance = { printCalls: 0, writes: [] };
    const originalPrint = window.print;
    window.print = () => { window.__calendarPrintAcceptance.printCalls += 1; return originalPrint?.(); };
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
      const method = String(init.method || 'GET').toUpperCase();
      const url = typeof input === 'string' ? input : input?.url || '';
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) window.__calendarPrintAcceptance.writes.push({ method, url });
      return originalFetch(input, init);
    };
  }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
}

function parseRgb(value) {
  const match = String(value).match(/rgba?\(([^)]+)\)/);
  if (!match) return null;
  const values = match[1].split(',').slice(0, 3).map((part) => Number.parseFloat(part.trim()));
  return values.length === 3 && values.every(Number.isFinite) ? values : null;
}

function relativeLuminance(rgb) {
  return rgb.reduce((sum, channel, index) => {
    const value = channel / 255;
    const linear = value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    return sum + linear * [0.2126, 0.7152, 0.0722][index];
  }, 0);
}

async function assertPrintPreviewContract(page) {
  const dialog = page.locator('[data-calendar-print-dialog]');
  await expect(dialog).toBeVisible({ timeout: 20_000 });
  await expect(dialog.getByRole('heading', { name: '列印課表', exact: true })).toBeVisible();
  const period = dialog.locator('.calendar-print-toolbar select').first();
  await expect(period.locator('option[value="week"]')).toHaveText('本週');
  await expect(period.locator('option[value="month"]')).toHaveText('當月');
  await expect(dialog.locator('input[type="search"]')).toHaveCount(3);
  await expect(dialog.locator('.calendar-print-statuses input[type="checkbox"]')).toHaveCount(5);
  await expect(dialog.locator('.calendar-print-statuses input:checked')).toHaveCount(5);

  const hasPreview = await dialog.locator('.calendar-print-sheet').count() > 0;
  if (hasPreview) {
    const sheet = dialog.locator('.calendar-print-sheet').first();
    const contrast = await sheet.evaluate((element) => {
      const panel = element.closest('.calendar-print-dialog__panel');
      const background = getComputedStyle(element).backgroundColor;
      const foreground = getComputedStyle(panel || element).color;
      const parse = (value) => {
        const match = value.match(/rgba?\(([^)]+)\)/);
        return match ? match[1].split(',').slice(0, 3).map((part) => Number.parseFloat(part.trim())) : null;
      };
      const luminance = (rgb) => rgb.reduce((sum, channel, index) => {
        const normalized = channel / 255;
        const linear = normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        return sum + linear * [0.2126, 0.7152, 0.0722][index];
      }, 0);
      const bg = parse(background);
      const fg = parse(foreground);
      if (!bg || !fg) return { background, foreground, ratio: 0 };
      const [light, dark] = [luminance(bg), luminance(fg)].sort((a, b) => b - a);
      return { background, foreground, ratio: (light + 0.05) / (dark + 0.05) };
    });
    expect(contrast.background, 'dark-theme print sheet must remain white or near-white').toMatch(/rgb\((25[0-5]|2[4-5][0-9]), (25[0-5]|2[4-5][0-9]), (25[0-5]|2[4-5][0-9])\)/);
    expect(contrast.ratio, 'print sheet text must remain readable in dark theme').toBeGreaterThanOrEqual(4.5);
    await expect(sheet.locator('th')).toHaveCount(7);
    await expect(sheet.locator('footer')).toContainText('校內核對');
  } else {
    await expect(dialog.locator('.calendar-print-state')).toContainText('沒有可列印的課程');
  }

  const controls = await page.evaluate(() => window.__calendarPrintAcceptance);
  expect(controls.printCalls, 'acceptance must not invoke window.print').toBe(0);
  expect(controls.writes, 'acceptance must not call a write API').toEqual([]);
}

test('fixture: calendar print selectors and dark-theme contrast contract', async ({ page }) => {
  await page.setContent(`
    <html data-theme="dark"><body>
      <div data-calendar-print-dialog role="dialog">
        <div class="calendar-print-dialog__panel" style="background-color: white; color: rgb(31, 41, 55)">
          <h2>列印課表</h2>
          <section class="calendar-print-toolbar">
            <select><option value="week">本週</option><option value="month">當月</option></select>
            <input type="search"><input type="search"><input type="search">
            <fieldset class="calendar-print-statuses">${['請假', '補課', '代課', '調課', '已取消'].map((label) => `<label><input type="checkbox" checked>${label}</label>`).join('')}</fieldset>
          </section>
          <div class="calendar-print-sheet" style="background-color: white"><table><thead><tr>${'<th></th>'.repeat(7)}</tr></thead></table><footer>本課表含學生姓名，僅供校內核對</footer></div>
        </div>
      </div>
    </body></html>`);
  const sheet = page.locator('.calendar-print-sheet');
  await expect(sheet).toBeVisible();
  const colors = await sheet.evaluate((element) => ({
    background: getComputedStyle(element).backgroundColor,
    color: getComputedStyle(element.closest('.calendar-print-dialog__panel')).color,
  }));
  expect(relativeLuminance(parseRgb(colors.background))).toBeGreaterThan(0.9);
  expect(relativeLuminance(parseRgb(colors.color))).toBeLessThan(0.2);
  await expect(page.locator('.calendar-print-statuses input:checked')).toHaveCount(5);
});

test.describe('production acceptance — calendar print preview', () => {
  test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
    'missing controlled production director session');

  test('director opens week/month print preview without printing or writes', async ({ page }) => {
    await installSession(page);
    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);
    await page.getByRole('button', { name: '班級行事曆', exact: false }).first().click();
    await expect(page.getByRole('heading', { name: '班級行事曆 / 課表', exact: true })).toBeVisible({ timeout: 20_000 });
    await page.locator('html').evaluate((element) => { element.dataset.theme = 'dark'; });
    await page.getByRole('button', { name: '列印課表', exact: true }).click();
    await assertPrintPreviewContract(page);

    const dialog = page.locator('[data-calendar-print-dialog]');
    const period = dialog.locator('.calendar-print-toolbar select').first();
    await period.selectOption('month');
    await expect(period).toHaveValue('month');
    await expect(dialog.locator('.calendar-print-state, .calendar-print-sheet').first()).toBeVisible({ timeout: 20_000 });
    await period.selectOption('week');
    await expect(period).toHaveValue('week');
    await expect(dialog.getByRole('button', { name: '列印／另存 PDF', exact: true })).toBeVisible();
    const controls = await page.evaluate(() => window.__calendarPrintAcceptance);
    expect(controls.printCalls).toBe(0);
    expect(controls.writes).toEqual([]);
  });
});
