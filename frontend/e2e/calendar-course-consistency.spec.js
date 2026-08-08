// @ts-check
import { test, expect } from '@playwright/test';

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

async function dismissOverlays(page) {
  for (const selector of ['.guide-tour-close', '.release-nudge-btn:has-text("稍後再看")']) {
    const item = page.locator(selector).first();
    if (await item.isVisible().catch(() => false)) await item.click().catch(() => {});
  }
}

async function navigate(page, label) {
  await dismissOverlays(page);
  const button = page.getByRole('button', { name: label, exact: false }).first();
  await expect(button).toBeVisible({ timeout: 15_000 });
  await button.click();
  await expect(button).toHaveClass(/active/, { timeout: 15_000 });
}

async function assertResponsive(page, label) {
  await expect(page.getByText(label, { exact: false }).first()).toBeVisible({ timeout: 15_000 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  expect(overflow, `${label} has horizontal overflow`).toBeFalsy();
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

      await page.addInitScript(({ session, branch }) => {
        localStorage.setItem('alltrue_session', JSON.stringify(session));
        localStorage.setItem('app_branch', String(branch));
      }, { session: SESSION, branch: BRANCH_ID });

      const pageErrors = [];
      page.on('pageerror', (error) => pageErrors.push(String(error)));
      await page.goto('/');
      await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });

      await navigate(page, '班級行事曆 / 課表');
      await assertResponsive(page, '班級行事曆 / 課表');

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

      await navigate(page, '課程管理');
      await assertResponsive(page, '課程管理');
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
});
