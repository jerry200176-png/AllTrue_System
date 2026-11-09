// @ts-check
// In-app #528 / #5145: real SmartCalendar, committed mock API and full projection.
// Synthetic loopback data only; no credentials, production requests or replica DOM.
import { test, expect } from '@playwright/test';

test.use({ viewport: { width: 390, height: 844 }, timezoneId: 'Asia/Taipei', serviceWorkers: 'block' });

async function installCalendar(page, context) {
  await page.clock.install({ time: new Date('2026-11-09T11:00:00Z') });
  await context.route(url => !['127.0.0.1', 'localhost'].includes(url.hostname), route => route.abort());
  const course = {
    id: 99, student_id: 1, student_name: 'Synthetic Move', teacher_id: 5,
    teacher_name: 'Synthetic Teacher', subject: 'math', class_type: 'one_on_one',
    day_of_week: 1, start_time: '20:00', end_time: '22:00', duration_hours: 2,
    first_class_date: '2026-11-02', status: 'active', payment_type: 'monthly', weeks: [1, 2, 3, 4, 5],
  };
  const other = { ...course, id: 100, student_id: 2, student_name: 'Synthetic Other', teacher_id: 6, start_time: '17:00', end_time: '19:00' };
  let session = { id: 7001, student_class_id: 99, student_id: 1, teacher_id: 5,
    session_date: '2026-11-09', start_time: '20:00', end_time: '22:00', status: 'scheduled' };
  const otherSession = { ...session, id: 7002, student_class_id: 100, student_id: 2, teacher_id: 6, start_time: '17:00', end_time: '19:00' };
  const writes = [];
  const projections = [];
  let exceptions = [];
  await page.route('**/api/v1/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const json = body => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    if (request.method() === 'POST' && url.pathname === '/api/v1/learning-records/reschedule-session') {
      const payload = request.postDataJSON();
      writes.push(payload);
      expect(payload).toMatchObject({ student_class_id: 99, old_date: session.session_date,
        old_start_time: session.start_time, ensure_schedule_exception: true });
      const anchor = { id: 8000 + writes.length * 2, student_course_id: 99, student_id: 1, teacher_id: 5,
        schedule_date: session.session_date, start_time: session.start_time, status: 'rescheduled' };
      session = { ...session, session_date: payload.new_date, start_time: payload.start_time, end_time: payload.end_time };
      exceptions = [anchor, { ...anchor, id: anchor.id + 1, original_schedule_id: anchor.id,
        schedule_date: session.session_date, start_time: session.start_time, end_time: session.end_time, status: 'scheduled' }];
      return json({ committed: true, session_id: 7001 });
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())) {
      // Telemetry has no business effect in this local fixture.
      if (url.pathname === '/api/v1/adoption/events') return route.fulfill({ status: 204, body: '' });
      throw new Error(`Unexpected synthetic write: ${request.method()} ${url.pathname}`);
    }
    if (url.pathname === '/api/v1/student-classes') return json({ data: [course, other], last_page: 1, total: 2 });
    if (url.pathname === '/api/v1/schedules') return json({ data: exceptions });
    if (url.pathname === '/api/v1/class-sessions/projection') {
      expect(url.searchParams.get('branch_id')).toBe('1');
      const start = url.searchParams.get('start');
      const end = url.searchParams.get('end');
      const rows = [session, otherSession].filter(row => row.session_date >= start && row.session_date <= end);
      projections.push(rows.map(row => ({ ...row })));
      return json({ api_kind: 'projection', completeness: 'full', data: rows,
        by_class: Object.fromEntries([99, 100].map(id => [id, rows.filter(row => row.student_class_id === id)])) });
    }
    if (url.pathname === '/api/v1/students') return json({ data: [{ id: 1, name: course.student_name }, { id: 2, name: other.student_name }] });
    if (url.pathname === '/api/v1/teachers') return json({ data: [5, 6].map(id => ({ id, name: 'Synthetic Teacher', username: 'Synthetic Teacher', branch_ids: [1] })) });
    if (url.pathname === '/api/v1/subjects') return json({ data: [{ value: 'math', label: 'Math' }] });
    if (url.pathname === '/api/v1/branches') return json([{ id: 1, name: 'Synthetic Campus' }]);
    return json({ data: [], total: 0, last_page: 1 });
  });
  await page.goto('/pilot-mount.html?page=calendar&role=director');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
  await page.getByRole('button', { name: '週檢視', exact: true }).click();
  const card = page.locator('.course-block').filter({ hasText: 'Synthetic Move' });
  await expect(card).toHaveCount(1);
  await expect(card).toHaveAttribute('aria-label', /20:00/);
  return { card, writes, projections };
}

async function reschedule(page, card, date, start) {
  await card.click();
  await page.getByRole('button', { name: '🔄 調課', exact: true }).click();
  await page.locator('#cal-reschedule-date').fill(date);
  await page.locator('#cal-reschedule-start').selectOption(start);
  const completion = page.waitForEvent('dialog');
  const submit = page.getByRole('button', { name: '確認調課', exact: true }).click();
  const dialog = await completion;
  expect(dialog.type()).toBe('alert');
  expect(dialog.message()).toContain('已從');
  expect(dialog.message()).toContain(`改到 ${date}`);
  expect(dialog.message()).toContain(start);
  await dialog.accept();
  await submit;
  await expect(page.locator('#cal-reschedule-date')).toHaveCount(0);
}

test('528: sequential earlier moves replace the old card after committed refresh', async ({ page, context }) => {
  const { card, writes, projections } = await installCalendar(page, context);
  for (const start of ['19:30', '18:00']) {
    const count = writes.length;
    await reschedule(page, card, '2026-11-09', start);
    expect(writes).toHaveLength(count + 1);
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('aria-label', new RegExp(start));
    await expect(card).not.toHaveAttribute('aria-label', /20:00/);
  }
  expect(writes).toHaveLength(2);
  expect(projections.at(-1).find(row => row.id === 7001).start_time).toBe('18:00');
  await expect(page.locator('.course-block').filter({ hasText: 'Synthetic Other' })).toHaveCount(1);
});

test('528: a cross-week move keeps its identity when the destination week is shown', async ({ page, context }) => {
  const { card, writes, projections } = await installCalendar(page, context);
  await reschedule(page, card, '2026-11-16', '19:30');
  expect(projections.at(-1).find(row => row.id === 7001).session_date).toBe('2026-11-16');
  await expect(card).toHaveCount(0);
  await expect(page.locator('.course-block').filter({ hasText: 'Synthetic Other' })).toHaveCount(1);
  await page.getByRole('button', { name: '下一週', exact: true }).click();
  await expect(card).toHaveCount(1);
  await expect(card).toHaveAttribute('aria-label', /19:30/);
  expect(writes).toHaveLength(1);
});
