// @ts-check
/**
 * DRAFT — install path: frontend/e2e/agreed-fee-isolated-fullstack.spec.js
 *
 * Browser → same-origin /api (Vite proxy) → Laravel → AllTrue_test.
 * Fail-closed when AGREED_FEE_ISOLATED_REQUIRE≠0 (default): missing/non-loopback env throws.
 * Success UI only via fixture onChanged (`changed`); close/cancel is not success.
 */
import { test, expect } from '@playwright/test';

const REQUIRE = process.env.AGREED_FEE_ISOLATED_REQUIRE !== '0';
const base = process.env.AGREED_FEE_ISOLATED_BASE_URL;
const api = process.env.AGREED_FEE_ISOLATED_API_URL;
const token = process.env.AGREED_FEE_ISOLATED_TOKEN;

const LOOPBACK_HOSTS = new Set(['127.0.0.1', 'localhost']);

function assertLoopbackUrl(raw, label) {
  if (!raw) throw new Error(`${label} missing`);
  let u;
  try {
    u = new URL(raw);
  } catch {
    throw new Error(`${label} not a URL: ${raw}`);
  }
  if (!LOOPBACK_HOSTS.has(u.hostname)) {
    throw new Error(`${label} host must be loopback, got ${u.hostname}`);
  }
  if (u.protocol !== 'http:') {
    throw new Error(`${label} must be http:// loopback`);
  }
  return u;
}

function requireEnv() {
  const missing = [];
  for (const k of [
    'AGREED_FEE_ISOLATED_BASE_URL',
    'AGREED_FEE_ISOLATED_API_URL',
    'AGREED_FEE_ISOLATED_TOKEN',
    'AGREED_FEE_ISOLATED_COURSE_ID',
    'AGREED_FEE_ISOLATED_INVOICE_ID',
    'AGREED_FEE_ISOLATED_CASE_INV8800_COURSE_ID',
    'AGREED_FEE_ISOLATED_CASE_INV8800_INVOICE_ID',
    'AGREED_FEE_ISOLATED_CASE_REG8800_COURSE_ID',
    'AGREED_FEE_ISOLATED_CASE_REG8800_INVOICE_ID',
    'AGREED_FEE_ISOLATED_CASE_STATE_COURSE_ID',
    'AGREED_FEE_ISOLATED_CASE_STATE_INVOICE_ID',
  ]) {
    if (!process.env[k]) missing.push(k);
  }
  if (missing.length) {
    throw new Error(`agreed-fee isolated env missing: ${missing.join(',')}`);
  }
  const b = assertLoopbackUrl(base, 'BASE');
  const a = assertLoopbackUrl(api, 'API');
  if (b.port && b.port !== '5179') throw new Error(`BASE port must be 5179, got ${b.port}`);
  if (a.port && a.port !== '8000') throw new Error(`API port must be 8000, got ${a.port}`);
  // Precise allowlist — not a lifenet blacklist.
  if (b.origin !== 'http://127.0.0.1:5179') {
    throw new Error(`BASE origin must be http://127.0.0.1:5179, got ${b.origin}`);
  }
  if (a.origin !== 'http://127.0.0.1:8000') {
    throw new Error(`API origin must be http://127.0.0.1:8000, got ${a.origin}`);
  }
}

if (REQUIRE) {
  requireEnv();
}

/**
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string|number} courseId
 */
