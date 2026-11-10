import { createHash } from 'node:crypto';
import { isKnownStatefulRead } from './readOnlyAppGuard.js';

const PREFIX = '/api/v1/';
const RESCHEDULE = PREFIX + 'learning-records/reschedule-session';
const PATHS = new Set(['student-classes', 'student-classes/session-dates', 'schedules', 'class-sessions',
  'class-sessions/projection', 'students', 'teachers', 'subjects', 'rooms', 'learning-records'].map(path => PREFIX + path));
const positive = value => Number.isSafeInteger(value) && value > 0;

/** Exact browser-local calendar fixtures; auth, me and campus authorization stay real. */
export async function installDirectorCalendarMocks(context, origin) {
  const counts = { projection: 0, materializer: 0, reschedule: 0, rejected: 0 };
  let branch = 0;
  let session = { id: 7001, student_class_id: 99, student_id: 1, teacher_id: 5,
    session_date: '2026-11-09', start_time: '20:00', end_time: '22:00', status: 'scheduled' };
  const otherSession = { ...session, id: 7002, student_class_id: 100, student_id: 2, teacher_id: 6,
    start_time: '17:00', end_time: '19:00' };
  let exceptions = [];
  const course = { id: 99, student_id: 1, student_name: 'Synthetic Move', teacher_id: 5,
    teacher_name: 'Synthetic Teacher', subject: 'Math', class_type: 'one_on_one', day_of_week: 1,
    start_time: '20:00', end_time: '22:00', duration_hours: 2, first_class_date: '2026-11-02',
    status: 'active', payment_type: 'monthly', weeks: [1, 2, 3, 4, 5] };
  const other = { ...course, id: 100, student_id: 2, student_name: 'Synthetic Other', teacher_id: 6,
    start_time: '17:00', end_time: '19:00' };
  const matches = value => {
    const url = new URL(String(value));
    return url.origin === origin && (PATHS.has(url.pathname) || url.pathname === RESCHEDULE
      || isKnownStatefulRead(origin, 'GET', url));
  };
  const handler = async route => {
    const request = route.request();
    const url = new URL(request.url());
    const method = request.method();
    const reject = async () => { counts.rejected += 1; await route.abort('blockedbyclient'); };
    const json = body => route.fulfill({ status: 200, contentType: 'application/json',
      body: method === 'HEAD' ? '' : JSON.stringify(body) });
    if (url.pathname === RESCHEDULE) {
      let body; try { body = request.postDataJSON(); } catch { return reject(); }
      if (method !== 'POST' || counts.reschedule || !branch
        || body?.student_class_id !== 99 || body.old_date !== session.session_date
        || body.old_start_time !== session.start_time || body.new_date !== '2026-11-09'
        || body.start_time !== '19:30' || body.end_time !== '21:30'
        || body.ensure_schedule_exception !== true) return reject();
      counts.reschedule += 1;
      const anchor = { id: 8001, student_course_id: 99, student_id: 1, teacher_id: 5,
        schedule_date: session.session_date, start_time: session.start_time, status: 'rescheduled' };
      session = { ...session, start_time: body.start_time, end_time: body.end_time };
      exceptions = [anchor, { ...anchor, id: 8002, original_schedule_id: anchor.id,
        start_time: session.start_time, end_time: session.end_time, status: 'scheduled' }];
      return json({ committed: true, session_id: 7001 });
    }
    if (!['GET', 'HEAD'].includes(method) || !PATHS.has(url.pathname)) return reject();
    const requestedBranch = Number(url.searchParams.get('branch_id') || url.searchParams.get('campus_id'));
    if (positive(requestedBranch)) {
      if (branch && branch !== requestedBranch) return reject();
      branch = requestedBranch; // Existing authenticated App chooses its own accessible campus.
    }
    if (url.pathname.endsWith('/class-sessions') || url.pathname.endsWith('/projection')) {
      counts.materializer += 1;
      const start = url.searchParams.get('start');
      const end = url.searchParams.get('end');
      const rows = [session, otherSession].filter(row => (!start || row.session_date >= start) && (!end || row.session_date <= end));
      const by_class = Object.fromEntries([99, 100].map(id => [id, rows.filter(row => row.student_class_id === id)]));
      if (url.pathname.endsWith('/projection')) {
        counts.projection += 1;
        return json({ api_kind: 'projection', completeness: 'full', data: rows, by_class });
      }
      return json({ data: rows, by_class, total: rows.length, current_page: 1, last_page: 1 });
    }
    if (url.pathname === PREFIX + 'student-classes') return json({ data: [course, other], last_page: 1, total: 2 });
    if (url.pathname === PREFIX + 'schedules') return json({ data: exceptions });
    if (url.pathname === PREFIX + 'students') return json({ data: [{ id: 1, name: course.student_name }, { id: 2, name: other.student_name }] });
    if (url.pathname === PREFIX + 'teachers') return json({ data: [5, 6].map(id => ({ id, name: 'Synthetic Teacher',
      username: 'Synthetic Teacher', branch_ids: branch ? [branch] : [] })) });
    if (url.pathname === PREFIX + 'subjects') return json([{ id: 1, name: '數學', campus_id: branch || null }]);
    return json({ data: [], total: 0, last_page: 1 });
  };
  // Reinstall after the unchanged global guard is armed: exact local fulfill
  // rules take precedence; no matched request can fetch/continue/fallback.
  const install = () => context.route(matches, handler);
  await install();
  return { install, counts, branchBound: () => positive(branch) };
}

