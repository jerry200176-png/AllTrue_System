// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import {
  FREE_GIFT_LABEL, LEGACY_FLAG_UNVERIFIED_LABEL, TUITION_STATUS_CONFIG, WAIVED_LABEL,
} from '../src/lib/courseMoneyState.js';
import { formatCourseRef } from '../src/lib/studentBillingRows.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { KNOWN_POST_READ_PATHS } from './fixtures/readOnlyAppGuard.js';

/**
 * Read-only production acceptance for the #4150 payment-display fixes (#4201, #4204, #4205).
 *
 * Business rules checked on live data of the smoke director's own campus:
 *  1. A free/gift course (payment_status 'free') reads 免費／贈送, never 未繳 (學生管理 and 課程查找).
 *     No badge says 未繳 unless the course's payment_status is 'unpaid'.
 *  2. A waived course (ledger `waived`) reads 不收了 and offers no 登記這筆 / 開帳單 and no bulk 登記收款.
 *  3. A legacy paid flag with no standing payment reads 需核對, never 繳費日 or 已收清.
 *  4. Payments that net to zero (reversed) show no 繳費日.
 *
 * Expectations are computed from the GET payloads the page rendered, so the spec does not depend on
 * particular records. A rule with no live case is reported as not exercised (annotation, and a skip
 * when the whole test had nothing to check); it never counts as a pass. Output is counts and numeric
 * IDs only. Every non-GET request is aborted and fails the test; no write action is ever clicked.
 */
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });

const BASE = process.env.SMOKE_BASE_URL;
const REQUESTED_BRANCH_RAW = process.env.SMOKE_BRANCH_ID || '';
const REQUESTED_BRANCH_ID = /^\d+$/.test(REQUESTED_BRANCH_RAW) ? Number(REQUESTED_BRANCH_RAW) : 0;
const RELEASE = latestReleaseVersionForRole('director');
const TELEMETRY_PATH = '/api/v1/adoption/events';
// Reads the app sends as POST to its own origin (KNOWN_POST_READ_PATHS). They are still aborted, so no
// non-GET leaves the browser, but counted as blocked reads rather than write attempts.
const PROBE_PATH = '/api/v1/__acceptance_write_probe';
const READ_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const UNPAID_LABEL = TUITION_STATUS_CONFIG.unpaid.label;
// Bounded sample: UI students per surface, API ledger reads used to pick cases, UI ledgers per rule.
const UI_STUDENTS = 6;
const LEDGER_SCAN = 24;
const LEDGER_CASES = 4;
const SHORT = { timeout: 5_000 };

function readSession() {
  const raw = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!raw) return null;
  try { return JSON.parse(Buffer.from(raw, 'base64').toString('utf8')); } catch { return null; }
}

const SESSION = readSession();
const CAMPUSES = Array.isArray(SESSION?.user?.campuses)
  && SESSION.user.campuses.every((campus) => Number.isInteger(campus) && campus > 0) ? SESSION.user.campuses : [];
const BRANCH_ID = REQUESTED_BRANCH_RAW !== '' ? REQUESTED_BRANCH_ID : (CAMPUSES[0] || 0);
const EXPIRES_MS = Date.parse(String(SESSION?.expires_at || ''));
// Same bounded session contract as tutoring-free-production.spec.js.
const SESSION_CONTRACT = Boolean(
  typeof SESSION?.access_token === 'string' && SESSION.access_token.length > 0 && !/\s/.test(SESSION.access_token)
  && SESSION?.token_type === 'Bearer'
  && Number.isInteger(SESSION?.user?.id) && SESSION.user.id > 0
  && SESSION?.user?.role === 'director' && SESSION?.user?.must_change_password === false
  && CAMPUSES.length > 0 && Number.isInteger(BRANCH_ID) && BRANCH_ID > 0 && CAMPUSES.includes(BRANCH_ID)
  && EXPIRES_MS > Date.now() && EXPIRES_MS - Date.now() <= 30 * 60 * 1000,
);

