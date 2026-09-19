// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

/** Authenticated, read-only production acceptance for In-App #325. */
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });
const BASE = process.env.SMOKE_BASE_URL;
const REQUESTED_BRANCH_RAW = process.env.SMOKE_BRANCH_ID || '';
const HAS_REQUESTED_BRANCH = REQUESTED_BRANCH_RAW !== '';
const REQUESTED_BRANCH_ID = /^\d+$/.test(REQUESTED_BRANCH_RAW) ? Number(REQUESTED_BRANCH_RAW) : 0;
const RELEASE = latestReleaseVersionForRole('director');

function readSession() {
  const raw = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!raw) return null;
  try { return JSON.parse(Buffer.from(raw, 'base64').toString('utf8')); } catch { return null; }
}

const SESSION = readSession();
const AUTHORIZED_CAMPUSES = Array.isArray(SESSION?.user?.campuses)
  && SESSION.user.campuses.every((campus) => Number.isInteger(campus) && campus > 0)
  ? SESSION.user.campuses
  : [];
const BRANCH_ID = HAS_REQUESTED_BRANCH ? REQUESTED_BRANCH_ID : (AUTHORIZED_CAMPUSES[0] || 0);

function sessionExpiryMs(value) {
  if (typeof value === 'number' && Number.isFinite(value) && value > 0) return value > 1e12 ? value : value * 1000;
  if (typeof value === 'string' && /^\d{10}(?:\.\d+)?$/.test(value)) return Number(value) * 1000;
  if (typeof value === 'string' && /^\d{13}$/.test(value)) return Number(value);
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/.test(value)) return Date.parse(value);
  return 0;
}

const SESSION_EXPIRY_MS = sessionExpiryMs(SESSION?.expires_at);
const SESSION_CONTRACT = Boolean(
  typeof SESSION?.access_token === 'string' && SESSION.access_token.trim() === SESSION.access_token
  && SESSION.access_token.length > 0 && !/[\r\n]/.test(SESSION.access_token)
  && SESSION?.token_type === 'Bearer'
  && Number.isInteger(SESSION?.user?.id) && SESSION.user.id > 0
  && SESSION?.user?.role === 'director'
  && SESSION?.user?.must_change_password === false
  && Array.isArray(SESSION?.user?.campuses) && SESSION.user.campuses.length > 0
  && SESSION.user.campuses.every((campus) => Number.isInteger(campus) && campus > 0)
  && Number.isInteger(BRANCH_ID) && BRANCH_ID > 0 && AUTHORIZED_CAMPUSES.includes(BRANCH_ID)
  && SESSION_EXPIRY_MS > Date.now() && SESSION_EXPIRY_MS - Date.now() <= 30 * 60 * 1000,
);

function rows(payload) {
  return Array.isArray(payload) ? payload : (Array.isArray(payload?.data) ? payload.data : []);
}

function field(row, ...keys) {
  for (const key of keys) if (row?.[key] !== undefined && row?.[key] !== null) return row[key];
  return null;
}

function isTutoring(row) {
  return String(field(row, 'class_type', 'ClassType') || '').trim().toLowerCase() === 'tutoring';
}

function amount(row, ...keys) {
  return Number(field(row, ...keys) || 0);
}

function containsForbiddenData(value) {
  const forbidden = /(phone|email|password|token|name|body|note|address|line[_-]?id|student[_-]?id|class[_-]?id|invoice|receipt|amount|charge|pay)/i;
  if (Array.isArray(value)) return value.some(containsForbiddenData);
  if (typeof value === 'string') return /@|\b\d{7,}\b|^Bearer\s|^eyJ/i.test(value);
  if (!value || typeof value !== 'object') return false;
  return Object.entries(value).some(([key, item]) => forbidden.test(key) || containsForbiddenData(item));
}

