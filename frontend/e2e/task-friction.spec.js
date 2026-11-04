// @ts-check
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { LEGACY_FLAG_UNVERIFIED_LABEL } from '../src/lib/courseMoneyState.js';

/**
 * UI/UX plan step 0 (tracker #4659): READ-ONLY friction baseline for the 5 main director tasks.
 * Each task starts at the director home, drives the UI until the form/dialog is visible, then closes it.
 * Nothing is ever submitted. Counts: clicks, app_page changes, full document loads.
 * Task level results are soft (missing fixture => reached:false + note); the only hard assertion is that no
 * POST/PUT/PATCH/DELETE left the browser after login. Output: test-results/task-friction.json (no names logged).
 */
const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = { account: process.env.SMOKE_DIRECTOR_USER, password: process.env.SMOKE_DIRECTOR_PASS };
// Read-only POSTs/telemetry the app itself sends; they are answered locally and never reach production.
const LOCAL_ANSWER = ['/api/v1/adoption/events', '/api/v1/student-classes/session-dates'];
const SEARCH_CHAR = process.env.SMOKE_FRICTION_SEARCH_CHAR || '陳';
const SHORT = { timeout: 6_000 };

test.skip(!BASE || !DIRECTOR.account || !DIRECTOR.password, 'SMOKE_BASE_URL / director credentials not set');
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });

const appPage = (url) => new URL(url).searchParams.get('app_page') || '';

test('director task friction baseline (read-only)', async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const writes = [];
  let guarding = false;
  await page.route('**/*', (route) => {
    const req = route.request();
    if (!guarding || ['GET', 'HEAD', 'OPTIONS'].includes(req.method())) return route.fallback();
    const path = new URL(req.url()).pathname;
    if (LOCAL_ANSWER.includes(path)) return route.fulfill({ status: 204, body: '' });
    return route.abort('blockedbyclient');
  });
  // Every non-read request after login is aborted above; the hard check is that none of them got a response.
  const blocked = [];
  page.on('requestfailed', (req) => {
    if (guarding && !['GET', 'HEAD', 'OPTIONS'].includes(req.method())) blocked.push(`${req.method()} ${new URL(req.url()).pathname}`);
  });
  page.on('response', (res) => {
    const req = res.request();
    if (guarding && !['GET', 'HEAD', 'OPTIONS'].includes(req.method())
      && !LOCAL_ANSWER.includes(new URL(req.url()).pathname)) writes.push(`${req.method()} ${new URL(req.url()).pathname}`);
  });

  // Login (before the guard: login itself is a POST).
  await page.goto('/');
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  await page.locator('button.login-btn').click({ force: true });
  if (await page.locator('#login-account').count()) await page.locator('button.login-btn').click({ force: true });
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
  guarding = true;

  let clicks = 0;
  let loads = 0;
  let switches = 0;
  let lastPage = '';
  page.on('load', () => { loads += 1; });
  const sync = async () => {
    await page.waitForTimeout(400);
    const now = appPage(page.url());
    if (now !== lastPage) { switches += 1; lastPage = now; }
  };
  /** The only click path: counts, then re-reads app_page. */
  const click = async (locator) => {
    await locator.click(SHORT);
    clicks += 1;
    await sync();
  };
  const visible = (locator) => locator.first().isVisible().catch(() => false);

  const results = [];
  const run = async (task, fn) => {
    let reached = false;
    let note = '';
    try {
      await page.goto('/?app_page=director');
      await dismissOverlays(page);
      await page.waitForTimeout(800);
      clicks = 0; loads = 0; switches = 0; lastPage = appPage(page.url());
      const out = await fn();
      reached = out.reached;
      note = out.note || '';
    } catch (error) {
      note = `error: ${String(error?.message || error).split('\n')[0].slice(0, 120)}`;
    }
    results.push({ task, clicks, pageSwitches: switches, fullLoads: loads, reached, note });
    // Close whatever is open; Escape never submits.
    for (let i = 0; i < 3; i += 1) await page.keyboard.press('Escape').catch(() => {});
    await dismissOverlays(page);
  };
  const nav = (label) => click(page.locator('nav.sidebar-nav').getByRole('button', { name: label }).first());

  await run('1 登記收款 from 課程查找', async () => {
    await nav('課程查找');
    const btn = page.locator('button.payment-next-action', { hasText: '登記收款' }).first();
    if (!(await visible(btn))) return { reached: false, note: 'no course with 登記收款 visible' };
    await click(btn);
    const reached = await page.locator('.pe-modal h3', { hasText: '登記收款' }).first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? '' : 'entry form not visible' };
  });

  await run('2 補登收款 for legacy-paid course', async () => {
    await nav('課程查找');
    const row = page.locator('.payment-status-and-action', { hasText: LEGACY_FLAG_UNVERIFIED_LABEL }).locator('button.payment-next-action').first();
    if (!(await visible(row))) return { reached: false, note: 'skipped: no fixture' };
    await click(row);
    const backfill = page.locator('[data-testid="legacy-backfill-open"]').first();
    if (!(await visible(backfill))) return { reached: false, note: 'ledger has no 補登收款 button' };
    await click(backfill);
    const reached = await page.locator('.pe-modal').first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? '' : 'entry form not visible' };
  });

  await run('3 新增課程 for a student', async () => {
    await nav('學生管理');
    await click(page.getByRole('button', { name: /課程\/購課/ }).first());
    await click(page.getByRole('button', { name: /新增課程/ }).first());
    const reached = await page.locator('.modal-overlay, [role="dialog"]').first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? '' : 'course form not visible' };
  });

  await run('4 續課 for a course', async () => {
    await nav('課程查找');
    const direct = page.locator('[data-testid="course-row-actions"]').getByRole('button', { name: /^續課/ }).first();
    if (await visible(direct)) await click(direct);
    else {
      const manage = page.locator('[data-testid="course-manager-open"]').first();
      if (!(await visible(manage))) return { reached: false, note: 'no course row with 續課 / 管理課程' };
      await click(manage);
      const renew = page.locator('[data-testid="course-manager"]').getByRole('button', { name: /^(續課|加購)/ }).first();
      if (!(await visible(renew))) return { reached: false, note: 'course manager has no 續課 button' };
      await click(renew);
    }
    const reached = await page.locator('.modal-title', { hasText: '續課' }).first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? '' : '續課 dialog not visible' };
  });

  await run('5 find student by name search', async () => {
    await nav('學生管理');
    await page.locator('#students-name-filter').fill(SEARCH_CHAR); // typing is not counted as a click
    await page.waitForTimeout(1_500);
    const first = page.locator('tr.student-row').first();
    if (!(await visible(first))) return { reached: false, note: 'search returned no row (typed 1 char)' };
    await click(first);
    const reached = await page.locator('[id^="student-course-detail-"]').first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? 'typed 1 char, not counted as click' : 'record did not open' };
  });

  fs.mkdirSync('test-results', { recursive: true });
  fs.writeFileSync('test-results/task-friction.json', JSON.stringify({ results, blockedWrites: [...new Set(blocked)] }, null, 2));
  console.log('blocked (never reached production):', [...new Set(blocked)].join(', ') || 'none');
  console.log('task | clicks | pageSwitches | fullLoads | reached | note');
  for (const r of results) console.log(`${r.task} | ${r.clicks} | ${r.pageSwitches} | ${r.fullLoads} | ${r.reached} | ${r.note}`);

  expect(writes, 'no POST/PUT/PATCH/DELETE may reach production').toEqual([]);
});
