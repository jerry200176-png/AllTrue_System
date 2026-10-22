// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { coursePaymentBadge, REPORT_STATUS_LABELS, TUITION_STATUS_CONFIG } from '../src/lib/courseMoneyState.js';
import { getNavigationGroups, sidebarMoreTrigger } from '../src/lib/navigationRegistry.js';
import { formatCourseRef, formatCoursePeriod } from '../src/lib/studentBillingRows.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import {
  BILLING_ACTION,
  COURSE_PAYMENT_ACTION_NAME,
  expectCoursePaymentEntryOpensLedgerInPlace,
  recordCourseLists,
} from './fixtures/coursePaymentEntry.js';
import { recordLatestGetJson } from './fixtures/latestGetJson.js';
import { openPageFromSidebarSearch } from './fixtures/openPageFromSidebarSearch.js';
import { appOriginOf, installReadOnlyAppGuard } from './fixtures/readOnlyAppGuard.js';

/**
 * Authenticated, read-only acceptance for the calendar/course-management parity
 * contract behind in-app #225/#226/#227.
 *
 * The workflow supplies a short-lived production director session obtained from
 * the existing Pi smoke-token path. No password is stored in this repository.
 */

const BASE = process.env.SMOKE_BASE_URL;
const REQUESTED_BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 0);
const START = process.env.SMOKE_START_DATE || '2026-08-05';
const END = process.env.SMOKE_END_DATE || '2026-08-07';
const CALENDAR_NAV_LABEL = '班級行事曆';
const COURSE_NAV_LABEL = '課程查找';
const CALENDAR_PAGE_TITLE = '班級行事曆 / 課表';
const COURSE_PAGE_TITLE = '課程查找';
const LEARNING_NAV_LABEL = '學習評量';
const LEARNING_PAGE_TITLE = '學習評量表';
// #4102 (in-app 395/403/404) retired these billing labels; no billing view may show them again.
const RETIRED_BILLING_LABELS = ['付款期間待確認', '等你確認收款'];
// Opening 學習評量表 as a director backfills past records (LearningRecordsPage ensurePastRecords).
const LEARNING_ENSURE_PAST_PATH = '/api/v1/learning-records/ensure-past';
// 課程查找 and 學習評量表 ask for session dates with a POST that writes nothing (StudentClassController::sessionDates,
// the same handler as the GET route). Tests count it, still blocked, only when no assertion needs its data.
const SESSION_DATES_POST_READ = '/api/v1/student-classes/session-dates';
const DESKTOP = { name: 'desktop', width: 1440, height: 900 };
const MOBILE = { name: 'mobile', width: 390, height: 844 };
// Keep the returning-user fixture aligned with the same STAFF_UPDATES source
// that drives the production release nudge. A hard-coded version makes this
// read-only acceptance fail as soon as a newer staff update is published.
const CURRENT_STAFF_RELEASE = latestReleaseVersionForRole('director');

function readSession() {
  const encoded = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!encoded) return null;
  try {
    return JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
  } catch {
    return null;
  }
}

const SESSION = readSession();
const AUTHORIZED_CAMPUSES = Array.isArray(SESSION?.user?.campuses)
  ? SESSION.user.campuses.map(Number).filter(Number.isInteger)
  : [];
const BRANCH_ID = REQUESTED_BRANCH_ID || AUTHORIZED_CAMPUSES[0] || 0;