async function getJson(request, path, token) {
  const response = await request.get(`${BASE}${path}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(response.ok(), `${path} returned ${response.status()}`).toBeTruthy();
  return response.json();
}

async function getPagedRows(request, path, token) {
  const result = [];
  for (let page = 1; page <= 20; page += 1) {
    const payload = await getJson(request, `${path}&per_page=1000&page=${page}`, token);
    const batch = rows(payload);
    result.push(...batch);
    if (batch.length < 1000) return result;
  }
  throw new Error('student-class pagination exceeded bounded read-only limit');
}

test.describe('production acceptance — tutoring free/non-receivable', () => {
  test('director read-only API and real UI acceptance', async ({ page, request }) => {
    test.skip(!BASE, 'missing controlled production base URL');
    expect(SESSION_CONTRACT, 'session must satisfy the exact bounded read-only contract').toBe(true);
    const token = SESSION.access_token;
    const unsafe = [];
    const telemetry = [];
    let schedulerCreateIntercepted = false;
    let schedulerCreatePayload = null;

    await page.addInitScript(({ session, branch, release }) => {
      localStorage.setItem('alltrue_session', JSON.stringify(session));
      localStorage.setItem('app_branch', String(branch));
      localStorage.setItem('alltrue_release_notes_seen', release);
      sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
      window.__printAttempts = 0;
      window.__printGuardSelfTest = false;
      const nativePrint = window.print.bind(window);
      let originalPrintCalls = 0;
      window.print = (...args) => { originalPrintCalls += 1; return nativePrint(...args); };
      window.print = () => { window.__printAttempts += 1; throw new Error('window.print forbidden in read-only acceptance'); };
      try { window.print(); } catch (error) { window.__printGuardSelfTest = error instanceof Error; }
      window.__printAttempts = 0;
      window.__originalPrintCalls = originalPrintCalls;

      window.__readOnlyViolations = [];
      const isTelemetry = (method, url) => String(method).toUpperCase() === 'POST'
        && new URL(url, window.location.href).origin === window.location.origin
        && new URL(url, window.location.href).pathname === '/api/v1/adoption/events';
      const rejectWrite = (method, url) => {
        const detail = { method: String(method).toUpperCase(), url: String(url || '') };
        window.__readOnlyViolations.push(detail);
        throw new Error(`production acceptance blocked ${detail.method} ${detail.url}`);
      };
      const originalFetch = window.fetch.bind(window);
      window.fetch = (input, init = {}) => {
        const method = String(init.method || input?.method || 'GET').toUpperCase();
        const url = typeof input === 'string' ? input : input?.url || '';
        const schedulerProbe = window.__schedulerSubmitProbe === true
          && method === 'POST' && new URL(url, window.location.href).pathname === '/api/v1/class-sessions/batch';
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && !isTelemetry(method, url) && !schedulerProbe) return rejectWrite(method, url);
        return originalFetch(input, init);
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
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && !isTelemetry(method, this.__acceptanceUrl)) {
          return rejectWrite(method, this.__acceptanceUrl);
        }
        return xhrSend.apply(this, args);
      };
      const originalSubmit = HTMLFormElement.prototype.submit;
      const originalRequestSubmit = HTMLFormElement.prototype.requestSubmit;
      HTMLFormElement.prototype.submit = function guardedSubmit() {
        return rejectWrite('FORM.SUBMIT', this.action);
      };
      HTMLFormElement.prototype.requestSubmit = function guardedRequestSubmit(...args) {
        return rejectWrite('FORM.REQUESTSUBMIT', this.action);
      };
      document.addEventListener('submit', (event) => {
        event.preventDefault();
        if (window.__selfTestingForm) {
          window.__selfTestingFormBlocked = event.defaultPrevented;
          return;
        }
        rejectWrite('FORM.DEFAULT', event.target?.action || window.location.href);
      }, true);
      const nativeBeacon = navigator.sendBeacon?.bind(navigator);
      navigator.sendBeacon = (url, data) => {
        if (!isTelemetry('POST', url)) return rejectWrite('BEACON', url);
        return nativeBeacon ? nativeBeacon(url, data) : false;
      };
      window.__writeGuardSelfTests = { form: false, submit: false, requestSubmit: false, beacon: false };
      try {
        const probe = document.createElement('form');
        probe.action = '/write-probe';
        document.documentElement.appendChild(probe);
        window.__selfTestingForm = true;
        probe.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        window.__writeGuardSelfTests.form = window.__selfTestingFormBlocked === true;
        probe.remove();
      } catch (error) { window.__writeGuardSelfTests.form = error instanceof Error; }
      window.__selfTestingForm = false;
      try {
        const probe = document.createElement('form');
        probe.submit();
      } catch (error) { window.__writeGuardSelfTests.submit = error instanceof Error; }
      try {
        const probe = document.createElement('form');
        probe.requestSubmit();
      } catch (error) { window.__writeGuardSelfTests.requestSubmit = error instanceof Error; }
      try { navigator.sendBeacon('https://outside.invalid/api/v1/adoption/events', 'probe'); }
      catch (error) { window.__writeGuardSelfTests.beacon = error instanceof Error; }
      window.__readOnlyViolations = [];
      window.__schedulerSubmitProbe = false;
      void originalSubmit; void originalRequestSubmit;
    }, { session: SESSION, branch: BRANCH_ID, release: RELEASE });

    page.on('request', (req) => {
      const method = req.method();
      const path = new URL(req.url()).pathname;
      if (method === 'POST' && path === '/api/v1/adoption/events') return;
      if (method === 'POST' && path === '/api/v1/class-sessions/batch' && schedulerCreateIntercepted) return;
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) unsafe.push({ method, path });
    });
    await page.route('**/api/v1/adoption/events', async (route) => {
      const req = route.request();
      expect(new URL(req.url()).origin).toBe(new URL(BASE).origin);
      expect(req.method()).toBe('POST');
      const body = req.postDataJSON() || {};
      expect(Object.keys(body).sort()).toEqual(['branch_id', 'event', 'meta']);
      expect(body).toEqual(expect.objectContaining({ event: expect.any(String), branch_id: BRANCH_ID, meta: expect.any(Object) }));
      expect(body.event.trim()).not.toBe('');
      expect(body.event).toMatch(/^[a-z0-9_]+$/);
      expect(Array.isArray(body.meta)).toBe(false);
      const schemas = {
        dashboard_opened: { keys: ['page', 'role', 'telem_day', 'telem_session'], role: 'director', page: 'director-dashboard' },
        director_trust_decision_impression: { keys: ['has_drilldown', 'key', 'people_total', 'severity', 'target', 'telem_day', 'telem_session', 'viewport'] },
        director_trust_decision_click: { keys: ['from', 'has_drilldown', 'key', 'people_shown', 'severity', 'target', 'telem_day', 'telem_session'] },
        director_trust_score_shown: { keys: ['critical_count', 'decision_count', 'decision_keys', 'score', 'status', 'telem_day', 'telem_session', 'warning_count'] },
      };
      const schema = schemas[body.event];
      expect(schema, `unexpected telemetry event ${body.event}`).toBeDefined();
      expect(Object.keys(body.meta).sort()).toEqual(schema.keys);
      if (schema.role) expect(body.meta.role).toBe(schema.role);
      if (schema.page) expect(body.meta.page).toBe(schema.page);
      if (body.event === 'director_trust_decision_impression' || body.event === 'director_trust_decision_click') {
        expect(body.meta.key).toMatch(/^[a-z0-9_-]{1,80}$/i);
        expect(['critical', 'warning', '']).toContain(body.meta.severity);
        expect(['calendar', 'duplicate-review', 'course-mgmt', 'tuition', '']).toContain(body.meta.target);
        expect(typeof body.meta.has_drilldown).toBe('boolean');
        if (body.event.endsWith('impression')) {
          expect(Number.isInteger(body.meta.people_total)).toBe(true);
          expect(body.meta.people_total).toBeGreaterThanOrEqual(0);
          expect(body.meta.people_total).toBeLessThanOrEqual(100000);
          expect(body.meta.viewport).toBe(1);
        } else {
          expect(body.meta.from).toBe('decision_cta');
          expect(Number.isInteger(body.meta.people_shown)).toBe(true);
          expect(body.meta.people_shown).toBeGreaterThanOrEqual(0);
          expect(body.meta.people_shown).toBeLessThanOrEqual(100000);
        }
      }
      if (body.event === 'director_trust_score_shown') {
        for (const key of ['critical_count', 'warning_count', 'decision_count']) {
          expect(Number.isInteger(body.meta[key])).toBe(true);
          expect(body.meta[key]).toBeGreaterThanOrEqual(0);
          expect(body.meta[key]).toBeLessThanOrEqual(100000);
        }
        expect(body.meta.score).toBeGreaterThanOrEqual(0);
        expect(body.meta.score).toBeLessThanOrEqual(100);
        expect(['red', 'yellow', 'green']).toContain(body.meta.status);
        expect(body.meta.decision_keys.length).toBe(body.meta.decision_count);
      }
      const allowedMeta = new Set(schema.keys);
      expect(Object.keys(body.meta).every((key) => allowedMeta.has(key))).toBe(true);
      expect(Object.entries(body.meta).every(([key, value]) => key === 'decision_keys'
        ? Array.isArray(value) && value.every((item) => typeof item === 'string' && /^[a-z0-9_-]{1,80}$/i.test(item))
        : ['string', 'number', 'boolean'].includes(typeof value))).toBe(true);
      if (body.meta.workflow !== undefined) expect(['billing', 'calendar']).toContain(body.meta.workflow);
      if (body.meta.phase !== undefined) expect(['started', 'completed', 'returned', 'error']).toContain(body.meta.phase);
      if (body.meta.duration_ms !== undefined) expect(body.meta.duration_ms).toBeGreaterThanOrEqual(0);
      if (body.meta.telem_session !== undefined) expect(body.meta.telem_session).toMatch(/^t_[a-z0-9_]+$/);
      if (body.meta.telem_day !== undefined) expect(body.meta.telem_day).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(containsForbiddenData(body)).toBe(false);
      telemetry.push(body);
      await route.fulfill({ status: 204, body: '' });
    });
    await page.route('**/api/v1/class-sessions/batch', async (route) => {
      if (!schedulerCreateIntercepted && await page.evaluate(() => window.__schedulerSubmitProbe === true)) {
        expect(route.request().method()).toBe('POST');
        schedulerCreatePayload = route.request().postDataJSON() || {};
        schedulerCreateIntercepted = true;
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
        return;
      }
      await route.abort();
    });

    const courses = await getPagedRows(request, `/api/v1/student-classes?campus_id=${BRANCH_ID}`, token);
    const studentCampus = (row) => field(row, 'CampusID', 'campus_id') ?? field(row?.student, 'CampusID', 'campus_id');
    const arScope = (row) => Number(studentCampus(row) || 0) === BRANCH_ID;
    const scopedCourses = courses.filter(arScope);
    expect(scopedCourses.length, 'student-class branch read must overlap AR student-campus scope').toBeGreaterThan(0);
    const tutoring = scopedCourses.filter(isTutoring);
    const active = (row) => {
      const stop = field(row, 'Stop', 'stop');
      return stop !== null && stop !== undefined && (stop === 0 || stop === '0');
    };
    const regularUnpaid = scopedCourses.filter((row) => active(row) && !isTutoring(row) && amount(row, 'Charge', 'charge') > amount(row, 'Pay', 'paid'));
    const regularOutstanding = regularUnpaid.reduce((sum, row) => sum + Math.max(0, amount(row, 'Charge', 'charge') - amount(row, 'Pay', 'paid')), 0);
    const activeTutoring = tutoring.filter(active);
    const tutoringOutstanding = activeTutoring.reduce((sum, row) => sum + Math.max(0, amount(row, 'Charge', 'charge') - amount(row, 'Pay', 'paid')), 0);
    expect(activeTutoring.length, 'production branch must provide a non-empty tutoring control').toBeGreaterThan(0);
    expect(regularUnpaid.length, 'production branch must provide a non-empty regular unpaid control').toBeGreaterThan(0);
    const alerts = rows(await getJson(request, `/api/v1/alerts/tuition?branch_id=${BRANCH_ID}`, token));
    const alertIds = new Set(alerts.map((row) => String(field(row, 'id', 'class_id', 'student_class_id', 'StudentClassID') || '')));
    const tutoringIds = new Set(activeTutoring.map((row) => String(field(row, 'id', 'ID', 'class_id') || '')));
    const regularIds = new Set(regularUnpaid.map((row) => String(field(row, 'id', 'ID', 'class_id') || '')));
    expect(tutoringIds.size).toBe(activeTutoring.length);
    expect([...tutoringIds].every(Boolean), 'tutoring paired control must expose stable class IDs').toBe(true);
    for (const row of activeTutoring) {
      const id = String(field(row, 'id', 'ID', 'class_id') || '');
      if (id) expect(alertIds.has(id), `tutoring course ${id} leaked into alerts`).toBe(false);
    }
    if (regularUnpaid.length > 0) {
      expect([...alertIds].some((id) => regularIds.has(id)), 'regular unpaid paired control should remain visible').toBe(true);
    }

    const aging = await getJson(request, `/api/v1/finance/ar-aging?branch_id=${BRANCH_ID}`, token);
    expect(aging).toHaveProperty('totals');
    expect(Number(aging.totals.grand_total || 0), 'AR total must equal regular unpaid control only').toBe(regularOutstanding);
    const agingByStudent = new Map((Array.isArray(aging.students) ? aging.students : []).map((row) => [String(row.student_id), Number(row.total || 0)]));
    const expectedByStudent = new Map();
    for (const row of regularUnpaid) {
      const student = String(field(row, 'StudentID', 'student_id') || '');
      expectedByStudent.set(student, (expectedByStudent.get(student) || 0) + Math.max(0, amount(row, 'Charge', 'charge') - amount(row, 'Pay', 'paid')));
    }
    for (const [student, expected] of expectedByStudent) {
      expect(agingByStudent.get(student), `regular student ${student} paired AR control`).toBe(expected);
    }
    for (const [student, total] of agingByStudent) {
      expect(total).toBe(expectedByStudent.get(student) || 0);
    }
    if (tutoringOutstanding > 0) {
      expect(Number(aging.totals.grand_total || 0)).not.toBe(regularOutstanding + tutoringOutstanding);
    }

    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);
    await page.getByRole('button', { name: '學生管理', exact: true }).click();
    await expect(page.getByRole('heading', { name: /學生管理/ }).first()).toBeVisible({ timeout: 20_000 });
    const studentId = field(courses.find((course) => field(course, 'StudentID', 'student_id')), 'StudentID', 'student_id');
    expect(studentId, 'production course data must provide a non-PII student key').toBeTruthy();
    const row = page.locator(`tr.student-row[data-student-id="${String(studentId)}"]`);
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.locator('.btn-course-disclosure').click();
    await page.getByRole('button', { name: '新增課程', exact: true }).first().click();
    const scheduler = page.locator('.scheduler-layout');
    await expect(scheduler).toBeVisible({ timeout: 15_000 });
    const type = scheduler.locator('select').filter({ has: scheduler.locator('option[value="tutoring"]') }).first();
    await type.selectOption('tutoring');
    await scheduler.locator('select').filter({ has: scheduler.locator('option[value="session"]') }).first().selectOption('session');
    await scheduler.locator('select').filter({ has: scheduler.locator('option[value="auto_recurrence"]') }).first().selectOption('auto_recurrence');
    await scheduler.locator('input[type="number"]').first().fill('1');
    await scheduler.locator('input[type="date"]').first().fill(new Date().toISOString().slice(0, 10));
    await expect(scheduler).toContainText('輔導課免費，不需填金額，也不會產生應收帳款。');
    await expect(scheduler.locator('label').filter({ hasText: /單堂費用|每小時費用/ })).toHaveCount(0);
    await expect(scheduler.locator('label').filter({ hasText: '繳費日期' })).toHaveCount(0);
    await expect(scheduler.locator('input[id^="course-payment-date-"]')).toHaveCount(0);
    await expect(scheduler.locator('label').filter({ hasText: /單堂費用|每小時費用/ }).locator('..').locator('input')).toHaveCount(0);
    const relevantControls = await scheduler.locator('input, select, textarea').evaluateAll((controls) => controls
      .filter((control) => /金額|單堂費用|每小時費用|繳費日期|付款日期/.test(control.closest('.form-group')?.textContent || ''))
      .map((control) => ({ required: control.required, ariaRequired: control.getAttribute('aria-required'), valid: control.checkValidity() })));
    expect(relevantControls, 'tutoring must not render receivable controls').toEqual([]);
    const controls = scheduler.locator('input, select, textarea');
    await expect(controls).not.toHaveCount(0);
    const invalidRequired = await controls.evaluateAll((elements) => elements
      .filter((element) => element.required && !element.checkValidity())
      .map((element) => element.tagName));
    expect(invalidRequired, 'visible required scheduler controls must be valid').toEqual([]);
    const readinessButton = scheduler.getByRole('button', { name: '建立課程並寫入堂次', exact: true });
    await expect(readinessButton).toBeVisible();
    await expect(readinessButton).toBeEnabled();
    await expect(readinessButton).toHaveAttribute('type', 'button');
    await page.evaluate(() => { window.__schedulerSubmitProbe = true; });
    await readinessButton.click();
    await expect.poll(() => schedulerCreateIntercepted).toBe(true);
    expect(schedulerCreatePayload).toEqual(expect.objectContaining({ class_type: 'tutoring', payment_type: 'session' }));
    await page.evaluate(() => { window.__schedulerSubmitProbe = false; });
    await expect(scheduler).not.toContainText(/金額.*必填|付款.*必填|繳費.*必填/);
    await page.getByRole('button', { name: '取消', exact: true }).last().click();
    expect(await page.evaluate(() => window.__printGuardSelfTest)).toBe(true);
    expect(await page.evaluate(() => window.__printAttempts)).toBe(0);
    expect(await page.evaluate(() => window.__originalPrintCalls)).toBe(0);
    expect(await page.evaluate(() => window.__writeGuardSelfTests)).toEqual({ form: true, submit: true, requestSubmit: true, beacon: true });
    expect(await page.evaluate(() => window.__readOnlyViolations)).toEqual([]);

    expect(unsafe, `non-read-only requests observed: ${JSON.stringify(unsafe)}`).toEqual([]);
    expect(telemetry.length, 'at least one real adoption event must be observed').toBeGreaterThan(0);
  });
});
