// @ts-check
import { test, expect } from '@playwright/test';
import { latestReleaseVersionForRole } from '../src/lib/releaseNotes.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

/**
 * Bounded, read-only production acceptance for in-app #296 / PR #3015.
 * The protected workflow supplies a short-lived director session.  This spec
 * deliberately does not create, edit, delete, or submit a student.
 */
const BASE = process.env.SMOKE_BASE_URL;
const REQUESTED_BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 0);
const RELEASE = latestReleaseVersionForRole('director');

test.use({ trace: 'off', screenshot: 'off', video: 'off', serviceWorkers: 'block' });

function readSession() {
  const encoded = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!encoded) return null;
  try {
    const session = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
    return session && typeof session === 'object' ? session : null;
  } catch {
    return null;
  }
}

const SESSION = readSession();
const ROLE = SESSION?.user?.role;
const CAMPUS_IDS = Array.isArray(SESSION?.user?.campuses)
  ? SESSION.user.campuses.map(Number).filter(Number.isInteger)
  : [];
const BRANCH_ID = REQUESTED_BRANCH_ID || CAMPUS_IDS[0] || 0;

function expiryMs(value) {
  const numeric = Number(value);
  if (Number.isFinite(numeric) && numeric > 0) {
    return numeric > 1e12 ? numeric : numeric * 1000;
  }
  const text = String(value || '');
  if (!/(?:Z|[+-]\d{2}:\d{2})$/i.test(text)) return 0;
  const parsed = Date.parse(text);
  return Number.isFinite(parsed) ? parsed : 0;
}

const SESSION_REMAINING_SECONDS = Math.floor((expiryMs(SESSION?.expires_at) - Date.now()) / 1000);
const CONTROLLED_SESSION = Boolean(
  SESSION?.access_token
  && ['director', 'super_admin'].includes(ROLE)
  && BRANCH_ID > 0
  && CAMPUS_IDS.includes(BRANCH_ID)
  && SESSION_REMAINING_SECONDS > 0
  && SESSION_REMAINING_SECONDS <= 30 * 60,
);

const ITEM_KEYS = [
  'canonical_name',
  'district',
  'id',
  'label',
  'matched_alias',
  'municipality',
  'school_code',
].sort();

