// @ts-check
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { LEGACY_FLAG_UNVERIFIED_LABEL } from '../src/lib/courseMoneyState.js';
import {
  appOriginOf, classifyAppRequest, installReadOnlyAppGuard, isKnownPostRead,
} from './fixtures/readOnlyAppGuard.js';

/**
 * UI/UX plan step 0 (tracker #4659): READ-ONLY friction baseline for the 5 main director tasks.
 * Each task starts at the director home, drives the UI until the form/dialog is visible, then closes it.
 * Nothing is ever submitted. Counts: clicks, app_page changes, full document loads.
 * Task level results are soft (missing fixture => reached:false + note); the only hard assertion is that no
 * non-read request got a response from production after login (readOnlyAppGuard aborts them all, and answers
 * adoption / transaction-only Sentry telemetry locally). Output: test-results/task-friction.json (no names logged).
 */
const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = { account: process.env.SMOKE_DIRECTOR_USER, password: process.env.SMOKE_DIRECTOR_PASS };
const APP_ORIGIN = appOriginOf(BASE);
const GUARD_OPTIONS = { allowTransactionTelemetry: true };
const SEARCH_CHAR = process.env.SMOKE_FRICTION_SEARCH_CHAR || '陳';
const SHORT = { timeout: 6_000 };

test.skip(!BASE || !DIRECTOR.account || !DIRECTOR.password, 'SMOKE_BASE_URL / director credentials not set');
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });

const appPage = (url) => new URL(url).searchParams.get('app_page') || '';

