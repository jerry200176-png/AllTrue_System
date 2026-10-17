// @ts-check
/**
 * DRAFT — install path: frontend/e2e/agreed-fee-isolated-fullstack.spec.js
 *
 * Browser (real AgreedFeeCorrectionModal) → same-origin /api (Vite proxy) →
 * Laravel router → AllTrue_test.
 *
 * Asserts DB/API outcomes via GET /__isolated/fee-snapshot (also proxied),
 * not merely request URL counts. Never uses SMOKE_* / production hosts.
 */
import { test, expect } from '@playwright/test';

const base = process.env.AGREED_FEE_ISOLATED_BASE_URL;
const api = process.env.AGREED_FEE_ISOLATED_API_URL || 'http://127.0.0.1:8000';
const token = process.env.AGREED_FEE_ISOLATED_TOKEN;
const courseId = process.env.AGREED_FEE_ISOLATED_COURSE_ID;
const invoiceId = process.env.AGREED_FEE_ISOLATED_INVOICE_ID;

test.skip(!base || !token || !courseId || !invoiceId, 'Isolated full-stack env required');
test.skip(/lifenet\.com\.tw/i.test(String(base)) || /lifenet\.com\.tw/i.test(String(api)), 'Production host forbidden');

/**
 * @param {import('@playwright/test').APIRequestContext} request
 */