const field = (row, ...keys) => {
  for (const key of keys) if (row?.[key] !== undefined && row?.[key] !== null) return row[key];
  return null;
};
const rowsOf = (payload) => (Array.isArray(payload) ? payload : (Array.isArray(payload?.data) ? payload.data : []));
const idOf = (row) => Number(field(row, 'id', 'ID'));
const studentIdOf = (row) => Number(field(row, 'StudentID', 'student_id'));
const studentNameOf = (row) => String(field(row, 'student_name') ?? row?.student?.name ?? '').trim();
const statusOf = (row) => String(row?.payment_status ?? '').toLowerCase();
const isTutoring = (row) => String(field(row, 'class_type', 'ClassType') || '').toLowerCase() === 'tutoring';
const campusOf = (row) => Number(field(row, 'CampusID', 'campus_id') ?? field(row?.student, 'CampusID', 'campus_id') ?? 0);

/** Rule 1: the label a course badge must (not) show, from its server payment_status. */
const expectsFreeLabel = (row) => statusOf(row) === 'free' && !isTutoring(row)
  // Documented precedence in coursePaymentBadge: a waiver reads 不收了 and a legacy-only flag reads 需核對.
  && row?.closed_reason !== 'waived' && row?.legacy_paid_flag_only !== true;
function badgeViolation(row, text) {
  if (text.includes(UNPAID_LABEL) && statusOf(row) !== 'unpaid') return 'unpaid_text_without_unpaid_status';
  if (expectsFreeLabel(row) && text !== FREE_GIFT_LABEL) return 'free_course_without_free_label';
  return null;
}

/** Rules 2-4 from one ledger payload (GET /api/v1/accounting/ledger). */
const isReversal = (p) => Number(p?.amount || 0) < 0 || String(p?.method || '') === 'void';
const netOf = (invoice) => (invoice?.payments || [])
  .reduce((sum, p) => sum + (isReversal(p) ? -Math.abs(Number(p.amount || 0)) : Number(p.amount || 0)), 0);
function ledgerFacts(ledger, courseById) {
  const courses = ledger?.courses || [];
  const members = (c) => (c.package_id ? courses.filter((m) => m.package_id === c.package_id) : [c]).map((m) => Number(m.id));
  return courses.map((c) => {
    const ids = members(c);
    const invoices = (ledger.invoices || []).filter((inv) => ids.includes(Number(inv.student_class_id)) && inv.status !== 'void');
    const receipt = (ledger.receipts || []).some((r) => ids.includes(Number(r.student_class_id))
      && r.status === 'confirmed' && Number(r.amount) > 0 && r.payment_date);
    const noStanding = !receipt && invoices.every((inv) => netOf(inv) <= 0);
    const listed = courseById.get(Number(c.id));
    const tutoring = listed ? isTutoring(listed) : null;
    return {
      id: Number(c.id),
      subject: String(c.subject || ''),
      waived: c.waived === true,
      tutoring,
      netZeroReversed: noStanding && invoices.some((inv) => (inv.payments || []).length > 0),
      legacyNoStanding: noStanding && c.waived !== true && c.free_gift_settled !== true
        && Boolean(c.paid_at || c.paid) && tutoring === false,
    };
  });
}

/**
 * Contracts the bulk 登記收款 must list (PRD v2 D18, #3753, #4205): non-tutoring, not waived, money due or not yet
 * billed, no pending report on its package, and one lead per shared package (a package with bills on several members
 * keeps per-card actions). ids is null when a contract's class type is unknown.
 */