async function snapshot(request, courseId) {
  const res = await request.get(`${base}/__isolated/fee-snapshot?course_id=${courseId}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.ok(), `snapshot HTTP ${res.status()}`).toBeTruthy();
  return res.json();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string|number} courseId
 * @param {string|number} invoiceId
 */
async function openModal(page, courseId, invoiceId) {
  await page.addInitScript((t) => {
    localStorage.setItem(
      'alltrue_session',
      JSON.stringify({ access_token: t, user: { id: 1, role: 'director', name: 'Fixture' } }),
    );
    localStorage.setItem('app_branch', '1');
  }, token);
  await page.goto(`/agreed-fee-isolated.html?courseId=${courseId}&invoiceId=${invoiceId}`);
  await expect(page.getByTestId('agreed-fee-isolated-root')).toBeVisible();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} charge
 */
async function previewCharge(page, charge) {
  // fee-charge lives under v-if=!preview — caller must fee-back first when plan is visible.
  await expect(page.getByTestId('fee-charge')).toBeVisible();
  await page.getByTestId('fee-charge').fill(String(charge));
  await page.getByTestId('fee-reason').fill('Synthetic agreed fee correction');
  const previewWait = page.waitForResponse(
    (r) =>
      r.request().method() === 'POST' &&
      r.url().includes('/agreed-fee-correction/preview') &&
      r.ok(),
  );
  await page.getByTestId('fee-preview').click();
  const previewRes = await previewWait;
  await expect(page.getByTestId('fee-plan')).toBeVisible();
  return previewRes.json();
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function applyAndWait(page) {
  const applyWait = page.waitForResponse(
    (r) =>
      r.request().method() === 'POST' &&
      r.url().includes('/agreed-fee-correction/apply') &&
      r.status() === 200,
  );
  await page.getByTestId('fee-apply').click();
  const applyRes = await applyWait;
  const applyBody = await applyRes.json();
  await expect(page.getByTestId('agreed-fee-success')).toBeVisible();
  return applyBody;
}

test.describe('agreed-fee isolated full-stack', () => {
  test('trial 8000→500: preview no-write, apply, replay+DB, UI stale via fee-back', async ({
    page,
    request,
  }) => {
    const courseId = process.env.AGREED_FEE_ISOLATED_COURSE_ID;
    const invoiceId = process.env.AGREED_FEE_ISOLATED_INVOICE_ID;
    /** @type {null | Record<string, unknown>} */
    let lastApplyPayload = null;
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('/agreed-fee-correction/apply')) {
        try {
          lastApplyPayload = req.postDataJSON();
        } catch {
          lastApplyPayload = null;
        }
      }
    });

    await openModal(page, courseId, invoiceId);
    const before = await snapshot(request, courseId);
    expect(before.charge).toBe(8000);
    expect(before.rate).toBe(1000);
    expect(before.session_count).toBe(8);
    expect(before.used_sessions).toBe(3);
    expect(before.remaining_sessions).toBe(5);
    expect(before.start_date).toBe('2026-10-01');
    expect(before.end_date).toBe('2026-12-31');
    expect(before.original_invoice.TotalAmount).toBe(7600);
    expect(before.original_invoice.Status).toBe('unpaid');
    expect(before.original_invoice.PaidAmount).toBe(0);
    expect(before.original_invoice.Note).toBe('Original retained note');
    expect(before.original_invoice.IssueDate).toBe('2026-10-01');
    expect(before.original_invoice.DueDate).toBe('2026-10-20');
    expect(before.original_invoice_items).toEqual([
      expect.objectContaining({ Amount: 7600, Description: 'Original retained line' }),
    ]);
    expect(before.replacement_invoice).toBeNull();
    expect(before.correction_count).toBe(0);
    expect(before.payment_count).toBe(0);
    expect(before.payment_report_count).toBe(0);
    expect(before.audit_agreed_fee_count).toBe(0);

    const previewBody = await previewCharge(page, '500');
    expect(previewBody.old_charge).toBe(8000);
    expect(previewBody.new_charge).toBe(500);
    expect(previewBody.original_invoice_total).toBe(7600);
    expect(previewBody.effects.payment).toBe(0);
    expect(previewBody.effects.notification).toBe(0);
    expect(previewBody.preview_token).toBeTruthy();
    assertLoopbackUrl(base, 'BASE');
    expect(new URL(base).origin).toBe('http://127.0.0.1:5179');

    const afterPreview = await snapshot(request, courseId);
    expect(afterPreview.charge).toBe(8000);
    expect(afterPreview.correction_count).toBe(0);
    expect(afterPreview.original_invoice.TotalAmount).toBe(7600);
    expect(afterPreview.original_invoice.Status).toBe('unpaid');
    expect(afterPreview.original_invoice.PaidAmount).toBe(0);
    expect(afterPreview.original_invoice.Note).toBe(before.original_invoice.Note);
    expect(afterPreview.original_invoice.IssueDate).toBe(before.original_invoice.IssueDate);
    expect(afterPreview.original_invoice.DueDate).toBe(before.original_invoice.DueDate);
    expect(afterPreview.original_invoice_items).toEqual(before.original_invoice_items);
    expect(afterPreview.replacement_invoice).toBeNull();
    expect(afterPreview.payment_count).toBe(0);
    expect(afterPreview.payment_report_count).toBe(0);
    expect(afterPreview.notification_count).toBe(before.notification_count);
    expect(afterPreview.audit_agreed_fee_count).toBe(0);

    // UI stale edit: fee-charge is inside v-if=!preview — must fee-back first
    await page.getByTestId('fee-back').click();
    await expect(page.getByTestId('fee-charge')).toBeVisible();
    await page.getByTestId('fee-charge').fill('600');
    await expect(page.getByTestId('fee-plan')).toHaveCount(0);

    const preview2 = await previewCharge(page, '500');
    expect(preview2.new_charge).toBe(500);
    expect(preview2.preview_token).toBeTruthy();

    const applyBody = await applyAndWait(page);
    expect(applyBody.replacement_invoice_id).toBeTruthy();
    expect(applyBody.correction_id || applyBody.new_charge || applyBody.replacement_invoice_id).toBeTruthy();

    const afterApply = await snapshot(request, courseId);
    expect(afterApply.charge).toBe(500);
    expect(afterApply.rate).toBe(1000);
    expect(afterApply.session_count).toBe(8);
    expect(afterApply.used_sessions).toBe(3);
    expect(afterApply.remaining_sessions).toBe(5);
    expect(afterApply.start_date).toBe(before.start_date);
    expect(afterApply.end_date).toBe(before.end_date);
    expect(afterApply.original_invoice.TotalAmount).toBe(7600);
    expect(afterApply.original_invoice.Status).toBe('void');
    expect(afterApply.original_invoice.PaidAmount).toBe(0);
    expect(afterApply.original_invoice.IssueDate).toBe('2026-10-01');
    expect(afterApply.original_invoice.DueDate).toBe('2026-10-20');
    expect(afterApply.original_invoice_items[0].Amount).toBe(7600);
    expect(afterApply.original_invoice_items[0].Description).toBe('Original retained line');
    expect(afterApply.replacement_invoice.id).toBe(applyBody.replacement_invoice_id);
    expect(afterApply.replacement_invoice.TotalAmount).toBe(500);
    expect(afterApply.replacement_invoice.Status).toBe('unpaid');
    expect(afterApply.replacement_invoice.PaidAmount).toBe(0);
    expect(afterApply.replacement_invoice.DueDate).toBe('2026-10-20');
    expect(afterApply.correction_count).toBe(1);
    expect(afterApply.correction_kinds).toContain('agreed_fee');
    expect(afterApply.payment_count).toBe(0);
    expect(afterApply.payment_report_count).toBe(0);
    expect(afterApply.audit_agreed_fee_count).toBe(1);
    expect(afterApply.notification_count).toBe(before.notification_count);

    expect(lastApplyPayload).toBeTruthy();
    const replay = await request.post(
      `${base}/api/v1/student-classes/${courseId}/agreed-fee-correction/apply`,
      {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        data: { ...lastApplyPayload, preview_token: 'expired' },
      },
    );
    expect(replay.status()).toBe(200);
    const replayBody = await replay.json();
    expect(replayBody).toEqual(applyBody);
    const afterReplay = await snapshot(request, courseId);
    expect(afterReplay.charge).toBe(500);
    expect(afterReplay.correction_count).toBe(1);
    expect(afterReplay.correction_ids).toEqual(afterApply.correction_ids);
    expect(afterReplay.replacement_invoice.id).toBe(afterApply.replacement_invoice.id);
    expect(afterReplay.replacement_invoice.TotalAmount).toBe(500);
    expect(afterReplay.replacement_invoice.Status).toBe('unpaid');
    expect(afterReplay.replacement_invoice.PaidAmount).toBe(0);
    expect(afterReplay.original_invoice.Status).toBe('void');
    expect(afterReplay.original_invoice.TotalAmount).toBe(7600);
    expect(afterReplay.payment_count).toBe(0);
    expect(afterReplay.payment_report_count).toBe(0);
    expect(afterReplay.audit_agreed_fee_count).toBe(1);
    expect(afterReplay.notification_count).toBe(before.notification_count);
  });

  test('state_changed: valid preview then bump tracked state → exact 409, no side effects', async ({
    page,
    request,
  }) => {
    const courseId = process.env.AGREED_FEE_ISOLATED_CASE_STATE_COURSE_ID;
    await openModal(page, courseId, process.env.AGREED_FEE_ISOLATED_CASE_STATE_INVOICE_ID);
    const before = await snapshot(request, courseId);
    expect(before.charge).toBe(8000);
    expect(before.correction_count).toBe(0);
    expect(before.remaining_sessions).toBe(5);

    const previewBody = await previewCharge(page, '500');
    expect(previewBody.preview_token).toBeTruthy();
    // Keep the modal's pending idempotency_key — a fresh UUID would 409 on request-hash
    // mismatch, not state drift. After bump, click real fee-apply with that sealed plan.

    const bump = await request.post(`${base}/__isolated/bump-tracked-state`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      data: { course_id: Number(courseId) },
    });
    expect(bump.ok()).toBeTruthy();
    const bumpBody = await bump.json();
    expect(bumpBody.remaining_sessions).toBe(4);

    const applyWait = page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' && r.url().includes('/agreed-fee-correction/apply'),
    );
    await page.getByTestId('fee-apply').click();
    const applyRes = await applyWait;
    expect(applyRes.status()).toBe(409);
    const err = await applyRes.json();
    expect(err.code).toBe('state_changed');
    await expect(page.getByTestId('agreed-fee-success')).toHaveCount(0);

    const after = await snapshot(request, courseId);
    expect(after.charge).toBe(8000);
    expect(after.remaining_sessions).toBe(4);
    expect(after.correction_count).toBe(0);
    expect(after.replacement_invoice).toBeNull();
    expect(after.original_invoice.Status).toBe('unpaid');
    expect(after.original_invoice.TotalAmount).toBe(7600);
    expect(after.original_invoice.PaidAmount).toBe(0);
    expect(after.original_invoice_items).toEqual(before.original_invoice_items);
    expect(after.payment_count).toBe(0);
    expect(after.payment_report_count).toBe(0);
    expect(after.audit_agreed_fee_count).toBe(before.audit_agreed_fee_count);
    expect(after.notification_count).toBe(before.notification_count);
  });

  test('invoice discrepancy 8800→7200 (charge stays 7200, obligation aligned)', async ({
    page,
    request,
  }) => {
    const courseId = process.env.AGREED_FEE_ISOLATED_CASE_INV8800_COURSE_ID;
    const invoiceId = process.env.AGREED_FEE_ISOLATED_CASE_INV8800_INVOICE_ID;
    await openModal(page, courseId, invoiceId);
    const before = await snapshot(request, courseId);
    expect(before.charge).toBe(7200);
    expect(before.original_invoice.TotalAmount).toBe(8800);
    expect(before.original_invoice.Status).toBe('unpaid');
    expect(before.correction_count).toBe(0);

    const previewBody = await previewCharge(page, '7200');
    expect(previewBody.old_charge).toBe(7200);
    expect(previewBody.new_charge).toBe(7200);
    expect(previewBody.original_invoice_total).toBe(8800);
    expect(previewBody.charge_delta).toBe(0);

    const afterPreview = await snapshot(request, courseId);
    expect(afterPreview.charge).toBe(7200);
    expect(afterPreview.original_invoice.TotalAmount).toBe(8800);
    expect(afterPreview.original_invoice.Status).toBe('unpaid');
    expect(afterPreview.correction_count).toBe(0);
    expect(afterPreview.replacement_invoice).toBeNull();

    const applyBody = await applyAndWait(page);

    const after = await snapshot(request, courseId);
    expect(after.charge).toBe(7200);
    expect(after.rate).toBe(1000);
    expect(after.start_date).toBe(before.start_date);
    expect(after.end_date).toBe(before.end_date);
    expect(after.original_invoice.Status).toBe('void');
    expect(after.original_invoice.TotalAmount).toBe(8800);
    expect(after.original_invoice.PaidAmount).toBe(0);
    expect(after.replacement_invoice.TotalAmount).toBe(7200);
    expect(after.replacement_invoice.Status).toBe('unpaid');
    expect(after.replacement_invoice.PaidAmount).toBe(0);
    expect(after.replacement_invoice.id).toBe(applyBody.replacement_invoice_id);
    expect(after.correction_count).toBe(1);
    expect(after.payment_count).toBe(0);
    expect(after.payment_report_count).toBe(0);
    expect(after.audit_agreed_fee_count).toBeGreaterThanOrEqual(1);
  });

  test('regular one_on_one 8800→7200 preserves sessions/rate', async ({ page, request }) => {
    const courseId = process.env.AGREED_FEE_ISOLATED_CASE_REG8800_COURSE_ID;
    const invoiceId = process.env.AGREED_FEE_ISOLATED_CASE_REG8800_INVOICE_ID;
    await openModal(page, courseId, invoiceId);
    const before = await snapshot(request, courseId);
    expect(before.charge).toBe(8800);
    expect(before.rate).toBe(1100);
    expect(before.used_sessions).toBe(5);
    expect(before.remaining_sessions).toBe(3);
    expect(before.class_type).toBe('one_on_one');
    expect(before.original_invoice.TotalAmount).toBe(8800);

    await previewCharge(page, '7200');
    const applyBody = await applyAndWait(page);

    const after = await snapshot(request, courseId);
    expect(after.charge).toBe(7200);
    expect(after.rate).toBe(1100);
    expect(after.used_sessions).toBe(5);
    expect(after.remaining_sessions).toBe(3);
    expect(after.start_date).toBe(before.start_date);
    expect(after.end_date).toBe(before.end_date);
    expect(after.original_invoice.Status).toBe('void');
    expect(after.original_invoice.TotalAmount).toBe(8800);
    expect(after.replacement_invoice.TotalAmount).toBe(7200);
    expect(after.replacement_invoice.Status).toBe('unpaid');
    expect(after.replacement_invoice.PaidAmount).toBe(0);
    expect(after.replacement_invoice.id).toBe(applyBody.replacement_invoice_id);
    expect(after.payment_count).toBe(0);
    expect(after.payment_report_count).toBe(0);
  });
});
