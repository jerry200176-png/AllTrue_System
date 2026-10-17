// @ts-check
/**
 * DRAFT — proposed path: frontend/e2e/agreed-fee-isolated-fullstack.spec.js
 *
 * Browser → real Laravel API → AllTrue_test.
 * Skips unless AGREED_FEE_ISOLATED_* env is set. Never page.route-mocks fee APIs.
 * Does not use SMOKE_* / production.
 */
import { test, expect } from '@playwright/test';

const base = process.env.AGREED_FEE_ISOLATED_BASE_URL;
const api = process.env.AGREED_FEE_ISOLATED_API_URL;
const token = process.env.AGREED_FEE_ISOLATED_TOKEN;
const courseId = process.env.AGREED_FEE_ISOLATED_COURSE_ID;
const invoiceId = process.env.AGREED_FEE_ISOLATED_INVOICE_ID;

test.skip(!base || !api || !token || !courseId || !invoiceId, 'Isolated full-stack env required');

test.describe('agreed-fee isolated full-stack (synthetic)', () => {
  test('preview then apply 8000→500 against real API (no receipt side effects)', async ({ page }) => {
    const feeCalls = [];
    page.on('request', (req) => {
      const u = req.url();
      if (u.includes('/agreed-fee-correction/')) {
        feeCalls.push({ method: req.method(), url: u });
      }
    });
    // Refuse accidental production hosts
    test.skip(/lifenet\.com\.tw/.test(api), 'Production host forbidden');

    await page.addInitScript(
      ({ token: t, apiUrl }) => {
        localStorage.setItem(
          'alltrue_session',
          JSON.stringify({
            access_token: t,
            user: { id: 1, role: 'director', name: 'Isolated Director' },
          }),
        );
        window.__AGREED_FEE_API__ = apiUrl;
      },
      { token, apiUrl: api },
    );

    await page.goto('/agreed-fee-isolated.html');
    await expect(page.getByTestId('agreed-fee-isolated-root')).toBeVisible();

    // Modal fields — selectors must match AgreedFeeCorrectionModal data-testid once wired.
    await page.getByTestId('agreed-fee-new-charge').fill('500');
    await page.getByTestId('agreed-fee-reason').fill('Synthetic agreed fee correction');
    await page.getByTestId('agreed-fee-preview').click();
    await expect(page.getByTestId('agreed-fee-preview-summary')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('agreed-fee-apply').click();
    await expect(page.getByTestId('agreed-fee-success')).toBeVisible({ timeout: 15_000 });

    expect(feeCalls.some((c) => c.url.endsWith('/preview') && c.method === 'POST')).toBeTruthy();
    expect(feeCalls.some((c) => c.url.endsWith('/apply') && c.method === 'POST')).toBeTruthy();
    expect(feeCalls.every((c) => c.url.startsWith(api))).toBeTruthy();
  });
});