/** Public release identity only; test-only harness SHA is deliberately separate. */
export async function readDirectorRuntimeIdentity(context, origin) {
  const values = [];
  for (const path of ['/version.json', '/deployment.json']) {
    const response = await context.request.get(origin + path, { maxRedirects: 0, maxRetries: 0, timeout: 15_000 });
    try {
      if (response.status() !== 200 || response.url() !== origin + path
        || !response.headers()['content-type']?.includes('application/json')) throw new Error('Invalid public identity.');
      const bytes = await response.body();
      if (bytes.length > 64 * 1024) throw new Error('Public identity exceeds bound.');
      values.push(JSON.parse(bytes.toString('utf8')));
    } finally { await response.dispose(); }
  }
  const sha = values[0].build_sha;
  if (!/^[a-f0-9]{40}$/.test(sha) || values[1].frontend_sha !== sha) throw new Error('Mixed frontend identity.');
  return sha;
}

/** Observe bytes the browser actually loaded; never request or replace bundles. */
export function observeDirectorCalendarBundles(page, origin) {
  const metadata = [];
  const pending = [];
  let failed = false;
  const local = ['127.0.0.1', 'localhost'].includes(new URL(origin).hostname);
  page.on('response', response => {
    const url = new URL(response.url());
    const type = response.request().resourceType();
    const root = type === 'document' && url.pathname === '/';
    const script = type === 'script' && (/^\/assets\/.+\.js$/.test(url.pathname)
      || (local && ['/src/main.js', '/src/pages/SmartCalendar.vue'].includes(url.pathname)));
    if (url.origin !== origin || (!root && !script)) return;
    pending.push((async () => {
      try {
        if (response.status() !== 200 || metadata.length >= 80) throw new Error('Invalid loaded bundle.');
        const bytes = await response.body();
        if (bytes.length > 32 * 1024 * 1024) throw new Error('Loaded bundle exceeds bound.');
        metadata.push({ kind: root ? 'document' : 'script', path: url.pathname,
          bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') });
      } catch { failed = true; }
    })());
  });
  return async () => {
    await Promise.all(pending);
    if (failed || !metadata.some(item => item.kind === 'document')
      || !metadata.some(item => /SmartCalendar/.test(item.path))) throw new Error('Loaded calendar bundle unverified.');
    return metadata.sort((a, b) => a.path.localeCompare(b.path));
  };
}
