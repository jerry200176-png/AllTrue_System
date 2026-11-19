import { expect } from '@playwright/test';
import { strictYmd } from './classSessionReadResponder.js';

export const SESSION_DATES_PATH = '/api/v1/student-classes/session-dates';
// The GET reads the authorized campus. Give the test room for historical lessons
// beyond the app's four-month default without allowing arbitrary date scans.
const MAX_ACCEPTANCE_DAYS = 366;
const MAX_ACCEPTANCE_WINDOWS = 2;
const DAY_MS = 86_400_000;

function withinAcceptanceSpan(start, end) {
  return Date.parse(end) - Date.parse(start) < MAX_ACCEPTANCE_DAYS * DAY_MS;
}

function inCourseWindow(row, courseId, start, end) {
  return row?.student_class_id === courseId && Boolean(strictYmd(row.session_date))
    && row.session_date >= start && row.session_date <= end;
}

/** The existing GET route serves the same read-only action as the page's POST. */
export async function readCoursePlanningWindow(request, origin, { branchId, courseId, start, end }, token) {
  if (!Number.isSafeInteger(branchId) || branchId <= 0 || !Number.isSafeInteger(courseId) || courseId <= 0
      || !strictYmd(start) || !strictYmd(end) || end < start) throw new Error('invalid course planning read scope');
  if (!withinAcceptanceSpan(start, end)) throw new Error('course planning read exceeds the acceptance date limit');
  const params = new URLSearchParams({ branch_id: String(branchId), range_start: start, range_end: end });
  const response = await request.get(`${new URL(origin).origin}${SESSION_DATES_PATH}?${params}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    maxRedirects: 0,
  });
  if (!response.ok()) throw new Error(`course planning read returned HTTP ${response.status()}`);
  const payload = await response.json();
  const course = payload?.[String(courseId)];
  if (!Array.isArray(course?.materialized) || !Array.isArray(course?.projected)) {
    throw new Error('course planning read did not return both row lists for the selected course');
  }
  const inScope = (row) => inCourseWindow(row, courseId, start, end);
  if (!course.materialized.every(inScope) || !course.projected.every(inScope)) {
    throw new Error('course planning read returned rows outside the selected course or dates');
  }
  return course;
}

/** Answer the selected course's read locally, leaving all other verbs and scopes to the guard. */
export async function installCoursePlanningRead(page, origin, { branchId, courseId, readWindow }) {
  const served = [];
  const limits = [];
  const windows = new Map();
  await page.route((url) => url.origin === origin && url.pathname === SESSION_DATES_PATH, async (route) => {
    const request = route.request();
    const { searchParams } = new URL(request.url());
    if (request.method() !== 'POST' || [...searchParams].length !== 1
        || searchParams.get('branch_id') !== String(branchId)) return route.fallback();

    let body;
    try { body = request.postDataJSON(); } catch { return route.fallback(); }
    if (body?.branch_id !== branchId || !Array.isArray(body.courses)
        || !body.courses.some((course) => course?.id === courseId)) return route.fallback();

    const start = strictYmd(body.range_start);
    const end = strictYmd(body.range_end);
    if (!start || !end || end < start) return route.fallback();
    if (!withinAcceptanceSpan(start, end)) {
      limits.push('date window exceeds 366 days');
      return route.fallback();
    }
    const key = `${start}/${end}`;
    if (!windows.has(key)) {
      if (windows.size >= MAX_ACCEPTANCE_WINDOWS) {
        limits.push('more than two distinct date windows');
        return route.fallback();
      }
      windows.set(key, readWindow({ branchId, courseId, start, end }));
    }
    const { materialized, projected } = await windows.get(key);
    const inScope = (row) => inCourseWindow(row, courseId, start, end);
    const visible = { materialized: materialized.filter(inScope), projected: projected.filter(inScope) };
    await route.fulfill({ json: { [courseId]: visible } });
    served.push(visible.materialized.length);
  });
  return { served, limits };
}

export async function expectAmendedCoursePlanning(page, group, row, { mobile }) {
  const more = row.getByRole('button', { name: /更多課程操作/ });
  const menu = page.getByRole('menu', { name: /更多課程操作/ });
  const manager = row.getByTestId('course-manager-open');
  const planningCaption = '下方日期清單只列已實際排定的堂次';
  let planning;
  if (await manager.isVisible()) {
    await manager.click();
    const drawer = page.getByTestId('course-manager');
    await expect(drawer.getByTestId('course-manager-overview')).toContainText(/剩餘\s*2\s*\/\s*4\s*堂/);
    await drawer.getByRole('tab', { name: '堂次', exact: true }).click();
    await drawer.getByTestId('course-manager-view-list').click();
    const list = drawer.getByTestId('course-manager-session-list');
    await expect(list.getByRole('button', { name: /^(?!(?:調課|代課) )/ })).toHaveCount(2);
    await expect(list.getByText('預排', { exact: true })).toHaveCount(0);
    await expect(drawer.getByTestId('course-manager-schedule-cta')).toBeEnabled();
    await expect(drawer.getByTestId('course-manager-quick-add')).toHaveCount(0);
    planning = drawer.getByTestId('course-manager-sessions').getByText(planningCaption);
  } else {
    await more.click();
    await menu.getByRole('menuitem', { name: '詳情', exact: true }).click();
    await expect(group.getByText(/^上課日期[（(]/)).toContainText(/已上\s*2\s*[／/]\s*購買\s*4\s*堂/);
    planning = group.getByText(planningCaption);
    await more.click();
    await expect(menu.getByRole('menuitem', { name: '排課', exact: true })).toBeEnabled();
    const makeup = menu.getByRole('menuitem', { name: /^補課／補登/ });
    await expect(makeup).toBeVisible();
    await expect(makeup).toBeDisabled();
    await page.keyboard.press('Escape');
  }
  await expect(planning).toBeVisible();
  await expect(planning).toContainText('尚未排滿');
  await expect(planning).toContainText('已排 2／購買 4 堂，尚有 2 堂未安排');
  if (mobile) {
    const clipped = await planning.evaluate((element) => element.scrollWidth > element.clientWidth + 1);
    expect(clipped, 'the planning explanation must wrap on mobile').toBe(false);
  }
}