function authHeaders() {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${SESSION.access_token}`,
  };
}

function assertDirectoryResponse(json, expectedLimit = 12) {
  expect(json && typeof json === 'object' && !Array.isArray(json)).toBe(true);
  expect(Object.keys(json).sort()).toEqual(['data']);
  expect(Array.isArray(json.data)).toBe(true);
  expect(json.data.length).toBeLessThanOrEqual(expectedLimit);
  for (const item of json.data) {
    expect(Object.keys(item).sort()).toEqual(ITEM_KEYS);
    expect(typeof item.id).toBe('string');
    expect(item.id.trim()).not.toBe('');
    expect(typeof item.canonical_name).toBe('string');
    expect(item.canonical_name.trim()).not.toBe('');
    expect(typeof item.municipality).toBe('string');
    expect(item.municipality.trim()).not.toBe('');
    expect(typeof item.label).toBe('string');
    expect(item.label.trim()).not.toBe('');
    expect(item.district === null || typeof item.district === 'string').toBe(true);
    expect(item.school_code === null || typeof item.school_code === 'string').toBe(true);
    expect(item.matched_alias === null || typeof item.matched_alias === 'string').toBe(true);
  }
}

function containsForbiddenTelemetry(value) {
  const forbiddenKey = /(phone|email|password|token|name|body|note|address|line[_-]?id|student[_-]?id|class[_-]?id|invoice|receipt|amount|charge|pay)/i;
  if (Array.isArray(value)) {
    return value.length > 40 || value.some(containsForbiddenTelemetry);
  }
  if (typeof value === 'string') {
    return value.length > 160 || /@|\b\d{7,}\b|^Bearer\s|^eyJ/i.test(value);
  }
  if (!value || typeof value !== 'object') return false;
  return Object.entries(value).some(([key, item]) => forbiddenKey.test(key) || containsForbiddenTelemetry(item));
}

function assertTelemetryPayload(payload) {
  expect(payload && typeof payload === 'object' && !Array.isArray(payload)).toBe(true);
  expect(Object.keys(payload).sort()).toEqual(['branch_id', 'event', 'meta']);
  expect(payload.branch_id).toBe(BRANCH_ID);
  expect(typeof payload.event).toBe('string');
  expect(payload.event).toMatch(/^[a-z0-9_]+$/);
  expect(payload.meta && typeof payload.meta === 'object' && !Array.isArray(payload.meta)).toBe(true);
  expect(Object.keys(payload.meta).length).toBeLessThanOrEqual(20);
  for (const [key, value] of Object.entries(payload.meta)) {
    expect(key).toMatch(/^[a-z0-9_]+$/);
    const scalar = value === null || ['string', 'number', 'boolean'].includes(typeof value);
    const boundedArray = Array.isArray(value)
      && value.length <= 40
      && value.every((item) => ['string', 'number', 'boolean'].includes(typeof item));
    expect(scalar || boundedArray).toBe(true);
  }
  expect(containsForbiddenTelemetry(payload)).toBe(false);
}

function installWriteGuard(page) {
  return page.addInitScript(() => {
    const state = { unknownWrites: [], blocked: [] };
    window.__schoolDirectoryAcceptance = state;
    const allowed = new Set(['GET', 'HEAD', 'OPTIONS']);
    const isTelemetry = (url, method) => method === 'POST'
      && new URL(String(url), window.location.href).pathname === '/api/v1/adoption/events';
    const block = (channel, method, url) => {
      state.blocked.push(channel);
      throw new Error(`school-directory acceptance blocked ${method} ${url}`);
    };

    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
      const method = String(init.method || input?.method || 'GET').toUpperCase();
      const url = typeof input === 'string' ? input : input?.url || '';
      if (!allowed.has(method) && !isTelemetry(url, method)) return block('fetch', method, url);
      return originalFetch(input, init);
    };

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function guardedOpen(method, url, ...args) {
      this.__schoolDirectoryMethod = String(method || 'GET').toUpperCase();
      this.__schoolDirectoryUrl = String(url || '');
      return originalOpen.call(this, method, url, ...args);
    };
    XMLHttpRequest.prototype.send = function guardedSend(...args) {
      const method = this.__schoolDirectoryMethod || 'GET';
      const url = this.__schoolDirectoryUrl || '';
      if (!allowed.has(method) && !isTelemetry(url, method)) return block('xhr', method, url);
      return originalSend.apply(this, args);
    };

    HTMLFormElement.prototype.submit = function guardedSubmit() {
      return block('form-submit', 'POST', this.action || window.location.href);
    };
    HTMLFormElement.prototype.requestSubmit = function guardedRequestSubmit() {
      return block('form-request-submit', 'POST', this.action || window.location.href);
    };
    const originalBeacon = navigator.sendBeacon?.bind(navigator);
    navigator.sendBeacon = (url, data) => {
      block('sendBeacon', 'POST', url);
      return false;
    };
    window.print = () => block('print', 'PRINT', window.location.href);
    void originalBeacon;
  });
}

async function installSession(page) {
  await page.addInitScript(({ session, branch, release }) => {
    localStorage.setItem('alltrue_session', JSON.stringify(session));
    localStorage.setItem('app_branch', String(branch));
    localStorage.setItem('alltrue_release_notes_seen', release);
    sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
  }, { session: SESSION, branch: BRANCH_ID, release: RELEASE });
}

async function assertNoUnknownWrites(page) {
  await page.evaluate(() => {
    const synthetic = [
      () => fetch('/api/v1/__school_directory_acceptance_write__', { method: 'POST' }),
      () => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', '/api/v1/__school_directory_acceptance_write__');
        xhr.send('{}');
      },
      () => { const form = document.createElement('form'); form.action = '/api/v1/__school_directory_acceptance_write__'; document.body.append(form); form.requestSubmit(); },
      () => navigator.sendBeacon('/api/v1/__school_directory_acceptance_write__', '{}'),
      () => window.print(),
    ];
    for (const attempt of synthetic) {
      try { void attempt(); } catch (_) { /* expected guard */ }
    }
  });
  const state = await page.evaluate(() => window.__schoolDirectoryAcceptance);
  expect(state.unknownWrites).toEqual([]);
  expect(new Set(state.blocked)).toEqual(new Set(['fetch', 'xhr', 'form-request-submit', 'sendBeacon', 'print']));
}

test.describe('production acceptance — school directory (#296)', () => {
  test.skip(!BASE || !CONTROLLED_SESSION, 'missing bounded SMOKE_BASE_URL/branch/director session (must expire within 30m)');

  test('API contract and student school picker are read-only', async ({ page, request, context }) => {
    const blockedNetworkWrites = [];
    const interceptedTelemetry = [];
    await context.route('**/*', async (route) => {
      const networkRequest = route.request();
      const method = networkRequest.method().toUpperCase();
      const url = new URL(networkRequest.url());
      const isRead = ['GET', 'HEAD', 'OPTIONS'].includes(method);
      if (!isRead) {
        if (method === 'POST' && url.origin === new URL(BASE).origin && url.pathname === '/api/v1/adoption/events') {
          let payload = null;
          try { payload = networkRequest.postDataJSON(); } catch { /* assertion below reports invalid shape */ }
          assertTelemetryPayload(payload);
          interceptedTelemetry.push(payload);
          await route.fulfill({ status: 204, body: '' });
          return;
        }
        blockedNetworkWrites.push({ method, path: url.pathname });
        await route.abort('blockedbyclient');
        return;
      }
      if (/^\/api\/v1\/(students|teachers|student-classes|courses|subjects)(?:\/|$)/.test(url.pathname)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
        return;
      }
      await route.continue();
    });

    const guest = await request.get(`${BASE}/api/v1/schools?q=%E5%BB%BA%E4%B8%AD&limit=12`);
    expect(guest.status()).toBe(401);

    const empty = await request.get(`${BASE}/api/v1/schools?q=&limit=12`, { headers: authHeaders() });
    expect(empty.ok()).toBe(true);
    expect(await empty.json()).toEqual({ data: [] });

    const response = await request.get(`${BASE}/api/v1/schools?q=%E5%BB%BA%E4%B8%AD&limit=12`, { headers: authHeaders() });
    expect(response.ok()).toBe(true);
    const json = await response.json();
    assertDirectoryResponse(json);
    const national = json.data.find((item) => item.canonical_name === '臺北市立建國高級中學');
    expect(national).toBeTruthy();

    const daanResponse = await request.get(`${BASE}/api/v1/schools?q=%E5%A4%A7%E5%AE%89%E5%9C%8B%E4%B8%AD&limit=12`, { headers: authHeaders() });
    expect(daanResponse.ok()).toBe(true);
    const daanJson = await daanResponse.json();
    assertDirectoryResponse(daanJson);
    const daan = daanJson.data.find((item) => item.canonical_name === '臺北市立大安國民中學');
    expect(daan).toMatchObject({ municipality: '臺北市', district: '大安區', school_code: '313501', matched_alias: '大安國中' });

    const variantResponse = await request.get(`${BASE}/api/v1/schools?q=${encodeURIComponent('台北市立大安國中')}&limit=12`, { headers: authHeaders() });
    expect(variantResponse.ok()).toBe(true);
    const variantJson = await variantResponse.json();
    assertDirectoryResponse(variantJson);
    expect(variantJson.data[0]?.canonical_name).toBe('臺北市立大安國民中學');

    const unknownResponse = await request.get(`${BASE}/api/v1/schools?q=${encodeURIComponent('不存在的學校')}&limit=12`, { headers: authHeaders() });
    expect(unknownResponse.ok()).toBe(true);
    expect(await unknownResponse.json()).toEqual({ data: [] });

    await installWriteGuard(page);
    await installSession(page);
    await page.goto(`${BASE}/`);
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);

    await page.getByRole('button', { name: '學生管理', exact: true }).click();
    await expect(page.getByRole('heading', { name: '學生管理', exact: true })).toBeVisible({ timeout: 20_000 });
    await page.getByRole('button', { name: '新增學生', exact: true }).first().click();
    await expect(page.getByRole('heading', { name: '新增學生', exact: true })).toBeVisible();

    const school = page.locator('#student-school');
    await school.fill('大安國中');
    const listbox = page.locator('#student-school-list');
    await expect(listbox).toBeVisible({ timeout: 15_000 });
    const option = listbox.getByRole('option').filter({ hasText: '臺北市立大安國民中學' }).first();
    await expect(option).toBeVisible();
    await expect(option).toContainText('臺北市 大安區');
    await school.press('ArrowDown');
    await school.press('Enter');
    await expect(school).toHaveValue('臺北市立大安國民中學');

    await school.fill('自訂測試校名不送出');
    await expect(school).toHaveValue('自訂測試校名不送出');
    await expect(listbox.locator('.school-name-custom-hint')).toContainText('也可直接輸入自訂校名');
    await page.getByRole('button', { name: '取消', exact: true }).last().click();
    await expect(page.getByRole('heading', { name: '新增學生', exact: true })).toHaveCount(0);
    await assertNoUnknownWrites(page);
    expect(blockedNetworkWrites).toEqual([]);
    expect(interceptedTelemetry.every((payload) => payload.branch_id === BRANCH_ID)).toBe(true);
  });
});