function expectedBulkIds(ledger, facts) {
  if (ledger?.scope?.no_payment_obligation) return { ids: [] };
  const byId = new Map(facts.map((f) => [f.id, f]));
  const courses = ledger?.courses || [];
  if (courses.some((c) => byId.get(Number(c.id))?.tutoring == null)) return { ids: null };
  const owed = (id) => (ledger.invoices || []).filter((inv) => Number(inv.student_class_id) === Number(id))
    .reduce((sum, inv) => sum + Number(inv.outstanding_amount || 0), 0);
  const ordered = courses.map((c, i) => ({ c, i })).sort((a, b) => owed(b.c.id) - owed(a.c.id) || a.i - b.i).map((x) => x.c);
  const membersOf = (c) => (c.package_id ? ordered.filter((m) => m.package_id === c.package_id) : [c]);
  const pendingOn = (c) => (ledger.receipts || []).find((r) => r.status === 'pending'
    && membersOf(c).some((m) => Number(m.id) === Number(r.student_class_id)));
  const due = (m) => owed(m.id) > 0 || !m.paid;
  const leadOf = (c) => {
    if (!c.package_id) return c;
    const members = membersOf(c);
    const pending = pendingOn(c);
    if (!pending && members.filter((m) => owed(m.id) > 0).length > 1) return c;
    return (pending && members.find((m) => Number(m.id) === Number(pending.student_class_id)))
      || members.find((m) => m.waived !== true && due(m)) || members.find(due) || members[0];
  };
  const severalBills = (c) => !!c.package_id && membersOf(c).filter((m) => owed(m.id) > 0).length > 1;
  return {
    ids: ordered.filter((c) => byId.get(Number(c.id)).tutoring === false && c.waived !== true && !severalBills(c)
      && Number(leadOf(c).id) === Number(c.id) && !pendingOn(c) && due(c)).map((c) => Number(c.id)).sort((a, b) => a - b),
  };
}

async function apiGet(request, path) {
  const response = await request.get(`${BASE}${path}`, {
    headers: { Authorization: `Bearer ${SESSION.access_token}`, Accept: 'application/json' },
  });
  expect(response.ok(), `GET ${path.split('?')[0]} returned ${response.status()}`).toBeTruthy();
  return response.json();
}

const cache = { courses: null, ledgerScan: null };
async function campusCourses(request) {
  if (cache.courses) return cache.courses;
  const all = [];
  for (let page = 1; page <= 20; page += 1) {
    const batch = rowsOf(await apiGet(request, `/api/v1/student-classes?branch_id=${BRANCH_ID}&per_page=1000&page=${page}`));
    all.push(...batch);
    if (batch.length < 1000) break;
    if (page === 20) throw new Error('student-class pagination exceeded the bounded read-only limit');
  }
  cache.courses = all.filter((row) => campusOf(row) === BRANCH_ID && studentIdOf(row) > 0 && idOf(row) > 0);
  expect(cache.courses.length, 'campus must have courses in the director scope').toBeGreaterThan(0);
  return cache.courses;
}

/** Unique student ids: students with a matching course first, then the rest; ascending id within each group. */
function studentsFirst(courses, matches, limit) {
  const ids = (list) => [...new Set(list.map(studentIdOf))].sort((a, b) => a - b);
  const preferred = ids(courses.filter(matches));
  return [...preferred, ...ids(courses).filter((id) => !preferred.includes(id))].slice(0, limit);
}

/** API-only scan (bounded) that picks students whose ledger has a case for rules 2-4. */
async function ledgerScan(request) {
  if (cache.ledgerScan) return cache.ledgerScan;
  const courses = await campusCourses(request);
  const byId = new Map(courses.map((row) => [idOf(row), row]));
  const likely = (row) => row?.closed_reason === 'waived' || row?.legacy_paid_flag_only === true;
  const scan = { waived: [], legacy: [], reversed: [], scanned: 0 };
  for (const studentId of studentsFirst(courses, likely, LEDGER_SCAN)) {
    const facts = ledgerFacts(await apiGet(request, `/api/v1/accounting/ledger?student_id=${studentId}`), byId);
    scan.scanned += 1;
    if (facts.some((f) => f.waived && f.tutoring !== true)) scan.waived.push(studentId);
    if (facts.some((f) => f.legacyNoStanding)) scan.legacy.push(studentId);
    if (facts.some((f) => f.netZeroReversed)) scan.reversed.push(studentId);
  }
  cache.ledgerScan = scan;
  return scan;
}

function note(type, description) {
  test.info().annotations.push({ type, description });
}
/** Target-rule coverage for the gate: one `rule-coverage` note per test, `<rule>=<cases checked>`. */
function ruleCoverage(rule, cases) {
  note('rule-coverage', `${rule}=${Number(cases)}`);
}
function coverage(counts) {
  for (const [key, value] of Object.entries(counts)) note('coverage', `${key}=${Number(value)}`);
}

