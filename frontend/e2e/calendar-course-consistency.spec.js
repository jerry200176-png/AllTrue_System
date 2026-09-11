// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

/**
 * Authenticated, read-only acceptance for the calendar/course-management parity
 * contract behind in-app #225/#226/#227.
 *
 * The workflow supplies a short-lived production director session obtained from
 * the existing Pi smoke-token path. No password is stored in this repository.
 */

const BASE = process.env.SMOKE_BASE_URL;
const BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 16);
const START = process.env.SMOKE_START_DATE || '2026-08-05';
const END = process.env.SMOKE_END_DATE || '2026-08-07';
const CALENDAR_NAV_LABEL = '班級行事曆';
const COURSE_NAV_LABEL = '課程查找';
const SUBJECT_UNITS_NAV_LABEL = '科目數統計';
const CALENDAR_PAGE_TITLE = '班級行事曆 / 課表';
const COURSE_PAGE_TITLE = '課程查找';
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
  const name = label.includes('行事曆') ? CALENDAR_PAGE_TITLE : COURSE_PAGE_TITLE;
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
  if (isMobile && label === COURSE_NAV_LABEL) {
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
  await dismissOverlays(page);
  const sidebar = page.locator('nav.sidebar-nav');
  const direct = sidebar.getByRole('button', { name: SUBJECT_UNITS_NAV_LABEL, exact: false }).first();
  if (await direct.count() && await direct.isVisible().catch(() => false)) {
    await direct.click();
  } else {
    const moreTrigger = sidebar.locator('.sidebar-more-trigger').first();
    await expect(moreTrigger).toBeVisible({ timeout: 15_000 });
    await moreTrigger.click();
    const morePanel = page.locator('.sidebar-more-panel');
    await expect(morePanel).toBeVisible({ timeout: 15_000 });
    await morePanel.getByRole('button', { name: SUBJECT_UNITS_NAV_LABEL, exact: false }).first().click();
  }
  await expect(page.locator('[data-guide="subject-units-header"]')).toBeVisible({ timeout: 15_000 });
}

