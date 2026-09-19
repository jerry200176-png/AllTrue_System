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
    window.print = () => {
      window.__calendarPrintAcceptance.printCalls += 1;
      throw new Error('production acceptance must not call window.print');
    };
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
      const method = String(init.method || input?.method || 'GET').toUpperCase();
      const url = typeof input === 'string' ? input : input?.url || '';
      const isLocalTelemetry = method === 'POST'
        && new URL(url, window.location.href).pathname === '/api/v1/adoption/events';
      if (isLocalTelemetry) return originalFetch(input, init);
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        window.__calendarPrintAcceptance.writes.push({ method, url, channel: 'fetch' });
        throw new Error(`production acceptance blocked ${method} fetch`);
      }
      return originalFetch(input, init);
    };
    const xhrOpen = XMLHttpRequest.prototype.open;
    const xhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function guardedOpen(method, url, ...args) {
      this.__calendarPrintMethod = String(method || 'GET').toUpperCase();
      this.__calendarPrintUrl = String(url || '');
      return xhrOpen.call(this, method, url, ...args);
    };
    XMLHttpRequest.prototype.send = function guardedSend(...args) {
      const method = this.__calendarPrintMethod || 'GET';
      const isLocalTelemetry = method === 'POST'
        && new URL(this.__calendarPrintUrl, window.location.href).pathname === '/api/v1/adoption/events';
      if (isLocalTelemetry) return xhrSend.apply(this, args);
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        window.__calendarPrintAcceptance.writes.push({ method, url: this.__calendarPrintUrl, channel: 'xhr' });
        throw new Error(`production acceptance blocked ${method} XHR`);
      }
      return xhrSend.apply(this, args);
    };
  }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
}

async function assertPrintPreviewContract(page, expectedPeriod) {
  const dialog = page.locator('[data-calendar-print-dialog]');
  await expect(dialog).toBeVisible({ timeout: 20_000 });
  await expect(dialog.getByRole('heading', { name: '列印課表', exact: true })).toBeVisible();
  const period = dialog.locator('.calendar-print-toolbar select').first();
  await period.selectOption(expectedPeriod);
  await expect(period).toHaveValue(expectedPeriod);
  const settled = dialog.locator('.calendar-print-sheet').or(
    dialog.locator('.calendar-print-state').filter({ hasNotText: '正在準備' }),
  ).first();
  await expect(settled).toBeVisible({ timeout: 20_000 });
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
        const srgb = value.match(/color\(srgb\s+([^/\s]+)\s+([^/\s]+)\s+([^/\s)]+)/i);
        if (srgb) return srgb.slice(1, 4).map((part) => Number.parseFloat(part) * 255);
        const rgb = value.match(/rgba?\(([^)]+)\)/);
        if (rgb) return rgb[1].trim().split(/[,\s]+/).slice(0, 3).map((part) => Number.parseFloat(part));
        return null;
      };
      const luminance = (rgb) => rgb.reduce((sum, channel, index) => {
        const normalized = channel / 255;
        const linear = normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        return sum + linear * [0.2126, 0.7152, 0.0722][index];
      }, 0);
      const bg = parse(background);
      const fg = parse(foreground);
      if (!bg || !fg) return { background, foreground, ratio: 0, parsed: false };
      const [light, dark] = [luminance(bg), luminance(fg)].sort((a, b) => b - a);
      return { background, foreground, ratio: (light + 0.05) / (dark + 0.05), parsed: true };
    });
    expect(contrast.background, 'dark-theme print sheet must remain white or near-white').toMatch(/rgb\(|color\(srgb/i);
    expect(contrast.parsed, 'Chromium computed color must be parseable').toBe(true);
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

function assertSuppressedTelemetry(payloads) {
  const eventNames = new Set([
    'calendar_print_preview_opened',
    'calendar_print_failed',
    'calendar_print_requested',
  ]);
  const metaKeys = new Set([
    'mode', 'range_start', 'range_end', 'orientation', 'row_count_bucket', 'result',
    'telem_session', 'telem_day',
  ]);
  const modes = new Set(['week', 'month']);
  const orientations = new Set(['portrait', 'landscape']);
  const results = new Set(['success', 'network', 'validation', 'http_4xx', 'http_5xx']);
  const rowBuckets = new Set(['0', '1-25', '26-100', '101-500', '500+']);
  const datePattern = /^\d{4}-\d{2}-\d{2}$/;
  expect(payloads.length, 'calendar preview should emit bounded telemetry').toBeGreaterThan(0);
  for (const payload of payloads) {
    expect(Object.keys(payload).sort()).toEqual(['branch_id', 'event', 'meta']);
    expect(eventNames.has(payload.event)).toBe(true);
    expect(Number.isInteger(payload.branch_id)).toBe(true);
    expect(payload.branch_id).toBeGreaterThan(0);
    expect(payload.meta && typeof payload.meta === 'object' && !Array.isArray(payload.meta)).toBe(true);
    expect(Object.keys(payload.meta).sort()).toEqual([...metaKeys].sort());
    expect(modes.has(payload.meta.mode)).toBe(true);
    expect(datePattern.test(payload.meta.range_start)).toBe(true);
    expect(datePattern.test(payload.meta.range_end)).toBe(true);
    expect(payload.meta.range_start <= payload.meta.range_end).toBe(true);
    expect(orientations.has(payload.meta.orientation)).toBe(true);
    expect(rowBuckets.has(payload.meta.row_count_bucket)).toBe(true);
    expect(results.has(payload.meta.result)).toBe(true);
    expect(typeof payload.meta.telem_session).toBe('string');
    expect(payload.meta.telem_session).toMatch(/^t_[a-z0-9_]+$/);
    expect(datePattern.test(payload.meta.telem_day)).toBe(true);
  }
}

test.describe('production acceptance — calendar print preview', () => {
  test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
    'missing controlled production director session');

  test('director opens week/month print preview without printing or writes', async ({ page }) => {
    const suppressedTelemetry = [];
    await page.route('**/api/v1/adoption/events', async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      if (request.method() !== 'POST' || url.pathname !== '/api/v1/adoption/events') {
        await route.continue();
        return;
      }
      suppressedTelemetry.push(request.postDataJSON());
      await route.fulfill({ status: 204, body: '' });
    });
    await installSession(page);
    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);
    await page.getByRole('button', { name: '班級行事曆', exact: false }).first().click();
    await expect(page.getByRole('heading', { name: '班級行事曆 / 課表', exact: true })).toBeVisible({ timeout: 20_000 });
    await page.locator('html').evaluate((element) => { element.dataset.theme = 'dark'; });
    await page.getByRole('button', { name: '列印課表', exact: true }).click();
    await assertPrintPreviewContract(page, 'week');
    await assertPrintPreviewContract(page, 'month');
    const dialog = page.locator('[data-calendar-print-dialog]');
    await expect(dialog.getByRole('button', { name: '列印／另存 PDF', exact: true })).toBeVisible();
    const controls = await page.evaluate(() => window.__calendarPrintAcceptance);
    expect(controls.printCalls).toBe(0);
    expect(controls.writes).toEqual([]);
    assertSuppressedTelemetry(suppressedTelemetry);
  });
});
