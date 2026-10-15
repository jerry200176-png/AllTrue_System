// @ts-check
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { expectCoursePaymentEntryOpensLedgerInPlace } from './fixtures/coursePaymentEntry.js';

/**
 * #4130 follow-up regression: the production acceptance checks the 課程查找 payment
 * entry through the shared expectCoursePaymentEntryOpensLedgerInPlace fixture. Run
 * the same fixture against the real App shell (mocked API) for every payment state,
 * so a label or behaviour change fails here instead of on the production gate.
 */
const STATES = ['unpaid', 'partial', 'pending_report', 'paid'];

for (const paymentStatus of STATES) {
  test(`director 課程查找 ${paymentStatus}: payment badge is read-only and its action opens billing in place`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const course = {
      id: 7101, student_id: 7201, student_name: '合成課程學生', teacher_name: '合成老師', TeacherID: 7301,
      subject: '數學', subject_name: '數學', class_type: 'one_on_one', payment_type: 'count', schedule_mode: 'count',
      SessionCount: 8, UsedSessions: 2, RemainingSessions: 6, payment_status: paymentStatus, charge: 4000,
      paid_amount: paymentStatus === 'paid' ? 4000 : 0, has_valid_payment: paymentStatus === 'paid',
      latest_payment_report_id: paymentStatus === 'pending_report' ? 7401 : null,
      days_of_week: [2], start_time: '17:00', end_time: '18:00', status: 'active', branch_id: 1,
    };
    await page.route('**/api/v1/**', async (route) => {
      const url = new URL(route.request().url());
      const p = url.pathname;
      if (route.request().method() !== 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
        return;
      }
      if (p.endsWith('/me')) {
        await route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
        return;
      }
      if (p.endsWith('/branches') || p.endsWith('/campuses')) {
        await route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
        return;
      }
      if (p.includes('/notifications')) {
        await route.fulfill({ json: { data: [], unread_count: 0 } });
        return;
      }
      if (p === '/api/v1/student-classes') {
        await route.fulfill({ json: { data: [course], current_page: 1, last_page: 1, total: 1 } });
        return;
      }
      if (p === '/api/v1/accounting/ledger') {
        await route.fulfill({ json: { student: { id: course.student_id, name: course.student_name }, invoices: [], payments: [], reports: [] } });
        return;
      }
      await route.fulfill({ json: { data: [] } });
    });

    await page.goto('/pilot-mount.html?page=app');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    await page.locator('nav.sidebar-nav').getByRole('button', { name: '課程查找' }).click();
    const heading = page.getByRole('heading', { name: '課程查找', exact: true }).first();
    await expect(heading).toBeVisible({ timeout: 15_000 });

    await expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading: heading });
    await expect(page.locator('#ledger-modal-title')).toHaveText(`${course.student_name}的帳務`);
  });
}
