import { once } from 'node:events';
import { createServer } from 'node:http';
import { expect, test } from '@playwright/test';
import { installLocalClassSessionReads } from './fixtures/classSessionReadResponder.js';
import {
  expectAmendedCoursePlanning, installCoursePlanningRead, readCoursePlanningWindow, SESSION_DATES_PATH,
} from './fixtures/coursePlanningDetails.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { installReadOnlyAppGuard, isKnownStatefulRead } from './fixtures/readOnlyAppGuard.js';

const course = {
  id: 9009, student_id: 9002, student_name: '合成堂數課程學生', teacher_id: 9003, teacher_name: '合成老師',
  branch_id: 1, subject: '數學', subject_name: '數學', class_type: 'one_on_two', Stop: 1, status: 'inactive',
  closed_reason: 'contract_amended', history_reason: null, is_active_course: false, ScheduleMode: 'count',
  payment_type: 'session', payment_status: 'paid', sessions_purchased: 4, sessions_used: 2, remaining_sessions: 2,
  start_time: '17:00', end_time: '19:00', duration_hours: 2, start_date: '2026-08-05', first_class_date: '2026-08-05',
  days_of_week: [3],
};
const sessions = ['2026-08-05', '2026-08-12'].map((session_date, index) => ({
  id: 9100 + index, student_class_id: course.id, student_id: course.student_id, teacher_id: course.teacher_id,
  session_date, start_time: '17:00', end_time: '19:00', status: 'attended',
}));

test.use({ serviceWorkers: 'block' });

for (const width of [390, 1440]) {
  test(`amended course shows two unarranged sessions @${width}`, async ({ page, context, baseURL }) => {
    const origin = new URL(baseURL).origin;
    expect(new URL(origin).hostname).toBe('127.0.0.1');
    await page.setViewportSize({ width, height: 900 });
    const blocked = await installReadOnlyAppGuard(context, origin);
    await context.route((url) => url.origin !== origin, (route) => route.abort('blockedbyclient'));
    await installLocalClassSessionReads(context, origin);
    await context.route((url) => url.origin === origin && url.pathname.startsWith('/api/v1/')
        && !isKnownStatefulRead(origin, 'GET', url), async (route) => {
      const request = route.request();
      if (request.method() !== 'GET') return route.fallback();
      const url = new URL(request.url());
      const data = url.pathname === '/api/v1/student-classes' && url.searchParams.get('branch_id') === '1' ? [course] : [];
      await route.fulfill({ json: { data, total: data.length, current_page: 1, last_page: 1 } });
    });
    const { served, limits } = await installCoursePlanningRead(page, origin, {
      branchId: 1, courseId: course.id, readWindow: () => ({ materialized: sessions, projected: [] }),
    });

    await page.goto('/pilot-mount.html?page=course&mode=normal');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    const group = page.locator('.student-group-card').filter({ hasText: course.student_name });
    const toggle = group.locator('.student-group-toggle');
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
    const row = group.locator(`.course-row[data-contract-id="${course.id}"]`);
    await expect(row).toBeVisible();
    await expectAmendedCoursePlanning(page, group, row, { mobile: width < 640 });

    expect(served).toContain(2);
    expect(limits).toEqual([]);
    expect(blocked).toEqual([]);
  });
}