test('director task friction baseline (read-only)', async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  // Responses to non-read requests that the guard did not answer locally = a write reached production.
  const writes = [];
  let guarding = false;
  let blocked = [];
  // Only requests sent after the guard is installed count; login-time telemetry can answer later (ui-smoke on #4882).
  const guardedRequests = new WeakSet();
  page.on('request', (req) => { if (guarding) guardedRequests.add(req); });
  page.on('response', (res) => {
    const req = res.request();
    if (!guardedRequests.has(req)) return;
    const action = classifyAppRequest(APP_ORIGIN, req.method(), req.url(), GUARD_OPTIONS);
    if (action === 'allow' || action === 'ignore') return;
    if ((action === 'stub-telemetry' || action === 'inspect-telemetry') && res.status() === 204) return;
    writes.push(`${req.method()} ${new URL(req.url()).pathname}`);
  });

  // Login (before the guard: login itself is a POST).
  await page.goto('/');
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  await page.locator('button.login-btn').click({ force: true });
  if (await page.locator('#login-account').count()) await page.locator('button.login-btn').click({ force: true });
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
  // Login is a POST, so the guard goes on after it. It is a context route: every later non-read is aborted.
  blocked = await installReadOnlyAppGuard(page.context(), APP_ORIGIN, GUARD_OPTIONS);
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
  /** Waits (bounded) until the locator shows; unlike isVisible() it retries, so slow renders do not read as "absent". */
  const shows = (locator, timeout = 6_000) => expect(locator.first()).toBeVisible({ timeout }).then(() => true, () => false);
  const isGet = (res, path) => res.request().method() === 'GET' && new URL(res.url()).pathname === path;
  /** Clicks, then waits for the ledger GET and the contract cards it renders. false = ledger never loaded (never hangs). */
  const clickAndWaitLedger = async (locator) => {
    const loaded = page.waitForResponse((res) => isGet(res, '/api/v1/accounting/ledger'), { timeout: 15_000 }).then(() => true, () => false);
    await click(locator);
    return (await loaded) && shows(page.locator('[data-testid^="contract-card-"]'), 10_000);
  };

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
  /** 課程查找 is only settled when its skeletons are gone and the course requests went quiet. */
  const coursesSettled = async () => {
    await page.locator('.course-list-skeleton, .pending-leave-case--skeleton').first()
      .waitFor({ state: 'hidden', timeout: 20_000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {});
  };

  await run('1 登記收款 from 課程查找', async () => {
    await nav('課程查找');
    await coursesSettled();
    const btn = page.locator('button.payment-next-action', { hasText: '登記收款' }).first();
    if (!(await visible(btn))) return { reached: false, note: 'no course with 登記收款 visible' };
    const ledgerLoaded = await clickAndWaitLedger(btn);
    const reached = await shows(page.locator('.at-dialog__panel--sheet .at-dialog__title', { hasText: '登記收款' }));
    return { reached, note: reached ? '' : `entry form not visible${ledgerLoaded ? '' : ' (ledger did not load)'}` };
  });

  await run('2 補登收款 for legacy-paid course', async () => {
    await nav('課程查找');
    await coursesSettled();
    const row = page.locator('.payment-status-and-action', { hasText: LEGACY_FLAG_UNVERIFIED_LABEL }).locator('button.payment-next-action').first();
    if (!(await visible(row))) return { reached: false, note: 'skipped: no fixture' };
    const ledgerLoaded = await clickAndWaitLedger(row);
    // A legacy-paid row opens the back-fill form directly (initialAction 'legacy-backfill'): that modal counts as reached.
    const modal = page.locator('.at-dialog__panel--sheet'); // #4659 step 3: the form opens in the right-side sheet
    if (await shows(modal, ledgerLoaded ? 3_000 : 1_000)) return { reached: true, note: 'back-fill form opened directly' };
    const backfill = page.locator('[data-testid="legacy-backfill-open"]').first();
    if (!(await shows(backfill))) return { reached: false, note: ledgerLoaded ? 'ledger has no 補登收款 button' : 'ledger did not load' };
    await click(backfill);
    const reached = await shows(modal);
    return { reached, note: reached ? '' : 'entry form not visible' };
  });

  await run('3 新增課程 for a student', async () => {
    await nav('學生管理');
    await click(page.getByRole('button', { name: /課程\/購課/ }).first());
    await click(page.getByRole('button', { name: /新增課程/ }).first());
    const form = page.locator('.universal-scheduler-modal').first(); // UniversalClassScheduler, the new-course form
    const conflict = page.locator('.enrollment-conflict-modal').first(); // EnrollmentConflictDecisionModal
    await form.or(conflict).waitFor({ state: 'visible', timeout: 6_000 }).catch(() => {});
    if (await visible(conflict)) return { reached: false, note: 'stopped at enrollment-conflict dialog' };
    const reached = await visible(form);
    return { reached, note: reached ? '' : 'course form not visible' };
  });

  await run('4 續課 for a course', async () => {
    await nav('課程查找');
    await coursesSettled();
    const direct = page.locator('[data-testid="course-row-actions"]').getByRole('button', { name: /^續課/ }).first();
    if (await visible(direct)) await click(direct);
    else {
      const manage = page.locator('[data-testid="course-manager-open"]').first();
      if (!(await visible(manage))) return { reached: false, note: 'no course row with 續課 / 管理課程' };
      await click(manage);
      // The course manager opens on the overview tab; 續課 renders only under the billing tab.
      await click(page.locator('[data-testid="course-manager-tab-billing"]').first());
      const renew = page.locator('[data-testid="course-manager"]').getByRole('button', { name: /^(續課|加購)/ }).first();
      if (!(await shows(renew))) return { reached: false, note: 'course manager has no 續課 button' };
      await click(renew);
    }
    // PurchaseSessionsModal (續課), RenewMonthlyModal (月結續約), StudentsList sessions dialog (#sessions-modal-title)
    const dialog = page.locator('.modal-title, #sessions-modal-title').filter({ hasText: /續課|月結續約/ }).first();
    const reached = await dialog.isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? '' : '續課 dialog not visible' };
  });

  await run('5 find student by name search', async () => {
    await nav('學生管理');
    // StudentsList debounces the search (300 ms) into GET /api/v1/students?search=...
    const searched = page.waitForResponse((res) => isGet(res, '/api/v1/students') && new URL(res.url()).searchParams.has('search'), { timeout: 15_000 }).then(() => true, () => false);
    await page.locator('#students-name-filter').fill(SEARCH_CHAR); // typing is not counted as a click
    if (!(await searched)) return { reached: false, note: 'search request never fired' };
    await page.locator('.students-list-state--loading, .students-refresh-state').first().waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
    const first = page.locator('tr.student-row').first();
    if (!(await shows(first))) return { reached: false, note: 'search returned no row (typed 1 char)' };
    await click(first);
    const reached = await page.locator('[id^="student-course-detail-"]').first().isVisible(SHORT).catch(() => false);
    return { reached, note: reached ? 'typed 1 char, not counted as click' : 'record did not open' };
  });

  fs.mkdirSync('test-results', { recursive: true });
  fs.writeFileSync('test-results/task-friction.json', JSON.stringify({
    results,
    blockedWrites: [...new Set(blocked.map((b) => `${b.method} ${b.path}${isKnownPostRead(APP_ORIGIN, b) ? ' (post read)' : ''}`))],
    telemetry: blocked.telemetryCounts,
  }, null, 2));
  console.log('blocked (never reached production):', [...new Set(blocked.map((b) => `${b.method} ${b.path}`))].join(', ') || 'none');
  console.log('task | clicks | pageSwitches | fullLoads | reached | note');
  for (const r of results) console.log(`${r.task} | ${r.clicks} | ${r.pageSwitches} | ${r.fullLoads} | ${r.reached} | ${r.note}`);

  expect(writes, 'no POST/PUT/PATCH/DELETE may reach production').toEqual([]);
});
