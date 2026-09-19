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
const REQUIRE_HOSTED = process.env.SMOKE_REQUIRE_SCHOOL_DIRECTORY_ACCEPTANCE === 'true';
const RAW_BRANCH_ID = process.env.SMOKE_BRANCH_ID || '';
const HAS_REQUESTED_BRANCH = RAW_BRANCH_ID !== '';
const REQUESTED_BRANCH_ID = /^\d+$/.test(RAW_BRANCH_ID)
  && Number(RAW_BRANCH_ID) > 0
  && String(Number(RAW_BRANCH_ID)) === RAW_BRANCH_ID
  ? Number(RAW_BRANCH_ID)
  : 0;
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
  && SESSION.user.campuses.every((id) => Number.isInteger(id) && id > 0)
  ? SESSION.user.campuses
  : null;
const BRANCH_ID = HAS_REQUESTED_BRANCH ? REQUESTED_BRANCH_ID : (CAMPUS_IDS?.[0] || 0);

function expiryMs(value) {
  if (typeof value !== 'number' && typeof value !== 'string') return 0;
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
function isControlledSession(session, branchId) {
  const role = session?.user?.role;
  const campuses = session?.user?.campuses;
  const expires = expiryMs(session?.expires_at);
  const remaining = Math.floor((expires - Date.now()) / 1000);
  return Boolean(
    typeof session?.access_token === 'string'
    && session.access_token.trim() !== ''
    && session.token_type === 'Bearer'
    && ['director', 'super_admin'].includes(role)
    && Number.isInteger(branchId)
    && branchId > 0
    && Array.isArray(campuses)
    && campuses.every((id) => Number.isInteger(id) && id > 0)
    && (role === 'super_admin' || campuses.includes(branchId))
    && session.user.must_change_password === false
    && remaining > 0
    && remaining <= 30 * 60,
  );
}

const CONTROLLED_SESSION = isControlledSession(SESSION, BRANCH_ID);
const BROWSER_SESSION = CONTROLLED_SESSION ? {
  access_token: SESSION.access_token,
  token_type: 'Bearer',
  expires_at: SESSION.expires_at,
  user: { id: -296, role: ROLE, campuses: CAMPUS_IDS, must_change_password: false },
} : null;

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

function assertTelemetryPayload(payload) {
  expect(payload && typeof payload === 'object' && !Array.isArray(payload)).toBe(true);
  expect(Object.keys(payload).sort()).toEqual(['branch_id', 'event', 'meta']);
  expect(payload.branch_id).toBe(BRANCH_ID);
  expect(payload.event).toBe('dashboard_opened');
  expect(payload.meta && typeof payload.meta === 'object' && !Array.isArray(payload.meta)).toBe(true);
  expect(Object.keys(payload.meta).sort()).toEqual(['page', 'role', 'telem_day', 'telem_session']);
  expect(payload.meta.role).toBe('director');
  expect(payload.meta.page).toBe('director-dashboard');
  expect(payload.meta.telem_day).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(payload.meta.telem_session).toMatch(/^t_[a-z0-9_]+$/);
}

function installWriteGuard(page) {
  return page.addInitScript(() => {
    const state = { mode: 'runtime', selfTestBlocks: [], runtimeBlocks: [] };
    window.__schoolDirectoryAcceptance = state;
    const allowed = new Set(['GET', 'HEAD', 'OPTIONS']);
    const isTelemetry = (url, method) => {
      const target = new URL(String(url), window.location.href);
      return method === 'POST'
        && target.origin === window.location.origin
        && target.pathname === '/api/v1/adoption/events';
    };
    const block = (channel, method, url) => {
      const target = state.mode === 'self-test' ? state.selfTestBlocks : state.runtimeBlocks;
      target.push(channel);
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
    document.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      const action = event.target instanceof HTMLFormElement ? event.target.action : window.location.href;
      block('form-event', 'SUBMIT', action);
    }, true);
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
  }, { session: BROWSER_SESSION, branch: BRANCH_ID, release: RELEASE });
}