test('course details serve only the selected local course data', async ({ page, context, baseURL }) => {
  const origin = new URL(baseURL).origin;
  expect(new URL(origin).hostname).toBe('127.0.0.1');
  const blocked = await installReadOnlyAppGuard(context, origin);
  const sourceWindows = [];
  const { served, limits } = await installCoursePlanningRead(page, origin, {
    branchId: 1, courseId: course.id,
    readWindow: (window) => {
      sourceWindows.push(window);
      return { materialized: [...sessions, { ...sessions[1], student_class_id: 42 }], projected: [] };
    },
  });
  const mount = origin + '/course-planning-test';
  await page.route(mount, (route) => route.fulfill({ contentType: 'text/html', body: '<title>Course planning</title>' }));
  await page.goto(mount);

  const result = await page.evaluate(async ({ path, courseId }) => {
    const body = { branch_id: 1, courses: [{ id: courseId }], range_start: '2026-08-10', range_end: '2026-08-31' };
    const read = async (query, input = body, method = 'POST') => {
      try {
        const response = await fetch(path + query, {
          method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
        });
        return await response.json();
      } catch { return null; }
    };
    return {
      selected: await read('?branch_id=1'),
      repeated: await read('?branch_id=1'),
      secondWindow: await read('?branch_id=1', { ...body, range_end: '2026-08-30' }),
      denied: await Promise.all([
        read('?branch_id=2'),
        read('?branch_id=1&branch_id=2'),
        read('?branch_id=1', { ...body, branch_id: 2 }),
        read('?branch_id=1', { ...body, courses: [{ id: courseId + 1 }] }),
        read('?branch_id=1', { ...body, range_end: '2026-08-01' }),
        read('?branch_id=1', { ...body, range_start: '0001-01-01', range_end: '9999-12-31' }),
        read('?branch_id=1', { ...body, range_end: '2026-08-29' }),
        read('?branch_id=1', body, 'PUT'),
      ]),
    };
  }, { path: SESSION_DATES_PATH, courseId: course.id });

  expect(result.selected).toEqual({ [course.id]: { materialized: [sessions[1]], projected: [] } });
  expect(result.repeated).toEqual(result.selected);
  expect(result.secondWindow).toEqual(result.selected);
  expect(result.denied).toEqual(Array(8).fill(null));
  expect(served).toEqual([1, 1, 1]);
  expect(limits.sort()).toEqual(['date window exceeds 366 days', 'more than two distinct date windows']);
  expect(sourceWindows).toEqual([
    { branchId: 1, courseId: course.id, start: '2026-08-10', end: '2026-08-31' },
    { branchId: 1, courseId: course.id, start: '2026-08-10', end: '2026-08-30' },
  ]);
  expect(blocked.every((entry) => entry.origin === origin && entry.path === SESSION_DATES_PATH)).toBe(true);
  expect(blocked.map((entry) => entry.method).sort()).toEqual([...Array(7).fill('POST'), 'PUT']);
});

test('course planning reads use a canonical GET and decline redirects', async ({ request }) => {
  const received = [];
  const payload = { [course.id]: { materialized: sessions, projected: [] }, 42: { materialized: [], projected: [] } };
  const server = createServer((req, res) => {
    received.push([req.method, req.url]);
    if (req.url.includes('range_start=2026-08-02')) {
      res.writeHead(307, { Location: '/other' }).end();
      return;
    }
    const json = req.url.includes('range_start=2026-08-03')
      ? { [course.id]: { materialized: [], projected: [{ ...sessions[1], student_class_id: 42 }] } }
      : payload;
    res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(json));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  try {
    const origin = `http://127.0.0.1:${server.address().port}`;
    const window = { branchId: 1, courseId: course.id, start: '2026-08-01', end: '2026-08-31' };
    const read = (overrides = {}) => readCoursePlanningWindow(request, origin, { ...window, ...overrides }, 'synthetic-session');
    await expect(read()).resolves.toEqual(payload[course.id]);
    await expect(read({ branchId: 0 })).rejects.toThrow('invalid course planning read scope');
    await expect(read({ start: '0001-01-01', end: '9999-12-31' })).rejects.toThrow('exceeds the acceptance date limit');
    await expect(read({ start: '2026-08-02' })).rejects.toThrow('course planning read returned HTTP 307');
    await expect(read({ start: '2026-08-03' })).rejects.toThrow('rows outside the selected course or dates');
    expect(received).toEqual([
      ['GET', `${SESSION_DATES_PATH}?branch_id=1&range_start=2026-08-01&range_end=2026-08-31`],
      ['GET', `${SESSION_DATES_PATH}?branch_id=1&range_start=2026-08-02&range_end=2026-08-31`],
      ['GET', `${SESSION_DATES_PATH}?branch_id=1&range_start=2026-08-03&range_end=2026-08-31`],
    ]);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});
