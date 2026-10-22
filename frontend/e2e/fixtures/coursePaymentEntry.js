// @ts-check
import { expect } from '@playwright/test';
import { REPORT_STATUS_LABELS, coursePaymentBadge } from '../../src/lib/courseMoneyState.js';

const UNPAID_ACTION = '登記繳費回報';
const PENDING_REPORT_ACTION = `查看「${REPORT_STATUS_LABELS.pending}」`;
const BILLING_ACTION = '在這裡看帳務';

/** Any 課程查找 payment next action (used only to find a row whose state is not known yet). */
export const COURSE_PAYMENT_ACTION_NAME = new RegExp(`^(?:${UNPAID_ACTION}|${PENDING_REPORT_ACTION}|${BILLING_ACTION})$`);

/**
 * The next action a 課程查找 course row must offer for its payment state
 * (CourseManagement.vue paymentNextActionLabel): unpaid/partial → 登記繳費回報,
 * pending report → 查看「待核對入帳」, anything else → 在這裡看帳務; tutoring has none.
 *
 * @param {{ class_type?: string, payment_status?: string }} course
 * @returns {string | null}
 */
export function expectedCoursePaymentActionLabel(course) {
  if (course?.class_type === 'tutoring') return null;
  if (['unpaid', 'partial'].includes(String(course?.payment_status))) return UNPAID_ACTION;
  if (course?.payment_status === 'pending_report') return PENDING_REPORT_ACTION;
  return BILLING_ACTION;
}

/**
 * Record every course list the 課程查找 page loads, so the clicked row can be
 * resolved to its course. Call before navigating to the page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {() => Array<Record<string, any>>} the most recently loaded list
 */
export function recordCourseLists(page) {
  /** @type {Array<Record<string, any>>} */
  let latest = [];
  page.on('response', async (response) => {
    const url = new URL(response.url());
    if (url.pathname !== '/api/v1/student-classes' || response.request().method() !== 'GET' || !response.ok()) return;
    try {
      const json = await response.json();
      const list = json?.data ?? json;
      latest = Array.isArray(list) ? list : (list?.data ?? []);
    } catch { /* body unavailable after navigation */ }
  });
  return () => latest;
}

/**
 * On 課程查找, the payment status is a read-only badge and its next action opens
 * the clicked course's billing panel in place (課程查找 C-PR5 #3843, #3858). It
 * no longer navigates to 帳務中心.
 *
 * #4130 follow-up: the acceptance still expected a 前往帳務中心 button and a
 * 帳務中心 heading after the click, so it failed on every build since #3858.
 *
 * The clicked row carries its course id (data-course-id, #4186). The ledger request's
 * student_class_id must be that id, so "click course A opens course B of the same
 * student" fails. The course must be in the list the page loaded, belong to the
 * student group the row sits in, show the badge text and action label its payment
 * state requires (courseMoneyState.js), and the panel title must name that student.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{
 *   pageHeading: import('@playwright/test').Locator,
 *   courses: () => Array<Record<string, any>>,
 *   actionLabel?: string,
 * }} options `actionLabel` pins the exact action to click when the row's state is known.
 */
export async function expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading, courses, actionLabel }) {
  const actionName = actionLabel ? new RegExp(`^${actionLabel}$`) : COURSE_PAYMENT_ACTION_NAME;
  const paymentCell = page.locator('.payment-status-and-action').filter({
    has: page.getByRole('button', { name: actionName }),
  }).first();
  await expect(paymentCell, 'a 課程查找 row with a payment next action').toBeVisible({ timeout: 15_000 });
  const status = paymentCell.locator('.payment-status-badge');
  const action = paymentCell.getByRole('button', { name: actionName });
  await expect(status).toBeVisible({ timeout: 15_000 });
  await expect(status).toHaveAttribute('role', 'status');
  await expect(status).toHaveCSS('cursor', 'default');
  await expect(page.locator('.btn-status')).toHaveCount(0);
  await expect(action).toBeVisible();
  const clickedLabel = (await action.innerText()).trim();
  const rowCourseId = Number(await paymentCell.getAttribute('data-course-id'));
  expect(rowCourseId, 'the payment cell names its course (data-course-id)').toBeGreaterThan(0);
  const rowCourse = courses().find((candidate) => Number(candidate?.id ?? candidate?.ID) === rowCourseId);
  expect(rowCourse, `row course ${rowCourseId} must be a course listed on 課程查找`).toBeTruthy();
  await expect(status, `badge for course ${rowCourseId} matches its payment state`).toHaveText(coursePaymentBadge(rowCourse ?? {}, { monthlyPeriod: true }).label);
  const rowStudent = (await paymentCell
    .locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " student-group-card ")][1]')
    .locator('.cell-student').first().innerText()).trim();

  const ledgerResponse = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/api/v1/accounting/ledger' && response.request().method() === 'GET',
    { timeout: 15_000 },
  );
  await action.click();
  const ledger = page.getByRole('dialog').filter({ has: page.locator('#ledger-modal-title') });
  await expect(ledger, 'student billing panel opens in place').toBeVisible({ timeout: 15_000 });
  const response = await ledgerResponse;
  expect(response.status(), 'student billing panel ledger request').toBe(200);

  const requestedId = Number(new URL(response.url()).searchParams.get('student_class_id'));
  expect(requestedId, 'ledger request names the clicked course (student_class_id)').toBe(rowCourseId);
  const course = courses().find((candidate) => Number(candidate?.id ?? candidate?.ID) === requestedId);
  expect(course, `ledger course ${requestedId} must be a course listed on 課程查找`).toBeTruthy();
  const studentName = String(course?.student_name ?? '').trim();
  expect(studentName, `course ${requestedId} has a student name`).not.toBe('');
  expect(studentName, 'ledger opened for a course of the clicked row\'s student').toBe(rowStudent);
  expect(clickedLabel, `action for course ${requestedId} matches its payment state`).toBe(expectedCoursePaymentActionLabel(course ?? {}));
  await expect(ledger.locator('#ledger-modal-title')).toHaveText(`${studentName}的帳務`);
  await expect(pageHeading, 'still on 課程查找 (billing opens in place)').toBeVisible();
}
