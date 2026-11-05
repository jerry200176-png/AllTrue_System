// @ts-check
import { recordLatestGetJson } from './latestGetJson.js';
import { expect } from '@playwright/test';
import { REPORT_STATUS_LABELS, coursePaymentBadge } from '../../src/lib/courseMoneyState.js';

const UNPAID_ACTION = '登記收款';
const PENDING_REPORT_ACTION = `查看「${REPORT_STATUS_LABELS.pending}」`;
/** The billing action every course offers; 課程查找 rows show it once (#3982). */
export const BILLING_ACTION = '在這裡看帳務';
/** In-app #490: a director's legacy-paid row (paid flag, no Payment) offers 補登收款 instead. */
const LEGACY_ACTION = '補登收款';


/**
 * The next action a 課程查找 course row must offer for its payment state
 * (CourseManagement.vue paymentNextActionLabel): unpaid/partial → 登記收款,
 * pending report → 查看「待確認入帳」, anything else → 在這裡看帳務; tutoring has none.
 *
 * @param {{ class_type?: string, payment_status?: string }} course
 * @returns {string | null}
 */
export function expectedCoursePaymentActionLabel(course) {
  if (course?.class_type === 'tutoring') return null;
  if (course?.legacy_paid_flag_only === true) return LEGACY_ACTION; // director view (in-app #490)
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
  const latest = recordLatestGetJson(page, '/api/v1/student-classes');
  return () => {
    const json = latest()?.body;
    const list = json?.data ?? json;
    return Array.isArray(list) ? list : (list?.data ?? []);
  };
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
 * @returns {Promise<{ ledger: import('@playwright/test').Locator, studentClassId: number, paymentCell: import('@playwright/test').Locator, sheet: import('@playwright/test').Locator | null }>}
 *   the open billing panel, the course it was opened for, and the clicked row's payment cell
 */
export async function expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading, courses, actionLabel }) {
  // #4659 step 5: the payment action is the row's primary button (登記收款 / 補登收款) or an item in its ⋯ menu.
  const cells = page.locator('.payment-status-and-action');
  await expect(cells.first(), 'a 課程查找 row with a payment status').toBeVisible({ timeout: 15_000 });
  let paymentCell = null;
  let wanted = '';
  for (let i = 0; i < await cells.count(); i += 1) {
    const cell = cells.nth(i);
    const cellCourseId = Number(await cell.getAttribute('data-course-id'));
    const course = courses().find((candidate) => Number(candidate?.id ?? candidate?.ID) === cellCourseId);
    const label = course ? expectedCoursePaymentActionLabel(course) : null;
    if (label && (!actionLabel || label === actionLabel)) { paymentCell = cell; wanted = label; break; }
  }
  expect(paymentCell, 'a 課程查找 row with a payment next action').toBeTruthy();
  const row = paymentCell.locator('xpath=ancestor::tr[contains(concat(" ", normalize-space(@class), " "), " course-row ")][1]');
  const primary = row.getByTestId('course-row-primary');
  const primaryIsPayment = (await primary.getAttribute('data-action')) === 'payment';
  const menuItem = page.getByRole('menuitem', { name: wanted, exact: true });
  if (!primaryIsPayment) await row.locator('.action-menu-trigger').click();
  const action = primaryIsPayment ? primary : menuItem;
  const status = paymentCell.locator('.payment-status-badge');
  await expect(status).toBeVisible({ timeout: 15_000 });
  await expect(status).toHaveAttribute('role', 'status');
  await expect(status).toHaveCSS('cursor', 'default');
  await expect(page.locator('.btn-status')).toHaveCount(0);
  await expect(action).toBeVisible();
  if (!primaryIsPayment) await expect(menuItem, 'the ⋯ menu offers this action once').toHaveCount(1);
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
  // #4659 step 3: 登記收款 / 補登收款 open the entry form in a right-side sheet over the list (no ledger, no page switch).
  const sheetAction = clickedLabel === UNPAID_ACTION || clickedLabel === LEGACY_ACTION;
  const sheetDialog = page.locator('.at-dialog__panel--sheet');
  if (sheetAction) {
    await expect(sheetDialog, 'payment form opens in the side sheet').toBeVisible({ timeout: 15_000 });
    await expect(sheetDialog.locator('.at-dialog__title')).toHaveText(clickedLabel);
    await expect(ledger, 'the ledger is not opened first').toBeHidden();
  } else {
    await expect(ledger, 'student billing panel opens in place').toBeVisible({ timeout: 15_000 });
  }
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
  if (!sheetAction) await expect(ledger.locator('#ledger-modal-title')).toHaveText(`${studentName}的帳務`);
  await expect(pageHeading, 'still on 課程查找 (billing opens in place)').toBeVisible();
  return { ledger, studentClassId: requestedId, paymentCell, sheet: sheetAction ? sheetDialog : null };
}