async function assertWriteGuardSelfTest(page) {
  await page.evaluate(async () => {
    const state = window.__schoolDirectoryAcceptance;
    state.mode = 'self-test';
    state.selfTestBlocks = [];
    const synthetic = [
      async () => { try { await fetch('/api/v1/__school_directory_acceptance_write__', { method: 'POST' }); } catch (_) {} },
      async () => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', '/api/v1/__school_directory_acceptance_write__');
        try { xhr.send('{}'); } catch (_) {}
      },
      async () => { const form = document.createElement('form'); form.action = '/api/v1/__school_directory_acceptance_write__'; document.body.append(form); try { form.requestSubmit(); } catch (_) {} },
      async () => { const form = document.createElement('form'); form.action = '/api/v1/__school_directory_acceptance_write__'; document.body.append(form); try { form.submit(); } catch (_) {} },
      async () => {
        const form = document.createElement('form');
        const button = document.createElement('button');
        button.type = 'submit';
        form.action = '/api/v1/__school_directory_acceptance_write__';
        form.append(button);
        document.body.append(form);
        try { button.click(); } catch (_) {}
      },
      async () => { try { navigator.sendBeacon('/api/v1/__school_directory_acceptance_write__', '{}'); } catch (_) {} },
      async () => { try { window.print(); } catch (_) {} },
    ];
    for (const attempt of synthetic) {
      await attempt();
    }
    state.mode = 'runtime';
  });
  const state = await page.evaluate(() => window.__schoolDirectoryAcceptance);
  expect(new Set(state.selfTestBlocks)).toEqual(new Set([
    'fetch',
    'xhr',
    'form-request-submit',
    'form-submit',
    'form-event',
    'sendBeacon',
    'print',
  ]));
}

test('session gate rejects malformed production credentials', () => {
  const valid = {
    access_token: 'synthetic-token',
    token_type: 'Bearer',
    expires_at: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
    user: { role: 'director', campuses: [1], must_change_password: false },
  };
  expect(isControlledSession({ ...valid, user: { ...valid.user } }, 1)).toBe(true);
  expect(isControlledSession({ ...valid, user: { role: 'director', campuses: [1] } }, 1)).toBe(false);
  expect(isControlledSession({ ...valid, user: { ...valid.user, campuses: ['1'] } }, 1)).toBe(false);
  expect(isControlledSession({ ...valid, access_token: '' }, 1)).toBe(false);
  expect(isControlledSession({ ...valid, token_type: 'Basic' }, 1)).toBe(false);
  expect(isControlledSession(valid, 0)).toBe(false);
});

test.describe('production acceptance — school directory (#296)', () => {
  test.skip(!REQUIRE_HOSTED && (!BASE || !CONTROLLED_SESSION), 'missing bounded SMOKE_BASE_URL/branch/director session (must expire within 30m)');

  test.beforeAll(() => {
    if (!REQUIRE_HOSTED) return;
    expect(BASE, 'hosted acceptance requires SMOKE_BASE_URL').toBeTruthy();
    expect(CONTROLLED_SESSION, 'hosted acceptance requires a valid director/super-admin session expiring within 30m').toBe(true);
  });

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
      if (url.origin === new URL(BASE).origin && url.pathname === '/api/v1/me') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            id: -296,
            name: '受控驗證帳號',
            email: '',
            phone: '',
            avatar_url: '',
            role: ROLE,
            campuses: CAMPUS_IDS,
            must_change_password: false,
            engagement: null,
          }),
        });
        return;
      }
      if (url.origin === new URL(BASE).origin && url.pathname === '/api/v1/schools') {
        const school = {
          id: 'tpe-daan-jh',
          canonical_name: '臺北市立大安國民中學',
          municipality: '臺北市',
          district: '大安區',
          school_code: '313501',
          label: '臺北市立大安國民中學（臺北市 大安區）',
          matched_alias: '大安國中',
        };
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: url.searchParams.get('q') === '大安國中' ? [school] : [] }),
        });
        return;
      }
      if (url.origin === new URL(BASE).origin && url.pathname.startsWith('/api/v1/')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [] }),
        });
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
    expect(daan).toMatchObject({
      id: 'tpe-daan-jh',
      label: '臺北市立大安國民中學（臺北市 大安區）',
      municipality: '臺北市',
      district: '大安區',
      school_code: '313501',
      matched_alias: '大安國中',
    });

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
    await page.goto(`${BASE}/?app_page=students`);
    await assertWriteGuardSelfTest(page);
    await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
    await dismissOverlays(page);

    await expect(page.getByRole('button', { name: '學生管理', exact: true })).toHaveClass(/active/);
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
    const writeGuardState = await page.evaluate(() => window.__schoolDirectoryAcceptance);
    expect(writeGuardState.runtimeBlocks).toEqual([]);
    expect(blockedNetworkWrites).toEqual([]);
    expect(interceptedTelemetry.every((payload) => payload.branch_id === BRANCH_ID)).toBe(true);
  });
});