function subjectUnitsPath(start, end, branchId) {
  const params = new URLSearchParams({ start, end, branch_id: String(branchId) });
  return `/api/v1/finance/subject-units/timeline?${params}`;
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

test.describe('production acceptance — calendar/course parity', () => {
  test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
    'missing controlled production director session');

  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`director ${viewport.name}: calendar and course management agree`, async ({ page, request }) => {
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

      await page.addInitScript(({ session, branch, releaseVersion }) => {
        localStorage.setItem('alltrue_session', JSON.stringify(session));
        localStorage.setItem('app_branch', String(branch));
        // The controlled account is also a returning user who has already
        // acknowledged the current staff release. This keeps an unrelated
        // release prompt from covering the acceptance target.
        localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
        // This is an existing-user state: the intro is already seen. Keeping
        // the acceptance focused on the reported pages avoids the first-login
        // animation racing the sidebar click while still exercising production
        // auth, routing, data loading, and responsive layout.
        sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
      }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });

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

    await page.addInitScript(({ session, branch, releaseVersion }) => {
      localStorage.setItem('alltrue_session', JSON.stringify(session));
      localStorage.setItem('app_branch', String(branch));
      localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
      sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
    }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
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
    await branchSelect.selectOption(initialBranch);
    await page.waitForTimeout(250);
    const periodStart = START;
    const periodEnd = END;
    const dateInputs = page.locator('[data-guide="subject-units-header"] input[type="date"]');
    await expect(dateInputs).toHaveCount(2);
    await dateInputs.nth(0).fill(periodStart);
    const initialPeriodResponse = page.waitForResponse((response) => response.url().includes('/api/v1/finance/subject-units/timeline') && response.status() === 200);
    await dateInputs.nth(1).fill(periodEnd);
    await dateInputs.nth(1).dispatchEvent('change');
    await initialPeriodResponse;
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
    const branchResponse = page.waitForResponse((response) => response.url().includes('/api/v1/finance/subject-units/timeline') && response.status() === 200);
    await branchSelect.selectOption(switchedBranch);
    await branchResponse;
    await expect(page.locator('.contribution-table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    const switchedCampus = await assertSubjectUnitsRenderedAgainstApi(page, request, token, periodStart, periodEnd, switchedBranch);

    const widerStart = `${periodStart.slice(0, 7)}-01`;
    const widerEnd = monthEndFor(periodStart);
    await dateInputs.nth(0).fill(widerStart);
    const periodResponse = page.waitForResponse((response) => response.url().includes('/api/v1/finance/subject-units/timeline') && response.status() === 200);
    await dateInputs.nth(1).fill(widerEnd);
    await dateInputs.nth(1).dispatchEvent('change');
    await periodResponse;
    await expect(page.locator('.contribution-table tbody tr').first()).toBeVisible({ timeout: 15_000 });
    const switchedPeriod = await assertSubjectUnitsRenderedAgainstApi(page, request, token, widerStart, widerEnd, switchedBranch);

    expect(switchedCampus.rawDenominator).not.toBe(initial.rawDenominator);
    expect(switchedPeriod.rawDenominator).not.toBe(switchedCampus.rawDenominator);
  });

  test('director: 課程付款狀態不是按鈕且帳務入口清楚', async ({ page }) => {
    test.skip(!BASE || !SESSION?.access_token || !SESSION?.user?.id,
      'missing controlled production director session');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.addInitScript(({ session, branch, releaseVersion }) => {
      localStorage.setItem('alltrue_session', JSON.stringify(session));
      localStorage.setItem('app_branch', String(branch));
      localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
      sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
    }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await navigate(page, COURSE_NAV_LABEL);
    await expect(pageHeading(page, COURSE_NAV_LABEL)).toBeVisible({ timeout: 15_000 });

    const status = page.locator('.payment-status-badge').first();
    await expect(status).toBeVisible({ timeout: 15_000 });
    await expect(status).toHaveAttribute('role', 'status');
    await expect(status).toHaveCSS('cursor', 'default');
    await expect(page.locator('.btn-status')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /登記繳費回報|查看待對帳|前往帳務中心/, exact: true }).first()).toBeVisible();
  });

  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
  ]) {
    test(`director ${viewport.name}: contracted four-session detail explains two unarranged sessions`, async ({ page, request }) => {
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
      ));
      expect(matches, 'the reported 4 purchased / 2 attended / 2 unarranged course must be unique').toHaveLength(1);
      const target = matches[0];
      const studentName = String(valueOf(target, 'student_name') || target?.student?.name || '').trim();
      const subjectName = String(valueOf(target, 'subject_name', 'subject') || '').trim();
      expect(studentName).not.toBe('');
      expect(subjectName).not.toBe('');

      await page.addInitScript(({ session, branch, releaseVersion }) => {
        localStorage.setItem('alltrue_session', JSON.stringify(session));
        localStorage.setItem('app_branch', String(branch));
        localStorage.setItem('alltrue_release_notes_seen', releaseVersion);
        sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
      }, { session: SESSION, branch: BRANCH_ID, releaseVersion: CURRENT_STAFF_RELEASE });
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
      await expect(detail.locator('.dates-panel-title')).toContainText('已上 2／購買 4 堂');
      await expect(row.getByRole('button', { name: '排課', exact: true })).toBeEnabled();

      if (viewport.name === 'mobile') {
        const isClipped = await planning.evaluate((element) => element.scrollWidth > element.clientWidth + 1);
        expect(isClipped, 'the unarranged-session explanation must wrap rather than clip on mobile').toBeFalsy();
        await row.getByRole('button', { name: /更多/ }).click();
        await expect(group.getByRole('menuitem', { name: /補課 \/ 補登/ })).toBeEnabled();
      }
    });
  }
});
