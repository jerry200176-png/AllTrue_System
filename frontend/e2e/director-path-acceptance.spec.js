// @ts-check
import { execFileSync, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { appOriginOf, installReadOnlyAppGuard, isKnownPostRead } from './fixtures/readOnlyAppGuard.js';
import { installSingleStudentExportGuard } from './fixtures/directorExportGuard.js';
import { installDirectorCalendarMocks, observeDirectorCalendarBundles, readDirectorRuntimeIdentity } from './fixtures/directorCalendarAcceptance.js';

// Run only through playwright.director-acceptance.config.js in the existing credentialed smoke job.
// No session extraction, screenshots, traces, video, response dumps or downloaded attachments.
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });
const BASE = process.env.SMOKE_BASE_URL;
const ORIGIN = appOriginOf(BASE);

test.beforeEach(() => {
  if (!ORIGIN || !process.env.SMOKE_DIRECTOR_USER || !process.env.SMOKE_DIRECTOR_PASS) {
    throw new Error('UNVERIFIED: existing runtime director smoke inputs are required.');
  }
});

async function privateAcceptance(work) {
  try { await work(); }
  catch { throw new Error('UNVERIFIED: director path acceptance did not complete; private details suppressed.'); }
}

/** Same login controls as smoke.spec.js; wait for submission before continuing. */
async function login(page) {
  await page.goto('/');
  await page.locator('#login-account').fill(process.env.SMOKE_DIRECTOR_USER);
  await page.locator('#login-password').fill(process.env.SMOKE_DIRECTOR_PASS);
  await page.locator('button.login-btn').click();
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

async function navigate(page, label) {
  await dismissOverlays(page);
  const button = page.locator('nav.sidebar-nav').getByRole('button', { name: label, exact: false }).first();
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await button.click({ force: true });
    if (await page.getByRole('heading', { name: label, exact: true }).isVisible().catch(() => false)
      || /(?:^|\s)active(?:\s|$)/.test(await button.getAttribute('class') || '')) return;
    await page.waitForTimeout(400);
  }
  throw new Error('UNVERIFIED: destination did not become active.');
}

test('director acceptance: approved assessment rollback prompt can be cancelled without a business POST', async ({ page, context }) => {
  await privateAcceptance(async () => {
    let pageErrors = 0;
    page.on('pageerror', () => { pageErrors += 1; });
    await login(page);
    const blocked = await installReadOnlyAppGuard(context, ORIGIN, { allowTransactionTelemetry: true });
    await navigate(page, '學習評量');
    await page.locator('#lr-review-tab-approved').click();
    await page.getByRole('button', { name: '卡片', exact: true }).click();
    const card = page.locator('.lr-record-card')
      .filter({ has: page.getByRole('button', { name: '退回待審', exact: true }) })
      .filter({ has: page.getByRole('button', { name: '編輯評量', exact: true }) }).first();
    await card.getByRole('button', { name: '退回待審', exact: true }).waitFor({ state: 'visible', timeout: 15_000 });
    await card.getByRole('button', { name: '編輯評量', exact: true }).click();
    const editor = page.locator('.lr-modal--learning-form');
    const rollback = editor.getByRole('button', { name: '退回待審', exact: true });
    await expect(rollback).toBeVisible();
    const before = blocked.filter(request => !isKnownPostRead(ORIGIN, request)).length;
    let promptSeen = false;
    page.once('dialog', async dialog => { promptSeen = dialog.type() === 'prompt'; await dialog.dismiss(); });
    await rollback.click();
    await expect.poll(() => promptSeen).toBe(true);
    await page.waitForTimeout(200);
    expect(blocked.filter(request => !isKnownPostRead(ORIGIN, request)).length).toBe(before);
    expect(blocked.filter(request => /\/rollback-approval$/.test(request.path)).length).toBe(0);
    await expect(rollback).toBeEnabled();
    await editor.getByRole('button', { name: '關閉', exact: true }).click();
    expect(pageErrors).toBe(0);
    console.log(JSON.stringify({ acceptance: 'assessment-cancel', verified: true, rollback_posts: 0, blocked_background_writes: before }));
  });
});

