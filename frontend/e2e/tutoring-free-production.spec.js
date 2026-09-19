// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

/** Authenticated, read-only production acceptance for In-App #325. */
const BASE = process.env.SMOKE_BASE_URL;
const BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 16);
const RELEASE = latestReleaseVersionForRole('director');

function readSession() {
  const raw = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!raw) return null;
  try { return JSON.parse(Buffer.from(raw, 'base64').toString('utf8')); } catch { return null; }
}

const SESSION = readSession();

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

async function getJson(request, path, token) {
  const response = await request.get(`${BASE}${path}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(response.ok(), `${path} returned ${response.status()}`).toBeTruthy();
  return response.json();
}

test.describe('production acceptance — tutoring free/non-receivable', () => {
  test('director read-only API and real UI acceptance', async ({ page, request }) => {
    test.skip(!BASE || !SESSION?.access_token, 'missing controlled production director session');
    const token = SESSION.access_token;
    const unsafe = [];
    const telemetry = [];

    await page.addInitScript(({ session, branch, release }) => {
      localStorage.setItem('alltrue_session', JSON.stringify(session));
      localStorage.setItem('app_branch', String(branch));
      localStorage.setItem('alltrue_release_notes_seen', release);
      sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
      window.print = () => { throw new Error('window.print forbidden in read-only acceptance'); };
    }, { session: SESSION, branch: BRANCH_ID, release: RELEASE });

    page.on('request', (req) => {
      const method = req.method();
      const path = new URL(req.url()).pathname;
      if (method === 'POST' && path === '/api/v1/adoption/events') return;
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) unsafe.push({ method, path });
    });
    await page.route('**/api/v1/adoption/events', async (route) => {
      const req = route.request();
      expect(req.method()).toBe('POST');
      const body = req.postDataJSON() || {};
      expect(JSON.stringify(body)).not.toMatch(/phone|email|name|token|student_id|class_id|invoice|amount|charge|pay/i);
      telemetry.push(body);
      await route.fulfill({ status: 204, body: '' });
    });

    const courses = rows(await getJson(request, `/api/v1/student-classes?branch_id=${BRANCH_ID}&per_page=2000`, token));
    const tutoring = courses.filter(isTutoring);
    const regularUnpaid = courses.filter((row) => !isTutoring(row) && Number(field(row, 'Paid', 'paid') || 0) === 0);
    const regularPaid = courses.filter((row) => !isTutoring(row) && Number(field(row, 'Paid', 'paid') || 0) === 1);
    const alerts = rows(await getJson(request, `/api/v1/alerts/tuition?branch_id=${BRANCH_ID}`, token));
    const alertIds = new Set(alerts.map((row) => String(field(row, 'id', 'class_id', 'student_class_id', 'StudentClassID') || '')));
    for (const row of tutoring) {
      const id = String(field(row, 'id', 'ID', 'class_id') || '');
      if (id) expect(alertIds.has(id), `tutoring course ${id} leaked into alerts`).toBe(false);
    }
    if (regularUnpaid.length > 0) {
      expect(alerts.length, 'regular unpaid production control should remain visible when present').toBeGreaterThan(0);
    }

    const aging = await getJson(request, `/api/v1/finance/ar-aging?branch_id=${BRANCH_ID}`, token);
    expect(aging).toHaveProperty('totals');
    expect(regularPaid.length + regularUnpaid.length + tutoring.length).toBe(courses.length);

    await page.goto('/');
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);
    const studentsNav = page.getByRole('button', { name: '學生管理', exact: true }).first();
    if (await studentsNav.count()) await studentsNav.click();
    await expect(page.getByRole('heading', { name: /學生管理/ }).first()).toBeVisible({ timeout: 20_000 });
    const row = page.locator('tr.student-row').first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.locator('.btn-course-disclosure').click();
    await page.getByRole('button', { name: '新增課程', exact: true }).first().click();
    const scheduler = page.locator('.scheduler-layout');
    await expect(scheduler).toBeVisible({ timeout: 15_000 });
    const type = scheduler.locator('select').filter({ has: scheduler.locator('option[value="tutoring"]') }).first();
    await type.selectOption('tutoring');
    await expect(scheduler).toContainText('輔導課免費，不需填金額，也不會產生應收帳款。');
    await expect(scheduler.locator('label').filter({ hasText: /單堂費用|每小時費用/ })).toHaveCount(0);
    await expect(scheduler.locator('label').filter({ hasText: '繳費日期' })).toHaveCount(0);
    await page.getByRole('button', { name: '取消', exact: true }).last().click();

    expect(unsafe, `non-read-only requests observed: ${JSON.stringify(unsafe)}`).toEqual([]);
    expect(telemetry.every((event) => event.meta && !event.meta.name && !event.meta.student_id)).toBe(true);
  });
});