async function snapshot(request) {
  // Prefer same-origin via Vite proxy (base) so assertions match modal traffic.
  const res = await request.get(`${base}/__isolated/fee-snapshot?course_id=${courseId}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.ok(), `snapshot HTTP ${res.status()}`).toBeTruthy();
  return res.json();
}

test.describe('agreed-fee isolated full-stack (synthetic 8000→500)', () => {
  test('preview is no-write; apply persists; replay idempotent; stale requires re-preview; no receipt', async ({
    page,
    request,
  }) => {
    const feeResponses = [];
    /** @type {null | Record<string, unknown>} */
    let lastApplyPayload = null;
    page.on('request', (req) => {
      const u = req.url();
      if (req.method() === 'POST' && u.includes('/agreed-fee-correction/apply')) {
        try {
          lastApplyPayload = req.postDataJSON();
        } catch {
          lastApplyPayload = null;
        }
      }
    });
    page.on('response', async (res) => {
      const u = res.url();
      if (!u.includes('/agreed-fee-correction/')) return;
      let body = null;
      try {
        body = await res.json();
      } catch {
        body = null;
      }
      feeResponses.push({
        url: u,
        status: res.status(),
        method: res.request().method(),
        body,
      });
    });

    // Inject token via init script — never put Bearer/token in the navigation URL (trace/log hygiene).
    await page.addInitScript((t) => {
      localStorage.setItem(
        'alltrue_session',
        JSON.stringify({ access_token: t, user: { id: 1, role: 'director', name: 'Fixture' } }),
      );
      localStorage.setItem('app_branch', '1');
    }, token);
    await page.goto(`/agreed-fee-isolated.html?courseId=${courseId}&invoiceId=${invoiceId}`);
    await expect(page.getByTestId('agreed-fee-isolated-root')).toBeVisible();

    const before = await snapshot(request);
    expect(before.charge).toBe(8000);
    expect(before.rate).toBe(1000);
    expect(before.session_count).toBe(8);
    expect(before.used_sessions).toBe(3);
    expect(before.remaining_sessions).toBe(5);
    expect(before.correction_count).toBe(0);
    expect(before.payment_count).toBe(0);
    expect(before.payment_report_count).toBe(0);
    expect(before.effects.payment).toBe(0);
    expect(before.effects.receipt).toBe(0);

    // --- preview (no write) ---
    await page.getByTestId('fee-charge').fill('500');
    await page.getByTestId('fee-reason').fill('Synthetic agreed fee correction');
    await page.getByTestId('fee-preview').click();
    await expect(page.getByTestId('fee-plan')).toBeVisible();

    const previewRes = feeResponses.find(
      (r) => r.method === 'POST' && r.url.includes('/preview') && r.status === 200,
    );
    expect(previewRes, 'preview 200').toBeTruthy();
    expect(previewRes.body.old_charge).toBe(8000);
    expect(previewRes.body.new_charge).toBe(500);
    expect(previewRes.body.original_invoice_total).toBe(7600);
    expect(previewRes.body.effects?.payment).toBe(0);
    // Same-origin via Vite proxy (modal uses relative /api — not window.__AGREED_FEE_API__)
    const baseOrigin = new URL(base).origin;
    expect(new URL(previewRes.url).origin).toBe(baseOrigin);
    expect(previewRes.url).toContain('/api/v1/student-classes/');
    expect(previewRes.url).toContain('/agreed-fee-correction/preview');
    expect(previewRes.url).not.toContain('lifenet.com.tw');

    const afterPreview = await snapshot(request);
    expect(afterPreview.charge).toBe(8000);
    expect(afterPreview.correction_count).toBe(0);
    expect(afterPreview.payment_count).toBe(0);
    expect(afterPreview.open_unpaid_total).toBe(before.open_unpaid_total);

    // --- stale: edit after preview voids signed plan in UI ---
    await page.getByTestId('fee-charge').fill('600');
    await expect(page.getByTestId('fee-plan')).toHaveCount(0);
    await expect(page.getByTestId('fee-preview')).toBeVisible();

    // Re-preview at 500
    await page.getByTestId('fee-charge').fill('500');
    await page.getByTestId('fee-reason').fill('Synthetic agreed fee correction');
    await page.getByTestId('fee-preview').click();
    await expect(page.getByTestId('fee-plan')).toBeVisible();

    // --- apply ---
    await page.getByTestId('fee-apply').click();
    await expect(page.getByTestId('agreed-fee-success')).toBeVisible();

    const applyRes = feeResponses.filter(
      (r) => r.method === 'POST' && r.url.includes('/apply') && r.status === 200,
    );
    expect(applyRes.length).toBeGreaterThanOrEqual(1);
    expect(applyRes[0].body.new_charge ?? applyRes[0].body.correction_id).toBeTruthy();

    const afterApply = await snapshot(request);
    expect(afterApply.charge).toBe(500);
    expect(afterApply.rate).toBe(1000);
    expect(afterApply.session_count).toBe(8);
    expect(afterApply.used_sessions).toBe(3);
    expect(afterApply.remaining_sessions).toBe(5);
    expect(afterApply.correction_count).toBe(1);
    expect(afterApply.correction_kinds).toContain('agreed_fee');
    expect(afterApply.new_charges.map(Number)).toContain(500);
    expect(afterApply.payment_count).toBe(0);
    expect(afterApply.payment_report_count).toBe(0);
    expect(afterApply.effects.payment).toBe(0);
    expect(afterApply.effects.receipt).toBe(0);
    expect(afterApply.open_unpaid_total).toBe(500);

    // --- replay: same idempotency payload (mirrors AgreedFeeCorrectionTest permanent replay) ---
    expect(lastApplyPayload, 'captured apply POST body').toBeTruthy();
    const replay = await request.post(`${base}/api/v1/student-classes/${courseId}/agreed-fee-correction/apply`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      data: { ...lastApplyPayload, preview_token: 'expired' },
    });
    expect(replay.status()).toBe(200);
    const again = await snapshot(request);
    expect(again.charge).toBe(500);
    expect(again.correction_count).toBe(1);
    expect(again.payment_count).toBe(0);
    expect(again.payment_report_count).toBe(0);

    // Stale forged apply must not create a second correction / receipt
    const forged = await request.post(`${base}/api/v1/student-classes/${courseId}/agreed-fee-correction/apply`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      data: {
        scope: 'billed',
        original_invoice_id: Number(invoiceId),
        new_charge: 501,
        reason: 'Synthetic agreed fee correction',
        idempotency_key: crypto.randomUUID(),
        preview_token: 'forged',
      },
    });
    expect(forged.status()).toBeGreaterThanOrEqual(400);
    const finalSnap = await snapshot(request);
    expect(finalSnap.charge).toBe(500);
    expect(finalSnap.correction_count).toBe(1);
    expect(finalSnap.payment_count).toBe(0);
    expect(finalSnap.payment_report_count).toBe(0);
  });
});