test('director acceptance: one student UI filter downloads a typed native workbook', async ({ page, context }) => {
  await privateAcceptance(async () => {
    let pageErrors = 0;
    page.on('pageerror', () => { pageErrors += 1; });
    await login(page);
    await installReadOnlyAppGuard(context, ORIGIN, { allowTransactionTelemetry: true });
    await navigate(page, '帳務中心');
    await page.getByTestId('bill-filter-owing').click();
    const candidates = await page.locator('[data-testid^="bill-row-"][data-kind="owing"]').evaluateAll(rows => rows
      .filter(row => row.querySelector('.bl__check:not(:disabled)')).slice(0, 5)
      .map(row => row.querySelector('.bl__name')?.textContent || ''));
    let selected;
    for (const name of candidates) {
      if (!name.trim() || name.trim().length > 60) continue;
      await page.getByTestId('bill-search').fill(name.trim());
      await page.getByTestId('bill-search').press('Tab');
      const visible = await page.locator('[data-testid^="bill-row-"]').evaluateAll(rows => rows.map(row => ({
        key: (row.getAttribute('data-testid') || '').slice('bill-row-'.length),
        student: row.querySelector('.bl__name')?.getAttribute('data-student'),
        selectable: Boolean(row.querySelector('.bl__check:not(:disabled)')),
      })));
      if (visible.length && visible.length <= 2000 && new Set(visible.map(row => row.student)).size === 1
        && visible.every(row => /^[1-9][0-9]*$/.test(row.student || '') && /^[cp][1-9][0-9]*$/.test(row.key))
        && visible.some(row => row.selectable)) { selected = { name, keys: visible.map(row => row.key) }; break; }
    }
    if (!selected) throw new Error('UNVERIFIED: no suitable uniquely filtered student bills.');
    // Export uses visible rows, not the bulk-operation checkbox selection. The UI search above bounds its scope.
    await page.locator('.bl__check:not(:disabled)').first().check();
    const guard = await installSingleStudentExportGuard(context, ORIGIN, selected.keys, selected.name.trim());
    const downloaded = page.waitForEvent('download', { timeout: 30_000 });
    await page.getByTestId('bill-export').click();
    const download = await downloaded;
    try {
      const script = fileURLToPath(new URL('../../scripts/student_bill_xlsx_acceptance.py', import.meta.url));
      const checked = spawnSync('python3', [script], { input: JSON.stringify({ path: await download.path(), student: selected.name, sources: selected.keys.length }),
        encoding: 'utf8', timeout: 10_000, maxBuffer: 16 * 1024 });
      const summary = JSON.parse(checked.stdout || '{}');
      expect(checked.status === 0 && summary.valid === true && summary.sheets === 1).toBe(true);
      expect(guard.exportCount()).toBe(1);
      expect(guard.blocked.filter(request => !isKnownPostRead(ORIGIN, request)).length).toBe(0);
      expect(pageErrors).toBe(0);
      console.log(JSON.stringify({ acceptance: 'student-xlsx', verified: true, sheets: summary.sheets,
        summary_rows: summary.summary_rows, numeric_amount_cells: summary.numeric_amount_cells }));
    } finally { await download.delete(); }
  });
});

test('director acceptance: 528 deployed calendar replaces a moved card using only mocked reschedule data', async ({ page, context }) => {
  await privateAcceptance(async () => {
    const local = ['127.0.0.1', 'localhost'].includes(new URL(ORIGIN).hostname);
    const readCounters = async () => {
      const response = await context.request.get(ORIGIN + '/__director-acceptance-counts', { maxRedirects: 0 });
      try { return await response.json(); } finally { await response.dispose(); }
    };
    const localCounters = local ? await readCounters() : null;
    const before = await readDirectorRuntimeIdentity(context, ORIGIN);
    const bundles = observeDirectorCalendarBundles(page, ORIGIN);
    const calendar = await installDirectorCalendarMocks(context, ORIGIN);
    let pageErrors = 0;
    page.on('pageerror', () => { pageErrors += 1; });
    await login(page);
    const blocked = await installReadOnlyAppGuard(context, ORIGIN, { allowTransactionTelemetry: true });
    await calendar.install();
    await page.clock.setFixedTime(new Date('2026-11-09T11:00:00Z'));
    await navigate(page, '班級行事曆');
    await page.getByRole('button', { name: '週檢視', exact: true }).click();
    const card = page.locator('.course-block').filter({ hasText: 'Synthetic Move' });
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('aria-label', /20:00/);
    await card.click();
    await page.getByRole('button', { name: '🔄 調課', exact: true }).click();
    await page.locator('#cal-reschedule-date').fill('2026-11-09');
    await page.locator('#cal-reschedule-start').selectOption('19:30');
    const completed = page.waitForEvent('dialog');
    const submitting = page.getByRole('button', { name: '確認調課', exact: true }).click();
    const dialog = await completed;
    expect(dialog.type()).toBe('alert');
    expect(dialog.message().includes('19:30')).toBe(true);
    await dialog.accept();
    await submitting;
    await expect(page.locator('#cal-reschedule-date')).toHaveCount(0);
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('aria-label', /19:30/);
    await expect(card).not.toHaveAttribute('aria-label', /20:00/);
    await expect(page.locator('.course-block').filter({ hasText: 'Synthetic Other' })).toHaveCount(1);
    expect(calendar.counts.reschedule).toBe(1);
    expect(calendar.counts.projection).toBeGreaterThan(1);
    expect(calendar.counts.rejected).toBe(0);
    expect(calendar.branchBound()).toBe(true);
    expect(blocked.filter(request => !isKnownPostRead(ORIGIN, request))).toEqual([]);
    expect(pageErrors).toBe(0);
    if (local) expect(await readCounters()).toEqual(localCounters);
    expect(await readDirectorRuntimeIdentity(context, ORIGIN)).toBe(before);
    const loaded = await bundles();
    const harness = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: fileURLToPath(new URL('../..', import.meta.url)), encoding: 'utf8' }).trim();
    console.log(JSON.stringify({ acceptance: 'calendar-528-mocked-reschedule', verified: true,
      synthetic_path_only: true, production_transaction_committed: 'UNKNOWN',
      local, harness_sha: harness,
      frontend_sha_before: before, frontend_sha_after: before, ...calendar.counts, bundles: loaded }));
  });
});