/** Session injection plus an in-page and a network-level write block (pattern of the existing production specs). */
async function openReadOnly(page) {
  const blocked = [];
  await page.addInitScript(({ session, branch, release, telemetryPath, postReadPaths }) => {
    localStorage.setItem('alltrue_session', JSON.stringify(session));
    localStorage.setItem('app_branch', String(branch));
    localStorage.setItem('alltrue_release_notes_seen', release);
    sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
    window.__readOnlyViolations = [];
    window.__blockedPostReads = 0;
    const reads = ['GET', 'HEAD', 'OPTIONS'];
    const isTelemetry = (method, url) => method === 'POST'
      && new URL(url, window.location.href).origin === window.location.origin
      && new URL(url, window.location.href).pathname === telemetryPath;
    const reject = (method, url) => {
      const target = new URL(String(url || ''), window.location.href);
      if (method === 'POST' && target.origin === window.location.origin && postReadPaths.includes(target.pathname)) {
        window.__blockedPostReads += 1;
        throw new Error('payment acceptance blocked a POST read');
      }
      window.__readOnlyViolations.push({ method, path: new URL(String(url || ''), window.location.href).pathname });
      throw new Error(`payment acceptance blocked ${method}`);
    };
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
      const method = String(init.method || input?.method || 'GET').toUpperCase();
      const url = typeof input === 'string' ? input : input?.url || String(input || '');
      if (!reads.includes(method) && !isTelemetry(method, url)) {
        // fetch() never throws synchronously: a blocked call returns a rejected promise, like a network error.
        try { reject(method, url); } catch (error) { return Promise.reject(error); }
      }
      return nativeFetch(input, init);
    };
    const xhrOpen = XMLHttpRequest.prototype.open;
    const xhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function guardedOpen(method, url, ...args) {
      this.__acceptanceMethod = String(method || 'GET').toUpperCase();
      this.__acceptanceUrl = String(url || '');
      return xhrOpen.call(this, method, url, ...args);
    };
    XMLHttpRequest.prototype.send = function guardedSend(...args) {
      const method = this.__acceptanceMethod || 'GET';
      if (!reads.includes(method) && !isTelemetry(method, this.__acceptanceUrl)) return reject(method, this.__acceptanceUrl);
      return xhrSend.apply(this, args);
    };
    HTMLFormElement.prototype.submit = function guardedSubmit() { return reject('FORM.SUBMIT', this.action); };
    HTMLFormElement.prototype.requestSubmit = function guardedRequestSubmit() { return reject('FORM.REQUESTSUBMIT', this.action); };
    document.addEventListener('submit', (event) => {
      event.preventDefault();
      window.__readOnlyViolations.push({ method: 'FORM.DEFAULT', path: '' });
    }, true);
    const nativeBeacon = navigator.sendBeacon?.bind(navigator);
    navigator.sendBeacon = (url, data) => {
      if (!isTelemetry('POST', url)) return reject('BEACON', url);
      return nativeBeacon ? nativeBeacon(url, data) : false;
    };
    window.print = () => reject('PRINT', '');
  }, { session: SESSION, branch: BRANCH_ID, release: RELEASE, telemetryPath: TELEMETRY_PATH, postReadPaths: [...KNOWN_POST_READ_PATHS] });
  // Network layer: nothing but reads leaves the browser. Telemetry is answered locally and never reaches production.
  await page.route('**/*', async (route) => {
    const req = route.request();
    if (READ_METHODS.has(req.method())) return route.fallback();
    const url = new URL(req.url());
    if (req.method() === 'POST' && url.origin === new URL(BASE).origin && url.pathname === TELEMETRY_PATH) {
      return route.fulfill({ status: 204, body: '' });
    }
    blocked.push({ method: req.method(), path: url.pathname });
    return route.abort('blockedbyclient');
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/');
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
  await dismissOverlays(page);
  // Any write the app attempted while loading is a failure; it is never cleared.
  expect(await page.evaluate(() => window.__readOnlyViolations), 'application write attempts during startup').toEqual([]);
  expect(blocked, 'network write attempts during startup').toEqual([]);
  // Prove the in-page guard is live before any assertion relies on it. The probe must come back as a rejected
  // promise (not a synchronous throw), be recorded exactly once, and is the only record removed.
  const probe = await page.evaluate(async (probePath) => {
    let threw = false;
    let pending;
    try { pending = window.fetch(probePath, { method: 'POST' }); } catch { threw = true; }
    const rejected = pending ? await pending.then(() => false, () => true) : false;
    const matches = window.__readOnlyViolations.filter((v) => v.method === 'POST' && v.path === probePath);
    const others = window.__readOnlyViolations.filter((v) => !(v.method === 'POST' && v.path === probePath));
    window.__readOnlyViolations = others;
    return { threw, rejected, recorded: matches.length, others };
  }, PROBE_PATH);
  expect(probe, 'in-page fetch write guard rejects the synthetic probe asynchronously and records it once')
    .toEqual({ threw: false, rejected: true, recorded: 1, others: [] });
  return {
    async blockedPostReads() { return Number(await page.evaluate(() => window.__blockedPostReads)); },
    async assertNoWrites() {
      expect(await page.evaluate(() => window.__readOnlyViolations), 'in-page write attempts').toEqual([]);
      expect(blocked, 'network write attempts').toEqual([]);
    },
  };
}

/** Every GET /api/v1/student-classes row the page loaded, by course id (latest response wins). */
function recordCourseRows(page) {
  const byId = new Map();
  page.on('response', async (response) => {
    if (new URL(response.url()).pathname !== '/api/v1/student-classes' || response.request().method() !== 'GET' || !response.ok()) return;
    try { for (const row of rowsOf(await response.json())) if (idOf(row) > 0) byId.set(idOf(row), row); } catch { /* navigated away */ }
  });
  return byId;
}

async function openCourseFinder(page) {
  const nav = page.getByRole('button', { name: '課程查找', exact: false }).first();
  await expect(nav).toBeVisible({ timeout: 15_000 });
  await nav.click();
  await expect(page.getByRole('heading', { name: '課程查找', exact: true }).first()).toBeVisible({ timeout: 15_000 });
}

/** 課程查找 → filter one student → 帳務資料 tab; returns the group, or null when the page lists no such group. */
/** Resolves true once the locator is visible, false after `timeout` (locator.isVisible() does not wait). */
const appears = (locator, timeout = 10_000) => locator.waitFor({ state: 'visible', timeout }).then(() => true, () => false);

/**
 * 課程查找 → search one student → that student's own group (keyed `sid:<id>`, so a same-name or longer-name student
 * is never picked) → 帳務資料 tab. Waits for the debounced search's own GET before reading the list; returns null when
 * the page lists no group for this student id.
 */
async function openBillingTab(page, studentId, studentName) {
  const filter = page.locator('#course-filter-student');
  if ((await filter.inputValue()) !== studentName) {
    const searched = page.waitForResponse((r) => {
      const url = new URL(r.url());
      return url.pathname === '/api/v1/student-classes' && r.request().method() === 'GET'
        && url.searchParams.get('name') === studentName;
    }, { timeout: 20_000 });
    await filter.fill(studentName);
    expect((await searched).ok(), 'course search GET').toBe(true);
  }
  await expect(page.locator('.course-list-skeleton')).toHaveCount(0, { timeout: 20_000 });
  const group = page.locator('.student-group-card').filter({ has: page.locator(`#student-group-toggle-sid-${Number(studentId)}`) });
  if (!(await appears(group))) return null;
  await expect(group).toHaveCount(1);
  await group.locator('[data-testid="student-tab-billing"]').click();
  await expect(group.locator('.student-billing-state', { hasText: '載入帳務中' })).toHaveCount(0, { timeout: 20_000 });
  return group;
}

test.describe('production acceptance — payment display (#4150)', () => {
  test.skip(!BASE || !SESSION?.access_token, 'missing controlled production director session');

  test('學生管理: free courses read 免費／贈送 and 未繳 only when unpaid', async ({ page, request }) => {
    test.setTimeout(180_000);
    expect(SESSION_CONTRACT, 'session must satisfy the bounded read-only contract').toBe(true);
    const courses = await campusCourses(request);
    const names = new Map(courses.map((row) => [studentIdOf(row), studentNameOf(row)]));
    const guard = await openReadOnly(page);
    const pageRows = recordCourseRows(page);
    await page.getByRole('button', { name: '學生管理', exact: true }).click();
    await expect(page.getByRole('heading', { name: /學生管理/ }).first()).toBeVisible({ timeout: 20_000 });

    const counts = { students_sampled: 0, students_not_listed: 0, cards_checked: 0, free_cards_checked: 0, cards_unmatched: 0 };
    const violations = [];
    for (const studentId of studentsFirst(courses, (row) => statusOf(row) === 'free', UI_STUDENTS)) {
      counts.students_sampled += 1;
      let row = page.locator(`tr.student-row[data-student-id="${studentId}"]`);
      if (!(await row.isVisible().catch(() => false)) && names.get(studentId)) {
        await page.locator('#students-name-filter').fill(names.get(studentId));
        row = page.locator(`tr.student-row[data-student-id="${studentId}"]`);
      }
      if (!(await appears(row))) { counts.students_not_listed += 1; continue; }
      if ((await row.getAttribute('aria-expanded')) !== 'true') await row.locator('.btn-course-disclosure').click();
      const cards = page.locator(`#student-course-detail-${studentId} article.student-course-card[data-course-id]`);
      await cards.first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
      for (const card of await cards.all()) {
        const courseId = Number(await card.getAttribute('data-course-id'));
        const server = pageRows.get(courseId) || courses.find((c) => idOf(c) === courseId);
        if (!server) { counts.cards_unmatched += 1; continue; }
        const text = (await card.locator('.student-course-card__payment').innerText(SHORT)).trim();
        counts.cards_checked += 1;
        if (expectsFreeLabel(server)) counts.free_cards_checked += 1;
        const problem = badgeViolation(server, text);
        if (problem) violations.push({ course_id: courseId, problem });
      }
      if ((await row.getAttribute('aria-expanded')) === 'true') await row.locator('.btn-course-disclosure').click();
    }
    counts.blocked_post_reads = await guard.blockedPostReads();
    coverage(counts);
    ruleCoverage('free_gift', counts.free_cards_checked);
    await test.info().attach('students-list-coverage.json', { body: JSON.stringify({ branch_id: BRANCH_ID, ...counts, violations }), contentType: 'application/json' });
    await guard.assertNoWrites();
    expect(violations, 'course payment badges on 學生管理 (course ids only)').toEqual([]);
    expect(counts.cards_unmatched, 'every rendered card maps to a loaded course').toBe(0);
    if (counts.free_cards_checked === 0) note('not-exercised', 'students_list_free_label');
    test.skip(counts.cards_checked === 0, 'not-exercised: no course card rendered for the sampled students');
  });

  test('課程查找: free courses read 免費／贈送 and 未繳 only when unpaid', async ({ page, request }) => {
    test.setTimeout(180_000);
    expect(SESSION_CONTRACT, 'session must satisfy the bounded read-only contract').toBe(true);
    const courses = await campusCourses(request);
    const names = new Map(courses.map((row) => [studentIdOf(row), studentNameOf(row)]));
    const coursesById = new Map(courses.map((row) => [idOf(row), row]));
    const guard = await openReadOnly(page);
    const pageRows = recordCourseRows(page);
    await openCourseFinder(page);

    const counts = { students_sampled: 0, students_not_listed: 0, rows_checked: 0, free_rows_checked: 0, rows_unmatched: 0 };
    const violations = [];
    for (const studentId of studentsFirst(courses, (row) => statusOf(row) === 'free', UI_STUDENTS)) {
      counts.students_sampled += 1;
      const name = names.get(studentId);
      const group = name ? await openBillingTab(page, studentId, name) : null;
      if (!group) { counts.students_not_listed += 1; continue; }
      for (const tr of await group.locator('tr').filter({ has: page.locator('[data-testid="billing-contract-period"]') }).all()) {
        const refLine = (await tr.locator('[data-testid="billing-contract-period"]').innerText(SHORT)).trim().split('\n')[0].trim();
        const refId = Number((refLine.match(/^課程 (\d+)$/) || [])[1] || 0);
        const server = refId > 0 && formatCourseRef(refId) === refLine ? (pageRows.get(refId) || coursesById.get(refId)) : null;
        if (!server) { counts.rows_unmatched += 1; continue; }
        const text = (await tr.locator('.payment-status-badge').innerText(SHORT)).trim();
        counts.rows_checked += 1;
        if (expectsFreeLabel(server)) counts.free_rows_checked += 1;
        const problem = badgeViolation(server, text);
        if (problem) violations.push({ course_id: idOf(server), problem });
      }
    }
    counts.blocked_post_reads = await guard.blockedPostReads();
    coverage(counts);
    ruleCoverage('free_gift', counts.free_rows_checked);
    await test.info().attach('course-finder-coverage.json', { body: JSON.stringify({ branch_id: BRANCH_ID, ...counts, violations }), contentType: 'application/json' });
    await guard.assertNoWrites();
    expect(violations, 'course payment badges on 課程查找 (course ids only)').toEqual([]);
    expect(counts.rows_unmatched, 'every billing row maps to a campus course').toBe(0);
    if (counts.free_rows_checked === 0) note('not-exercised', 'course_finder_free_label');
    test.skip(counts.rows_checked === 0, 'not-exercised: no billing row rendered for the sampled students');
  });

  /**
   * Opens the ledger in place (課程查找 → 帳務資料 → 在這裡看帳務) for up to LEDGER_CASES students of one rule,
   * and checks each contract card against the ledger payload the page itself received.
   */
  async function checkLedgerRule(page, request, rule) {
    test.setTimeout(240_000);
    expect(SESSION_CONTRACT, 'session must satisfy the bounded read-only contract').toBe(true);
    const courses = await campusCourses(request);
    const names = new Map(courses.map((row) => [studentIdOf(row), studentNameOf(row)]));
    const scan = await ledgerScan(request);
    const guard = await openReadOnly(page);
    const pageRows = recordCourseRows(page);
    await openCourseFinder(page);
    const counts = { ledgers_scanned: scan.scanned, students_with_case: scan[rule].length, ledgers_opened: 0, cases_checked: 0, students_not_listed: 0 };
    const violations = [];
    for (const studentId of scan[rule].slice(0, LEDGER_CASES)) {
      const group = names.get(studentId) ? await openBillingTab(page, studentId, names.get(studentId)) : null;
      if (!group) { counts.students_not_listed += 1; continue; }
      const ledgerResponse = page.waitForResponse((r) => new URL(r.url()).pathname === '/api/v1/accounting/ledger'
        && r.request().method() === 'GET', { timeout: 20_000 });
      const entry = group.locator('.btn-invoices').first();
      if (!(await appears(entry))) { counts.students_not_listed += 1; continue; }
      await entry.click();
      const dialog = page.getByRole('dialog').filter({ has: page.locator('#ledger-modal-title') });
      await expect(dialog).toBeVisible({ timeout: 20_000 });
      const response = await ledgerResponse;
      expect(response.status(), 'ledger GET').toBe(200);
      const ledger = await response.json();
      // The ledger must belong to the student the scan selected (never a same-name student's).
      if (Number(ledger?.student?.id) !== Number(studentId)) violations.push({ course_id: 0, problem: 'ledger_opened_for_other_student' });
      counts.ledgers_opened += 1;
      const byId = new Map([...courses.map((row) => [idOf(row), row]), ...pageRows]);
      const facts = ledgerFacts(ledger, byId);
      await expect(dialog.locator('[data-testid^="contract-card-"]').first()).toBeVisible({ timeout: 20_000 });
      for (const fact of facts) {
        const card = dialog.locator(`[data-testid="contract-card-${fact.id}"]`);
        const relevant = (rule === 'waived' && fact.waived && fact.tutoring !== true)
          || (rule === 'legacy' && fact.legacyNoStanding) || (rule === 'reversed' && fact.netZeroReversed);
        if (relevant && !(await appears(card, 5_000))) { violations.push({ course_id: fact.id, problem: 'contract_card_missing' }); continue; }
        if (rule === 'waived' && fact.waived && fact.tutoring !== true) {
          counts.cases_checked += 1;
          const money = card.locator('[data-testid="contract-waived"]');
          if ((await money.innerText(SHORT).catch(() => '')).trim() !== WAIVED_LABEL) violations.push({ course_id: fact.id, problem: 'waived_without_label' });
          if (await card.locator('[data-testid="contract-record"], [data-testid="contract-issue"]').count()) violations.push({ course_id: fact.id, problem: 'waived_offers_payment_action' });
        }
        if (rule === 'legacy' && fact.legacyNoStanding) {
          counts.cases_checked += 1;
          const text = await card.innerText(SHORT);
          if ((await card.locator('[data-testid="contract-legacy-unverified"]').innerText(SHORT).catch(() => '')).trim() !== LEGACY_FLAG_UNVERIFIED_LABEL) violations.push({ course_id: fact.id, problem: 'legacy_without_review_note' });
          if (text.includes('繳費日') || text.includes('已收清')) violations.push({ course_id: fact.id, problem: 'legacy_reads_as_paid' });
        }
        if (rule === 'reversed' && fact.netZeroReversed) {
          counts.cases_checked += 1;
          const text = await card.innerText(SHORT);
          if (text.includes('繳費日') || /已收 \d+\/\d+/.test(text)) violations.push({ course_id: fact.id, problem: 'net_zero_payment_shows_paid_date' });
        }
      }
      if (rule === 'waived') {
        // Exact bulk 登記收款 membership: open the allocation view (local, no request; never its submit) and compare
        // its alloc-row-<id> set with the contracts that are due. A waived contract is never in it, no due one is missing.
        const expected = expectedBulkIds(ledger, facts);
        const sticky = dialog.locator('[data-testid="ledger-sticky-pay-btn"]');
        const waivedIds = facts.filter((f) => f.waived).map((f) => f.id);
        let shown = [];
        if (await sticky.count()) {
          await sticky.click();
          const alloc = dialog.locator('[data-testid="alloc"]');
          await expect(alloc).toBeVisible({ timeout: 10_000 });
          shown = (await alloc.locator('[data-testid^="alloc-row-"]').evaluateAll((rows) => rows
            .map((row) => Number(String(row.getAttribute('data-testid')).replace('alloc-row-', ''))))).sort((a, b) => a - b);
          await alloc.locator('[data-testid="alloc-back"]').click();
          await expect(alloc).toHaveCount(0, { timeout: 10_000 });
        }
        for (const id of shown.filter((x) => waivedIds.includes(x))) violations.push({ course_id: id, problem: 'bulk_includes_waived_contract' });
        if (expected.ids) {
          for (const id of expected.ids.filter((x) => !shown.includes(x))) violations.push({ course_id: id, problem: 'bulk_missing_payable_contract' });
          for (const id of shown.filter((x) => !expected.ids.includes(x) && !waivedIds.includes(x))) violations.push({ course_id: id, problem: 'bulk_includes_unexpected_contract' });
          counts.bulk_membership_exact = (counts.bulk_membership_exact || 0) + 1;
        } else {
          // A contract outside the campus course list has an unknown class type: only the waived exclusion is decidable.
          counts.bulk_membership_undetermined = (counts.bulk_membership_undetermined || 0) + 1;
        }
      }
      await dialog.getByRole('button', { name: '關閉學生帳務' }).click();
      await expect(dialog).toHaveCount(0, { timeout: 10_000 });
    }
    counts.blocked_post_reads = await guard.blockedPostReads();
    coverage(counts);
    ruleCoverage({ waived: 'waived', legacy: 'legacy_only', reversed: 'net_zero_reversal' }[rule], counts.cases_checked);
    await test.info().attach(`ledger-${rule}-coverage.json`, { body: JSON.stringify({ branch_id: BRANCH_ID, ...counts, violations }), contentType: 'application/json' });
    await guard.assertNoWrites();
    expect(violations, `ledger rule ${rule} (course ids only)`).toEqual([]);
    test.skip(counts.cases_checked === 0, `not-exercised: no ${rule} case among ${scan.scanned} scanned ledgers`);
  }

  test('帳務: a waived course reads 不收了 and offers no payment action', async ({ page, request }) => {
    await checkLedgerRule(page, request, 'waived');
  });

  test('帳務: a legacy paid flag with no standing payment reads 需核對', async ({ page, request }) => {
    await checkLedgerRule(page, request, 'legacy');
  });

  test('帳務: payments that net to zero show no 繳費日', async ({ page, request }) => {
    await checkLedgerRule(page, request, 'reversed');
  });
});