function listFromPayload(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function ymd(value) {
  return String(value || '').slice(0, 10);
}

function hm(value) {
  return String(value || '').slice(0, 5);
}

function valueOf(row, ...keys) {
  for (const key of keys) {
    if (row?.[key] !== undefined && row?.[key] !== null) return row[key];
  }
  return null;
}

function pageHeading(page, label) {
  const name = label.includes('行事曆') ? CALENDAR_PAGE_TITLE
    : label === LEARNING_NAV_LABEL ? LEARNING_PAGE_TITLE : COURSE_PAGE_TITLE;
  return page.getByRole('heading', { name, exact: true }).first();
}

function slotKey(courseId, date, start) {
  return `${String(courseId)}|${ymd(date)}|${hm(start)}`;
}

async function getJson(request, path, token) {
  const response = await request.get(`${BASE}${path}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(response.ok(), `${path} should return 2xx, got ${response.status()}`).toBeTruthy();
  return response.json();
}

async function navigate(page, label) {
  await dismissOverlays(page);
  const isMobile = (page.viewportSize()?.width || 0) <= 640;
  if (isMobile && label === CALENDAR_NAV_LABEL) {
    const bottomNav = page.locator('.mobile-bottom-nav');
    await expect(bottomNav).toBeVisible({ timeout: 15_000 });
    const bottomTab = bottomNav.locator('.mob-tab').filter({ hasText: '行事曆' }).first();
    await bottomTab.waitFor({ state: 'attached', timeout: 5_000 }).catch(() => {});
    if (await bottomTab.count()) {
      await expect(bottomTab).toBeVisible({ timeout: 15_000 });
      await bottomTab.click();
      await expect(bottomTab).toHaveClass(/active/, { timeout: 15_000 });
      return;
    }
    const more = bottomNav.getByRole('button', { name: /更多/ }).first();
    await expect(more).toBeVisible({ timeout: 15_000 });
    await more.click();
    const button = page.locator('.more-sheet.open').getByRole('button', { name: new RegExp(label) }).first();
    await expect(button).toBeVisible({ timeout: 15_000 });
    await button.click();
    await expect(page.locator('.more-sheet.open')).toHaveCount(0, { timeout: 15_000 });
    await expect(pageHeading(page, label)).toBeVisible({ timeout: 15_000 });
    return;
  }
  if (isMobile) {
    const more = page.locator('.mobile-bottom-nav').getByRole('button', { name: /更多/ }).first();
    await expect(more).toBeVisible({ timeout: 15_000 });
    await more.click();
    const button = page.locator('.more-sheet.open').getByRole('button', { name: new RegExp(label) }).first();
    await expect(button).toBeVisible({ timeout: 15_000 });
    await button.click();
    await expect(page.locator('.more-sheet.open')).toHaveCount(0, { timeout: 15_000 });
    await expect(pageHeading(page, label)).toBeVisible({ timeout: 15_000 });
    return;
  }
  const button = page.getByRole('button', { name: label, exact: false }).first();
  await expect(button).toBeVisible({ timeout: 15_000 });
  await button.click();
  await expect(button).toHaveClass(/active/, { timeout: 15_000 });
}

async function navigateSubjectUnits(page) {
  // 科目數統計 is a 報表與薪資 hub tab since #3866; search reaches it without the
  // hub's PIN-gated default tab (#4130).
  await openPageFromSidebarSearch(page, {
    role: SESSION?.user?.role || 'director',
    pageKey: 'subject-units',
    readySelector: '[data-guide="subject-units-header"]',
  });
}

function subjectUnitsPath(start, end, branchId) {
  const params = new URLSearchParams({ start, end, branch_id: String(branchId) });
  return `/api/v1/finance/subject-units/timeline?${params}`;
}

// #4130: each UI switch must be paired with the timeline response for exactly
// the range/campus it asked for. Matching "any 200 timeline response" let a
// wait resolve on a stale response from an earlier edit, and silently ignored a
// 403/5xx for the requested campus, so the wait hung until the 45s test limit
// without naming the step. The listener is attached before the action
// (Promise.all), the wait is bounded, and a non-200 answer fails at once.
const SUBJECT_UNITS_RESPONSE_TIMEOUT_MS = 15_000;

function isSubjectUnitsTimelineFor(url, { start, end, branchId }) {
  const parsed = new URL(url);
  return parsed.pathname.endsWith('/v1/finance/subject-units/timeline')
    && parsed.searchParams.get('start') === start
    && parsed.searchParams.get('end') === end
    && parsed.searchParams.get('branch_id') === String(branchId);
}

async function switchAndAwaitSubjectUnits(page, step, expected, action) {
  const label = `${step} (${expected.start}—${expected.end}, branch ${expected.branchId})`;
  const [response] = await Promise.all([
    page.waitForResponse(
      (candidate) => isSubjectUnitsTimelineFor(candidate.url(), expected),
      { timeout: SUBJECT_UNITS_RESPONSE_TIMEOUT_MS },
    ).catch((error) => {
      throw new Error(`${label}: the page sent no subject-units timeline request for this range/campus `
        + `within ${SUBJECT_UNITS_RESPONSE_TIMEOUT_MS}ms (${error?.name || 'Error'})`);
    }),
    action(),
  ]);
  expect(response.status(), `${label}: subject-units timeline returned HTTP ${response.status()}`).toBe(200);
  return response;
}

function displayedNumber(text) {
  return Number(String(text).replace('%', '').trim());
}

function displayedNumberAtTwoDecimals(value) {
  return Number(Number(value).toFixed(2));
}

function monthEndFor(value) {
  const [year, month] = String(value).slice(0, 7).split('-').map(Number);
  return new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
}

async function assertSubjectUnitsRenderedAgainstApi(page, request, token, start, end, branchId) {
  const payload = await getJson(request, subjectUnitsPath(start, end, branchId), token);
  expect(payload.scope?.role).toBe('director');
  expect(payload.scope?.campus_ids).toContain(Number(branchId));
  expect(payload.teacher_contributions.length, `no teacher row for ${branchId} ${start}—${end}`).toBeGreaterThan(0);

  const rawDenominator = payload.teacher_contributions.reduce(
    (total, teacher) => total + Number(teacher.raw_subject_count || 0),
    0,
  );
  expect(rawDenominator).toBeGreaterThan(0);
  for (const teacher of payload.teacher_contributions) {
    expect(Number(teacher.campus_proportion_pct)).toBeCloseTo(
      (Number(teacher.raw_subject_count) / rawDenominator) * 100,
      4,
    );
  }

  const firstApiTeacher = payload.teacher_contributions[0];
  const row = page.locator('.contribution-table tbody tr').filter({ hasText: firstApiTeacher.teacher_name }).first();
  await expect(row).toBeVisible({ timeout: 15_000 });
  const cells = row.locator('td');
  await expect(cells).toHaveCount(3);
  expect(displayedNumber(await cells.nth(0).innerText())).toBe(displayedNumberAtTwoDecimals(firstApiTeacher.raw_subject_count));
  expect(displayedNumber(await cells.nth(1).innerText())).toBe(displayedNumberAtTwoDecimals(firstApiTeacher.payroll_subject_count));
  expect(displayedNumber(await cells.nth(2).innerText())).toBe(displayedNumberAtTwoDecimals(firstApiTeacher.campus_proportion_pct));

  return { payload, rawDenominator };
}

async function assertResponsive(page, label) {
  const title = pageHeading(page, label);
  await expect(title).toBeVisible({ timeout: 15_000 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  expect(overflow, `${label} has horizontal overflow`).toBeFalsy();
  if ((page.viewportSize()?.width || 0) <= 640) {
    const overlaps = await page.evaluate(() => {
      const guide = document.querySelector('.global-guide-btn')?.getBoundingClientRect();
      const nav = document.querySelector('.mobile-bottom-nav')?.getBoundingClientRect();
      if (!guide || !nav) return false;
      return guide.left < nav.right && guide.right > nav.left && guide.top < nav.bottom && guide.bottom > nav.top;
    });
    expect(overlaps, `${label} guide control overlaps mobile bottom nav`).toBeFalsy();
  }
}

// The controlled account is a returning director: it has acknowledged the
// current staff release and seen the intro, so neither an unrelated release
// prompt nor the first-login animation races the acceptance target, while
// production auth, routing, data loading and responsive layout still run.
async function primeDirectorSession(page) {
  await page.addInitScript(({ session, branch, releaseVersion }) => {
    localStorage.setItem('alltrue_session', JSON.stringify(session));
    localStorage.setItem('app_branch', String(branch));
    localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
    sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
  }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
}

async function openDirectorApp(page, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await primeDirectorSession(page);
  await page.goto('/');
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
}

// Production may lack the case a check needs (no deactivated teacher, no
// binding, ...). The check is then skipped and recorded, so the run reads
// "precondition absent" for it (acceptance-report-gate prints the note)
// instead of reporting it as verified.
function notePreconditionAbsent(name) {
  test.info().annotations.push({ type: 'precondition-absent', description: name });
}

// How many live cases a check compared; a name and a number, never page text.
function noteCoverage(name, count) {
  test.info().annotations.push({ type: 'coverage', description: `${name}=${count}` });
}

async function expectNoRetiredBillingLabels(locator, where) {
  const text = await locator.innerText();
  for (const label of RETIRED_BILLING_LABELS) {
    expect(text.includes(label), `${where} shows the retired label ${label}`).toBe(false);
  }
}

// Enforce the read-only contract in the browser, like the other production
// acceptance specs: no unsafe HTTP method from any page may reach any origin.
// See fixtures/readOnlyAppGuard.js: app adoption POST telemetry is answered
// locally with 204. Every other HTTP write, including third-party telemetry,
// is aborted before its first hop and fails the test in afterEach. Reads on
// every origin pass through. The route is installed on the
// browser context so popup pages are guarded too.
const APP_ORIGIN = appOriginOf(BASE);
let blockedHttpWrites = [];
// POST paths a test expects its page to send on its own. The guard still aborts them before the
// first hop; they are counted instead of failing the test. Every other blocked request still fails.
let expectedAbortedPosts = new Set();

function expectAbortedPost(path) {
  expectedAbortedPosts.add(path);
}

test.describe('production acceptance — calendar/course parity', () => {
  test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
    'missing controlled production director session');

  // Service workers could send requests that bypass context.route.
  test.use({ serviceWorkers: 'block' });

  test.beforeEach(async ({ context }) => {
    blockedHttpWrites = await installReadOnlyAppGuard(context, APP_ORIGIN);
    expectedAbortedPosts = new Set();
  });

  test.afterEach(async () => {
    const expected = blockedHttpWrites.filter((write) => write.method === 'POST' && expectedAbortedPosts.has(write.path));
    if (expected.length > 0) noteCoverage('expected_aborted_posts', expected.length);
    expect(blockedHttpWrites.filter((write) => !expected.includes(write)),
      'read-only acceptance must not send HTTP writes to any origin').toEqual([]);
  });

  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`director ${viewport.name}: calendar and course management agree`, async ({ page, request }) => {
      // Ends on 課程查找, whose session-dates POST would otherwise fail the run only when it fires in time.
      expectAbortedPost(SESSION_DATES_POST_READ);
      await page.setViewportSize({ width: viewport.width, height: viewport.height });

      const token = SESSION.access_token;
      const params = new URLSearchParams({
        branch_id: String(BRANCH_ID),
        start: START,
        end: END,
        per_page: '2000',
      });
      const projectionParams = new URLSearchParams({
        branch_id: String(BRANCH_ID),
        start: START,
        end: END,
      });

      const [coursesPayload, schedulesPayload, projectionPayload] = await Promise.all([
        getJson(request, `/api/v1/student-classes?${params}`, token),
        getJson(request, `/api/v1/schedules?${params}`, token),
        getJson(request, `/api/v1/class-sessions/projection?${projectionParams}`, token),
      ]);

      const courses = listFromPayload(coursesPayload);
      const schedules = listFromPayload(schedulesPayload);
      const sessions = listFromPayload(projectionPayload);
      expect(projectionPayload.api_kind).toBe('projection');
      expect(projectionPayload.completeness).toBe('full');

      const materializedSlots = new Set(
        sessions
          .filter((row) => !['cancelled', 'voided'].includes(String(valueOf(row, 'status', 'Status') || '').toLowerCase()))
          .map((row) => slotKey(
            valueOf(row, 'student_class_id', 'StudentClassID'),
            valueOf(row, 'session_date', 'SessionDate'),
            valueOf(row, 'start_time', 'StartTime'),
          )),
      );

      // This is the exact invariant fixed by the production guard: a scheduled
      // reschedule target with no materialized ClassSession is not a calendar card.
      const orphanTargets = schedules.filter((row) => {
        const status = String(valueOf(row, 'status', 'Status') || '').toLowerCase();
        const courseId = valueOf(row, 'student_course_id', 'StudentClassID');
        const originalId = valueOf(row, 'original_schedule_id', 'original_id');
        if (status !== 'scheduled' || !originalId || !courseId) return false;
        return !materializedSlots.has(slotKey(
          courseId,
          valueOf(row, 'schedule_date', 'session_date', 'SessionDate'),
          valueOf(row, 'start_time', 'StartTime'),
        ));
      });

      await primeDirectorSession(page);

      const pageErrors = [];
      page.on('pageerror', (error) => pageErrors.push(String(error)));
      await page.goto('/');
      await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });

      await navigate(page, CALENDAR_NAV_LABEL);
      await assertResponsive(page, CALENDAR_NAV_LABEL);

      // Force the reported 2026-08-05—08-07 window instead of relying on the
      // runner's current week. The input's change handler triggers the real SPA
      // reload path used by an operator.
      const dateInput = page.locator('input.jump-date-input');
      await expect(dateInput).toBeVisible({ timeout: 15_000 });

      // The orphan rows may remain in the read-only schedules API as historical
      // evidence; they must not become visible cards. Match by student/subject
      // on the exact reported date, while the API invariant above proves the
      // stronger course-id + date + time identity.
      const orphanDates = [...new Set(orphanTargets
        .map((row) => ymd(valueOf(row, 'schedule_date', 'session_date', 'SessionDate')))
        .filter((date) => date >= START && date <= END))];
      for (const targetDate of orphanDates) {
        await dateInput.fill(targetDate);
        await dateInput.dispatchEvent('change');
        await expect(page.locator('.calendar-loading-bar')).toHaveCount(0, { timeout: 20_000 });
        await page.waitForTimeout(250);

        for (const row of orphanTargets.filter((candidate) => (
          ymd(valueOf(candidate, 'schedule_date', 'session_date', 'SessionDate')) === targetDate
        ))) {
          const studentName = String(valueOf(row, 'student_name') || row?.student?.name || '').trim();
          if (!studentName) continue;
          const subject = String(valueOf(row, 'subject') || '').trim();
          const visibleCards = page.locator('.course-block').filter({ hasText: studentName });
          // A student may have another class on the same day; require the exact
          // subject when the API provides it, otherwise retain the API invariant.
          if (subject) {
            const matchingText = await visibleCards.allTextContents();
            expect(matchingText.filter((text) => text.includes(subject)),
              `orphan target ${String(valueOf(row, 'id') || '')} rendered on ${targetDate}`).toHaveLength(0);
          }
        }
      }

      await navigate(page, COURSE_NAV_LABEL);
      await assertResponsive(page, COURSE_NAV_LABEL);
      expect(pageErrors, `production page errors (${viewport.name}):\n${pageErrors.join('\n')}`).toEqual([]);

      await test.info().attach(`consistency-${viewport.name}.json`, {
        body: JSON.stringify({
          branch_id: BRANCH_ID,
          start: START,
          end: END,
          course_count: courses.length,
          schedule_count: schedules.length,
          materialized_session_count: sessions.length,
          orphan_target_count: orphanTargets.length,
          orphan_target_ids: orphanTargets.map((row) => valueOf(row, 'id')).filter(Boolean),
        }, null, 2),
        contentType: 'application/json',
      });
    });
  }

  test('director: 科目數統計的分校占比與 API、分校及期間切換一致', async ({ page, request }) => {
    test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
      'missing controlled production director session');
    await page.setViewportSize({ width: 1440, height: 900 });
    const token = SESSION.access_token;

    await primeDirectorSession(page);
    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await navigateSubjectUnits(page);

    const branchSelect = page.locator('[data-guide="subject-units-header"] select').first();
    await expect(branchSelect).toBeVisible({ timeout: 15_000 });
    const branchIds = await branchSelect.locator('option').evaluateAll((options) => options
      .map((option) => option.value)
      .filter((value) => value !== 'all'));
    expect(branchIds.length, 'director session must expose at least two campuses for the campus-switch check').toBeGreaterThan(1);

    const initialBranch = branchIds.includes(String(BRANCH_ID)) ? String(BRANCH_ID) : branchIds[0];
    // Only switch when the value differs: re-selecting the current campus is
    // not a switch, and its response would be indistinguishable from the next one.
    if (await branchSelect.inputValue() !== initialBranch) {
      await branchSelect.selectOption(initialBranch);
    }
    const periodStart = START;
    const periodEnd = END;
    const dateInputs = page.locator('[data-guide="subject-units-header"] input[type="date"]');
    await expect(dateInputs).toHaveCount(2);
    // fill() on a date input already dispatches input + change, and every change
    // refetches; an extra dispatchEvent('change') only queued a duplicate request.
    await dateInputs.nth(0).fill(periodStart);
    await switchAndAwaitSubjectUnits(page, 'initial period',
      { start: periodStart, end: periodEnd, branchId: initialBranch },
      () => dateInputs.nth(1).fill(periodEnd));
    await expect(page.locator('.contribution-table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    const initial = await assertSubjectUnitsRenderedAgainstApi(page, request, token, periodStart, periodEnd, initialBranch);

    const rawSort = page.getByRole('button', { name: /原始科目數/ }).first();
    await expect(rawSort).toBeVisible();
    await rawSort.click();
    const ascendingRaw = await page.locator('.contribution-table tbody tr td:nth-child(2)').evaluateAll((cells) => cells.map((cell) => Number(cell.textContent.replace(/[^0-9.-]/g, ''))));
    expect(ascendingRaw).toEqual([...ascendingRaw].sort((a, b) => a - b));
    await rawSort.click();
    const descendingRaw = await page.locator('.contribution-table tbody tr td:nth-child(2)').evaluateAll((cells) => cells.map((cell) => Number(cell.textContent.replace(/[^0-9.-]/g, ''))));
    expect(descendingRaw).toEqual([...descendingRaw].sort((a, b) => b - a));

    const switchedBranch = branchIds.find((branchId) => branchId !== initialBranch);
    await switchAndAwaitSubjectUnits(page, 'campus switch',
      { start: periodStart, end: periodEnd, branchId: switchedBranch },
      () => branchSelect.selectOption(switchedBranch));
    await expect(page.locator('.contribution-table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    const switchedCampus = await assertSubjectUnitsRenderedAgainstApi(page, request, token, periodStart, periodEnd, switchedBranch);

    const widerStart = `${periodStart.slice(0, 7)}-01`;
    const widerEnd = monthEndFor(periodStart);
    expect(`${widerStart}..${widerEnd}`, 'period switch must change the range (SMOKE_START_DATE..SMOKE_END_DATE already spans the whole month)')
      .not.toBe(`${periodStart}..${periodEnd}`);
    await dateInputs.nth(0).fill(widerStart);
    await switchAndAwaitSubjectUnits(page, 'period switch',
      { start: widerStart, end: widerEnd, branchId: switchedBranch },
      () => dateInputs.nth(1).fill(widerEnd));
    await expect(page.locator('.contribution-table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    const switchedPeriod = await assertSubjectUnitsRenderedAgainstApi(page, request, token, widerStart, widerEnd, switchedBranch);

    expect(switchedCampus.rawDenominator).not.toBe(initial.rawDenominator);
    expect(switchedPeriod.rawDenominator).not.toBe(switchedCampus.rawDenominator);
  });

  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`director ${viewport.name}: 課程付款狀態與帳務入口分開且可操作`, async ({ page }) => {
    test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
      'missing controlled production director session');
    expectAbortedPost(SESSION_DATES_POST_READ);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await primeDirectorSession(page);
    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    const courses = recordCourseLists(page);
    await navigate(page, COURSE_NAV_LABEL);
    await expect(pageHeading(page, COURSE_NAV_LABEL)).toBeVisible({ timeout: 15_000 });

    // The row's state is unknown here: any action is accepted to find a row, then the
    // helper checks the clicked label against the clicked course's payment state.
    await expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading: pageHeading(page, COURSE_NAV_LABEL), courses });
  });
  }

  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`director ${viewport.name}: contracted four-session detail explains two unarranged sessions`, async ({ page, request }) => {
      test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
        'missing controlled production director session');
      test.skip(BRANCH_ID !== 9, 'the reported unarranged-session course is scoped to campus 9');
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      const token = SESSION.access_token;
      const coursesPayload = await getJson(
        request,
        `/api/v1/student-classes?branch_id=${BRANCH_ID}&per_page=2000`,
        token,
      );
      const matches = listFromPayload(coursesPayload).filter((course) => (
        Number(valueOf(course, 'SessionCount', 'session_count', 'sessions_purchased')) === 4
        && Number(valueOf(course, 'UsedSessions', 'used_sessions')) === 2
        && Number(valueOf(course, 'RemainingSessions', 'remaining_sessions')) === 2
        && String(valueOf(course, 'subject_name', 'subject') || '') === '數學'
        && String(valueOf(course, 'class_type', 'ClassType') || '') === 'one_on_two'
        && String(valueOf(course, 'start_time', 'StartTime') || '').slice(0, 5) === '17:00'
        && String(valueOf(course, 'end_time', 'EndTime') || '').slice(0, 5) === '19:00'
        && String(valueOf(course, 'closed_reason') || '') === 'contract_amended'
      ));
      expect(matches, 'the reported 4 purchased / 2 attended / 2 unarranged course must be unique').toHaveLength(1);
      const target = matches[0];
      const studentName = String(valueOf(target, 'student_name') || target?.student?.name || '').trim();
      const subjectName = String(valueOf(target, 'subject_name', 'subject') || '').trim();
      expect(studentName).not.toBe('');
      expect(subjectName).not.toBe('');

      await primeDirectorSession(page);
      await page.goto('/');
      await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
      await navigate(page, COURSE_NAV_LABEL);

      const studentFilter = page.locator('#course-filter-student');
      await studentFilter.fill(studentName);
      await expect(page.locator('.course-list-skeleton')).toHaveCount(0, { timeout: 20_000 });
      const group = page.locator('.student-group-card').filter({ hasText: studentName }).first();
      await expect(group).toBeVisible({ timeout: 20_000 });
      const toggle = group.locator('.student-group-toggle');
      if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
      const row = group.locator('tr.course-row').filter({ hasText: subjectName }).first();
      await expect(row).toBeVisible({ timeout: 15_000 });
      await row.getByRole('button', { name: '詳情', exact: true }).click();

      const detail = group.locator('.detail-panel').first();
      const planning = detail.locator('.drift-hint-info');
      await expect(planning).toBeVisible({ timeout: 15_000 });
      await expect(planning).toContainText('已排 2／購買 4 堂，尚有 2 堂未安排');
      await expect(planning).toContainText('下方日期清單只列已實際排定的堂次');
      // Keep the count contract independent of typography, while ensuring the
      // selected detail is the reported 2-attended / 4-purchased course.
      await expect(detail.locator('.dates-panel-title')).toContainText(/已上\s*2\s*[／/]\s*購買\s*4\s*堂/);
      if (viewport.name === 'desktop') {
        await expect(row.getByRole('button', { name: '排課', exact: true })).toBeEnabled();
      } else {
        const isClipped = await planning.evaluate((element) => element.scrollWidth > element.clientWidth + 1);
        expect(isClipped, 'the unarranged-session explanation must wrap rather than clip on mobile').toBeFalsy();
        await row.getByRole('button', { name: /更多/ }).click();
        const makeupEntry = group.getByRole('menuitem', { name: /補課 \/ 補登/ });
        await expect(makeupEntry).toBeVisible();
        // This exact report is an already-ended contract. Keep the existing
        // guard: the entry remains discoverable but cannot create a new
        // session after a contract amendment.
        await expect(makeupEntry).toBeDisabled();
      }
    });
  }

  // ── Affected-path evidence for in-app reports whose fix is in production ──
  // Each test asserts what its fix changed. Missing production data is recorded
  // with notePreconditionAbsent and only that assertion is skipped.

  test('director desktop: in-app 390 側欄入口與面板標題都叫「搜尋」', async ({ page }) => {
    await openDirectorApp(page, DESKTOP);
    // #4090: every director group is primary, so the panel holds only global search and is named 搜尋.
    const role = SESSION?.user?.role || 'director';
    expect(sidebarMoreTrigger(getNavigationGroups(role)).label, `${role} sidebar trigger in the shipped registry`).toBe('搜尋');
    await dismissOverlays(page);
    const trigger = page.locator('nav.sidebar-nav .sidebar-more-trigger').first();
    await expect(trigger, 'sidebar search trigger').toBeVisible({ timeout: 15_000 });
    await expect(trigger.locator('.nav-label')).toHaveText('搜尋');
    await expect(trigger).toHaveAttribute('aria-label', '搜尋');
    await expect(trigger).not.toContainText('更多功能');
    await trigger.click();
    const panel = page.locator('.sidebar-more-panel');
    await expect(panel, 'sidebar search panel').toBeVisible({ timeout: 15_000 });
    await expect(panel.locator('#sidebar-more-title')).toHaveText('搜尋');
    await expect(panel.getByRole('button', { name: '關閉搜尋', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(panel, 'search panel closes').toHaveCount(0);
  });

  test('director desktop: in-app 395/403 帳務中心應收分頁用新標籤，不再出現舊字樣', async ({ page }) => {
    test.setTimeout(90_000);
    await openDirectorApp(page, DESKTOP);
    await openPageFromSidebarSearch(page, {
      role: SESSION?.user?.role || 'director',
      pageKey: 'tuition-collect',
      readySelector: '[data-guide="tuition-header"]',
    });
    const center = page.locator('.tc-page');
    // loadAlerts() starts in setup, so 待處理清單 has no count while loading; then it reads
    // 「待處理清單 N 筆」, or is hidden when nothing is pending.
    const queue = page.getByTestId('tc-reminder-receivables');
    const queueState = async () => {
      if (await queue.count() === 0) return 'empty';
      return /\d+\s*筆$/.test((await queue.innerText()).trim()) ? 'listed' : 'loading';
    };
    await expect.poll(queueState, { timeout: 20_000, message: '帳務中心 queue loads' }).not.toBe('loading');
    await expectNoRetiredBillingLabels(center, '帳務中心 學生');

    if (await queueState() === 'listed') {
      await queue.click();
      const tabs = page.locator('#tuition-accounting-panel-receivables .tc-tabs .tc-tab');
      await expect(tabs.first(), '應收 tabs').toBeVisible({ timeout: 15_000 });
      const labels = await tabs.evaluateAll((buttons) => buttons.map((button) => [...button.childNodes]
        .filter((node) => !(node instanceof Element && node.classList.contains('tc-tab-badge')))
        .map((node) => node.textContent).join('').trim()));
      for (const label of [
        `${TUITION_STATUS_CONFIG.unpaid.label}／${TUITION_STATUS_CONFIG.partial.label}`,
        REPORT_STATUS_LABELS.pending,
        TUITION_STATUS_CONFIG.pending_reconciliation.label,
        TUITION_STATUS_CONFIG.amount_review.label,
      ]) expect(labels, `應收 tab ${label}`).toContain(label);
      expect(labels.filter((label) => /等你確認|付款期間待確認/.test(label)), 'no 應收 tab keeps a retired label').toEqual([]);
      await expectNoRetiredBillingLabels(center, '帳務中心 待處理清單');
    } else {
      notePreconditionAbsent('receivables_queue');
    }

    await page.getByTestId('tc-reminder-monthly-review').click();
    const monthly = page.locator('#tuition-accounting-panel-monthly-review .monthly-review');
    await expect(monthly, '月結要核對').toBeVisible({ timeout: 15_000 });
    await expect(monthly.locator('.at-skeleton')).toHaveCount(0, { timeout: 20_000 });
    await expectNoRetiredBillingLabels(center, '帳務中心 月結要核對');

    for (const view of [
      { open: page.locator('#tuition-accounting-tab-payments'), path: '/api/v1/accounting/payments', panel: 'payments', where: '收款紀錄' },
      { open: page.getByTestId('tc-reminder-settled'), path: '/api/v1/accounting/settled-courses', panel: 'settled', where: '已結清' },
    ]) {
      const loaded = page.waitForResponse((response) => new URL(response.url()).pathname === view.path
        && response.request().method() === 'GET', { timeout: 20_000 });
      await view.open.click();
      expect((await loaded).status(), `帳務中心 ${view.where} loads`).toBe(200);
      const panel = page.locator(`#tuition-accounting-panel-${view.panel}`);
      await expect(panel).toBeVisible({ timeout: 15_000 });
      await expect(panel.locator('.tc-skeleton-area')).toHaveCount(0, { timeout: 15_000 });
      await expectNoRetiredBillingLabels(center, `帳務中心 ${view.where}`);
    }
  });

  for (const viewport of [DESKTOP, MOBILE]) {
    test(`director ${viewport.name}: in-app 396 帳務入口開啟的合約卡被標示、捲到眼前並顯示課程編號`, async ({ page }) => {
      expectAbortedPost(SESSION_DATES_POST_READ);
      await openDirectorApp(page, viewport);
      const courses = recordCourseLists(page);
      await navigate(page, COURSE_NAV_LABEL);
      const heading = pageHeading(page, COURSE_NAV_LABEL);
      await expect(heading).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('.course-list-skeleton')).toHaveCount(0, { timeout: 20_000 });
      const actionCells = page.locator('.payment-status-and-action');
      if (await actionCells.filter({ has: page.getByRole('button', { name: COURSE_PAYMENT_ACTION_NAME }) }).count() === 0) {
        notePreconditionAbsent('course_row_payment_action');
        return;
      }
      // Prefer a row whose next action is 在這裡看帳務 so the single-entry rule (#3982) is checked too.
      const actionLabel = await actionCells.getByRole('button', { name: BILLING_ACTION, exact: true }).count() > 0
        ? BILLING_ACTION : undefined;
      if (!actionLabel) notePreconditionAbsent('course_row_billing_action');
      const { ledger, studentClassId, paymentCell } = await expectCoursePaymentEntryOpensLedgerInPlace(page, {
        pageHeading: heading, courses, actionLabel,
      });
      if (actionLabel) {
        const row = paymentCell.locator('xpath=ancestor::tr[contains(concat(" ", normalize-space(@class), " "), " course-row ")][1]');
        await expect(row.getByRole('button', { name: BILLING_ACTION, exact: true }), 'the row offers 在這裡看帳務 once').toHaveCount(1);
      }

      const card = ledger.getByTestId(`contract-card-${studentClassId}`);
      await expect(card, 'the opened course has a contract card').toHaveCount(1);
      await expect(card).toHaveClass(/(^|\s)contract--focused(\s|$)/);
      await expect(ledger.locator('.contract--focused'), 'only the opened course is marked').toHaveCount(1);
      await expect(card.getByTestId('contract-ref')).toHaveText(formatCourseRef(studentClassId));
      await expect(card, 'the opened contract is scrolled into view').toBeInViewport();
      const order = await ledger.locator('[data-testid^="contract-card-"]')
        .evaluateAll((cards) => cards.map((element) => element.getAttribute('data-testid')));
      noteCoverage('contract_cards', order.length);
      // #4120 scrolls only when the opened card is not already first; otherwise "in view" proves less.
      if (order.indexOf(`contract-card-${studentClassId}`) < 1) notePreconditionAbsent('opened_contract_below_first');
      await ledger.getByRole('button', { name: '關閉學生帳務', exact: true }).click();
      await expect(ledger, 'billing panel closes').toHaveCount(0);
    });
  }

  test('director desktop: in-app 404 課程查找帳務資料每份合約顯示期間與付款狀態', async ({ page }) => {
    test.setTimeout(90_000);
    expectAbortedPost(SESSION_DATES_POST_READ);
    await openDirectorApp(page, DESKTOP);
    const courses = recordCourseLists(page);
    await navigate(page, COURSE_NAV_LABEL);
    await expect(pageHeading(page, COURSE_NAV_LABEL)).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.course-list-skeleton')).toHaveCount(0, { timeout: 20_000 });
    const groups = page.locator('.student-group-card');
    const groupCount = Math.min(await groups.count(), 3);
    let checkedRows = 0;
    for (let index = 0; index < groupCount; index += 1) {
      const group = groups.nth(index);
      await group.getByTestId('student-tab-billing').click();
      const billing = group.locator('.student-billing-wrap');
      await expect(billing, '帳務資料').toBeVisible({ timeout: 15_000 });
      await expect(billing.getByText('載入帳務中…')).toHaveCount(0, { timeout: 20_000 });
      await expect(billing.locator('.student-billing-error'), '帳務資料 loads').toHaveCount(0);
      // #4102 relabelled this view as well.
      await expectNoRetiredBillingLabels(billing, '課程查找 帳務資料');
      const rows = billing.locator('tbody tr').filter({ has: page.getByTestId('billing-contract-period') });
      const rowCount = await rows.count();
      for (let rowIndex = 0; rowIndex < rowCount; rowIndex += 1) {
        const row = rows.nth(rowIndex);
        const period = row.getByTestId('billing-contract-period');
        const ref = (await period.locator('div').first().innerText()).trim();
        expect(ref, 'contract reference').toMatch(/^課程 \d{6}$/);
        const id = Number(ref.replace(/\D/g, ''));
        const course = courses().find((candidate) => Number(candidate?.id ?? candidate?.ID) === id);
        expect(course, `course ${id} is in the list 課程查找 loaded`).toBeTruthy();
        await expect(period.locator('.hint'), `course ${id} period`)
          .toHaveText(formatCoursePeriod(course?.first_class_date || course?.StartDate, course?.end_date || course?.EndDate));
        await expect(row.locator('.payment-status-badge[role="status"]'), `course ${id} payment state`)
          .toHaveText(coursePaymentBadge(course ?? {}, { monthlyPeriod: true }).label);
        await expect(row.getByRole('button', { name: BILLING_ACTION, exact: true }), `course ${id} offers 在這裡看帳務 once`).toHaveCount(1);
        checkedRows += 1;
      }
    }
    noteCoverage('billing_rows', checkedRows);
    if (checkedRows === 0) notePreconditionAbsent('billing_rows');
  });

  test('director desktop: in-app 400 家長 LINE 綁定清單與統計卡和 API 一致', async ({ page }) => {
    await openDirectorApp(page, DESKTOP);
    const bindings = recordLatestGetJson(page, '/api/v1/bindings');
    const stats = recordLatestGetJson(page, '/api/v1/bindings/stats');
    // 設定's default tab (老師管理) is PIN-gated; search reaches the 家長 LINE 綁定 tab directly.
    await openPageFromSidebarSearch(page, {
      role: SESSION?.user?.role || 'director',
      pageKey: 'binding-management',
      readySelector: '[data-guide="binding-stats"]',
    });
    await expect.poll(() => Boolean(bindings() && stats()), { timeout: 20_000, message: 'GET /bindings and /bindings/stats' }).toBe(true);
    const metric = (label) => page.locator('[data-guide="binding-stats"] .at-metric')
      .filter({ has: page.locator('.at-metric__label', { hasText: label }) });
    const desktopRows = page.locator('.bmp-desktop-table tbody tr');
    let rowCount = 0;
    // Compare with the latest responses the page received (a campus watch may reload once).
    await expect(async () => {
      const list = bindings()?.body;
      const totals = stats()?.body;
      expect(bindings()?.url.searchParams.get('page'), 'the page asks for the paged list').not.toBeNull();
      expect(Array.isArray(list?.data), 'GET /bindings returns data').toBe(true);
      for (const key of ['bound_count', 'unverified_bound_count', 'student_total']) {
        expect(Number.isFinite(totals?.[key]), `GET /bindings/stats ${key}`).toBe(true);
      }
      const rate = totals.student_total ? `${((totals.bound_count / totals.student_total) * 100).toFixed(1)}%` : '—';
      await expect(metric('已綁定 LINE').locator('.at-metric__value')).toHaveText(String(totals.bound_count), { timeout: 1_000 });
      await expect(metric('綁定率').locator('.at-metric__value')).toHaveText(rate, { timeout: 1_000 });
      await expect(metric('綁定率').locator('.at-metric__delta')).toHaveText(`${totals.bound_count}/${totals.student_total}`, { timeout: 1_000 });
      await expect(metric('未驗證綁定').locator('.at-metric__value')).toHaveText(String(totals.unverified_bound_count), { timeout: 1_000 });
      await expect(metric('總活躍學生').locator('.at-metric__value')).toHaveText(String(totals.student_total), { timeout: 1_000 });
      rowCount = list.data.length;
      await expect(page.getByText(`列表 ${rowCount} 筆`, { exact: true })).toBeVisible({ timeout: 1_000 });
      if (rowCount > 0) {
        await expect(desktopRows).toHaveCount(rowCount, { timeout: 1_000 });
        const verified = list.data.filter((row) => Boolean(row?.verified_at || row?.status === 'verified')).length;
        await expect(desktopRows.filter({ hasText: '已驗證' }), 'verified rows').toHaveCount(verified, { timeout: 1_000 });
      } else {
        await expect(page.getByText('沒有符合條件的綁定', { exact: true })).toBeVisible({ timeout: 1_000 });
      }
    }).toPass({ timeout: 20_000 });
    noteCoverage('binding_rows', rowCount);
    if (rowCount === 0) notePreconditionAbsent('binding_rows');
  });

  test('director desktop: in-app 411 快速排課老師選單排除停用老師', async ({ page }) => {
    await openDirectorApp(page, DESKTOP);
    const teachersResponse = recordLatestGetJson(page, '/api/v1/teachers');
    await navigate(page, CALENDAR_NAV_LABEL);
    await expect(pageHeading(page, CALENDAR_NAV_LABEL)).toBeVisible({ timeout: 15_000 });
    // The calendar's own campus list (useCalendarDataLoad: per_page=all, branch_id, strict_branch=1).
    await expect.poll(() => teachersResponse()?.url.searchParams.get('strict_branch'), {
      timeout: 20_000, message: 'the calendar loaded GET /teachers for its campus',
    }).toBe('1');
    const { url, body } = teachersResponse();
    const branchId = Number(url.searchParams.get('branch_id'));
    const campusTeachers = (Array.isArray(body) ? body : (body?.data || []))
      .filter((teacher) => Number(teacher?.id) > 0)
      .filter((teacher) => (Array.isArray(teacher?.branch_ids) && teacher.branch_ids.map(Number).includes(branchId))
        || Number(teacher?.branch_id || 0) === branchId);
    // Active per the API: status 'active', or no status (legacy rows).
    const isActive = (teacher) => String(teacher?.status ?? 'active') === 'active';
    const assignable = campusTeachers.filter(isActive);
    const deactivated = campusTeachers.filter((teacher) => !isActive(teacher));
    noteCoverage('campus_teachers', campusTeachers.length);
    noteCoverage('deactivated_teachers', deactivated.length);

    const quickAdd = page.locator('[data-guide="calendar-quick-add"]');
    // 快速排課 sits in the 篩選與更多操作 disclosure, collapsed by default.
    if (!(await quickAdd.isVisible())) await page.locator('.calendar-secondary-controls-summary').click();
    await expect(quickAdd, '快速排課').toBeVisible({ timeout: 15_000 });
    await quickAdd.click();
    const scheduler = page.locator('.universal-scheduler-modal');
    await expect(scheduler, '快速排課').toBeVisible({ timeout: 15_000 });
    const picker = scheduler.locator('.form-group')
      .filter({ has: page.locator('label', { hasText: /^老師 \*$/ }) }).locator('.searchable-select');
    await picker.locator('.ss-input').click();
    const options = picker.locator('.ss-dropdown .ss-option');
    await expect(options, 'create picker offers exactly the active teachers').toHaveCount(assignable.length, { timeout: 15_000 });
    if (deactivated.length === 0) {
      notePreconditionAbsent('deactivated_teacher');
    } else {
      // Option text is 「name · campuses」 (UniversalClassScheduler); the name is what buildCreateTeacherOptions shows.
      const shownName = (teacher) => String(teacher.username || teacher.name || teacher.Name || teacher.T_Name
        || teacher.LoginName || `老師#${Number(teacher.id)}`).trim();
      const activeNames = new Set(assignable.map(shownName));
      const deactivatedOnly = new Set(deactivated.map(shownName).filter((name) => !activeNames.has(name)));
      const offered = (await options.allInnerTexts()).map((text) => text.split(' · ')[0].trim());
      expect(offered.filter((name) => deactivatedOnly.has(name)).length, 'deactivated teachers in the create picker').toBe(0);
    }
    // Close the picker, then cancel the scheduler without creating anything.
    await scheduler.locator('.scheduler-header h3').click();
    await expect(picker.locator('.ss-dropdown')).toHaveCount(0);
    await scheduler.locator('.modal-actions').getByRole('button', { name: '取消', exact: true }).click();
    await expect(scheduler, '快速排課 closes').toHaveCount(0);
  });

  for (const viewport of [DESKTOP, MOBILE]) {
    test(`director ${viewport.name}: in-app 417 學習評量表只在桌面提供「列表」切換`, async ({ page }) => {
      // The director page backfills past records on open (a write) and reads session dates by POST.
      // The guard aborts both before they leave the browser; nothing reaches production.
      expectAbortedPost(LEARNING_ENSURE_PAST_PATH);
      expectAbortedPost(SESSION_DATES_POST_READ);
      await openDirectorApp(page, viewport);
      await navigate(page, LEARNING_NAV_LABEL);
      await expect(pageHeading(page, LEARNING_NAV_LABEL)).toBeVisible({ timeout: 15_000 });
      const toggle = page.locator('.lr-view-toggle');
      await expect(toggle, '顯示模式').toBeVisible({ timeout: 15_000 });
      const phone = viewport.width <= 640;
      await expect(toggle.getByRole('button', { name: '卡片', exact: true })).toHaveCount(1);
      await expect(toggle.getByRole('button', { name: '列表', exact: true }), phone ? 'phones offer no no-op 列表' : 'desktop keeps 列表')
        .toHaveCount(phone ? 0 : 1);
      await expect(toggle).toHaveAttribute('aria-label', phone ? '卡片檢視' : '切換列表或卡片');
    });
  }
});
