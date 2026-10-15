// @ts-check
import { test, expect } from '@playwright/test';
import { REPORT_STATUS_LABELS } from '../src/lib/courseMoneyState.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { expectCoursePaymentEntryOpensLedgerInPlace, recordCourseLists } from './fixtures/coursePaymentEntry.js';

/**
 * #4130 follow-up regression: the production acceptance checks the 課程查找 payment
 * entry through the shared expectCoursePaymentEntryOpensLedgerInPlace fixture. Run
 * the same fixture against the real App shell (mocked API) for every payment state,
 * so a label or behaviour change fails here instead of on the production gate.
 *
 * Each case lists the target course next to another student's course with a
 * different action, and the ledger mock answers only for the requested
 * student_class_id, so opening the wrong row's billing fails.
 */
const STATES = [
  { paymentStatus: 'unpaid', actionLabel: '登記繳費回報' },
  { paymentStatus: 'partial', actionLabel: '登記繳費回報' },
  { paymentStatus: 'pending_report', actionLabel: `查看「${REPORT_STATUS_LABELS.pending}」` },
  { paymentStatus: 'paid', actionLabel: '在這裡看帳務' },
];

function course(id, studentId, studentName, paymentStatus) {
  return {
    id, student_id: studentId, student_name: studentName, teacher_name: '合成老師', TeacherID: 7301,
    subject: '數學', subject_name: '數學', class_type: 'one_on_one', payment_type: 'count', schedule_mode: 'count',
    SessionCount: 8, UsedSessions: 2, RemainingSessions: 6, payment_status: paymentStatus, charge: 4000,
    paid_amount: paymentStatus === 'paid' ? 4000 : 0, has_valid_payment: paymentStatus === 'paid',
    latest_payment_report_id: paymentStatus === 'pending_report' ? 7401 : null,
    days_of_week: [2], start_time: '17:00', end_time: '18:00', status: 'active', branch_id: 1,
  };
}

for (const { paymentStatus, actionLabel } of STATES) {
  test(`director 課程查找 ${paymentStatus}: payment badge is read-only and ${actionLabel} opens that course's billing in place`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const target = course(7101, 7201, '合成目標學生', paymentStatus);
    const other = course(7102, 7202, '合成其他學生', paymentStatus === 'paid' ? 'unpaid' : 'paid');
    const ledgerIds = [];
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
        await route.fulfill({ json: { data: [other, target], current_page: 1, last_page: 1, total: 2 } });
        return;
      }
      if (p === '/api/v1/accounting/ledger') {
        const requested = Number(url.searchParams.get('student_class_id'));
        ledgerIds.push(requested);
        const match = [target, other].find((candidate) => candidate.id === requested);
        if (!match) {
          await route.fulfill({ status: 404, json: { message: 'not found' } });
          return;
        }
        await route.fulfill({ json: { student: { id: match.student_id, name: match.student_name }, invoices: [], payments: [], reports: [] } });
        return;
      }
      await route.fulfill({ json: { data: [] } });
    });

    const courses = recordCourseLists(page);
    await page.goto('/pilot-mount.html?page=app');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    await page.locator('nav.sidebar-nav').getByRole('button', { name: '課程查找' }).click();
    const heading = page.getByRole('heading', { name: '課程查找', exact: true }).first();
    await expect(heading).toBeVisible({ timeout: 15_000 });

    await expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading: heading, courses, actionLabel });
    expect(ledgerIds).toEqual([target.id]);
    await expect(page.locator('#ledger-modal-title')).toHaveText(`${target.student_name}的帳務`);
  });
}
