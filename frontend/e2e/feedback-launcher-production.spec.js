// @ts-check
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const BASE = process.env.SMOKE_BASE_URL;
const HOSTED = process.env.SMOKE_FEEDBACK_HOSTED === '1';
const BRANCH_ID = Number(process.env.SMOKE_BRANCH_ID || 0);

function readSession() {
  const encoded = process.env.SMOKE_DIRECTOR_SESSION_B64 || '';
  if (!encoded) return null;
  try {
    const value = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
    return value && typeof value === 'object' ? value : null;
  } catch {
    return null;
  }
}

const SESSION = readSession();
const ROLE = SESSION?.user?.role;
const CONTROLLED_SESSION = Boolean(
  SESSION?.access_token
  && ['director', 'super_admin'].includes(ROLE)
  && BRANCH_ID > 0,
);

const GUIDANCE = {
  bug: {
    label: '使用上有問題',
    placeholder: '例如：我在「出缺勤」按下儲存後，畫面沒有更新。',
  },
  ux: {
    label: '希望更好用',
    placeholder: '例如：我每天要重複找同一位學生，希望能更快找到。',
  },
  feature: {
    label: '想要新功能',
    placeholder: '例如：希望可以依月份查看每位老師的授課統計。',
  },
};

test.use({
  serviceWorkers: 'block',
  trace: 'off',
  screenshot: 'off',
  video: 'off',
});

function installSyntheticProfile(page) {
  return page.addInitScript(({ session, branch }) => {
    // Preserve only the bearer token and authorization shape; never expose the
    // workflow's real user name/account in the browser UI or artifacts.
    const safe = {
      ...session,
      user: {
        id: 900329,
        role: session.user.role,
        name: 'Acceptance Director',
        account: 'acceptance-director',
        campuses: [branch],
      },
    };
    localStorage.setItem('alltrue_session', JSON.stringify(safe));
    localStorage.setItem('app_branch', String(branch));
    sessionStorage.setItem('alltrue_brand_intro_seen_token', String(session.access_token || ''));
    window.__feedbackAcceptance = { blocked: [], unexpectedWrites: [], telemetry: [] };

    const safeMethods = new Set(['GET', 'HEAD', 'OPTIONS']);
    const telemetryPath = '/api/v1/adoption/events';
    const write = (channel, method, url) => {
      window.__feedbackAcceptance.blocked.push({ channel, method: String(method).toUpperCase() });
      throw new Error(`feedback acceptance blocked ${method} ${url}`);
    };
    const isTelemetry = (url, method) => method === 'POST'
      && new URL(String(url), window.location.href).pathname === telemetryPath;

    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
      const method = String(init.method || input?.method || 'GET').toUpperCase();
      const url = typeof input === 'string' ? input : input?.url || '';
      if (!safeMethods.has(method) && !isTelemetry(url, method)) return write('fetch', method, url);
      return nativeFetch(input, init);
    };
    const nativeOpen = XMLHttpRequest.prototype.open;
    const nativeSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function guardedOpen(method, url, ...args) {
      this.__feedbackMethod = String(method || 'GET').toUpperCase();
      this.__feedbackUrl = String(url || '');
      return nativeOpen.call(this, method, url, ...args);
    };
    XMLHttpRequest.prototype.send = function guardedSend(...args) {
      const method = this.__feedbackMethod || 'GET';
      const url = this.__feedbackUrl || '';
      if (!safeMethods.has(method) && !isTelemetry(url, method)) return write('xhr', method, url);
      return nativeSend.apply(this, args);
    };
    HTMLFormElement.prototype.submit = function guardedSubmit() {
      return write('form-submit', 'POST', this.action || window.location.href);
    };
    HTMLFormElement.prototype.requestSubmit = function guardedRequestSubmit() {
      return write('form-request-submit', 'POST', this.action || window.location.href);
    };
    navigator.sendBeacon = (url) => {
      write('sendBeacon', 'POST', url);
      return false;
    };
  }, { session: SESSION, branch: BRANCH_ID });
}

function installReadOnlyRoutes(page) {
  return page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const method = request.method().toUpperCase();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      if (method === 'POST' && url.pathname === '/api/v1/adoption/events') {
        const raw = request.postData() || '';
        expect(raw).not.toMatch(/bearer|access_token|password|@/i);
        const payload = (() => { try { return JSON.parse(raw); } catch { return null; } })();
        if (payload) {
          expect(payload).toEqual(expect.objectContaining({ event: expect.any(String) }));
        }
        await route.fulfill({ status: 204, body: '' });
        return;
      }
      await route.abort('blockedbyclient');
      return;
    }
    if (/\/api\/v1\/(students|teachers|student-classes|courses|subjects|bugs|notifications)(?:\/|$)/.test(url.pathname)) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return;
    }
    await route.continue();
  });
}

async function assertSyntheticGuards(page) {
  await page.evaluate(async () => {
    const attempts = [
      async () => { try { await fetch('/api/v1/__feedback_write__', { method: 'POST' }); } catch (_) {} },
      async () => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', '/api/v1/__feedback_write__');
        try { xhr.send('{}'); } catch (_) {}
      },
      async () => {
        const form = document.createElement('form');
        form.action = '/api/v1/__feedback_write__';
        document.body.append(form);
        try { form.requestSubmit(); } catch (_) {}
      },
      async () => { try { navigator.sendBeacon('/api/v1/__feedback_write__', '{}'); } catch (_) {} },
    ];
    for (const attempt of attempts) await attempt();
  });
  const state = await page.evaluate(() => window.__feedbackAcceptance);
  expect(state.unexpectedWrites).toEqual([]);
  expect(new Set(state.blocked.map((entry) => entry.channel))).toEqual(new Set(['fetch', 'xhr', 'form-request-submit', 'sendBeacon']));
}

async function assertFeedbackChoices(page, viewport) {
  await page.setViewportSize(viewport);
  await installSyntheticProfile(page);
  await installReadOnlyRoutes(page);
  await page.goto(`${BASE}/`);
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 20_000 });
  await dismissOverlays(page);

  const launcher = page.locator('.bug-launcher');
  await expect(launcher.locator('.fab')).toBeVisible({ timeout: 20_000 });
  await launcher.locator('.fab').click();
  const dialog = page.locator('.bug-report-dialog');
  await expect(dialog).toBeVisible();
  const description = dialog.locator('#bug-report-description');

  for (const [value, expected] of Object.entries(GUIDANCE)) {
    const radio = dialog.locator(`input[type="radio"][value="${value}"]`);
    await radio.check();
    await expect(radio).toBeChecked();
    await expect(dialog.locator('.feedback-type-option.selected strong')).toHaveText(expected.label);
    await expect(description).toHaveAttribute('placeholder', expected.placeholder);
  }

  await dialog.getByRole('button', { name: '取消', exact: true }).click();
  await expect(dialog).toBeHidden();
  await assertSyntheticGuards(page);
  const unexpectedWrites = await page.evaluate(() => window.__feedbackAcceptance.unexpectedWrites);
  expect(unexpectedWrites).toEqual([]);
}

test.describe('production acceptance — feedback launcher (#329)', () => {
  test.skip(!HOSTED, 'set SMOKE_FEEDBACK_HOSTED=1 only in the protected hosted acceptance phase');

  test('desktop exposes reporter-facing choices without writes', async ({ page }) => {
    expect(BASE, 'SMOKE_BASE_URL is required when hosted acceptance is enabled').toBeTruthy();
    expect(CONTROLLED_SESSION, 'hosted acceptance requires a valid director session; malformed session must fail').toBe(true);
    await assertFeedbackChoices(page, { width: 1440, height: 1000 });
  });

  test('mobile exposes reporter-facing choices without writes', async ({ page }) => {
    expect(BASE, 'SMOKE_BASE_URL is required when hosted acceptance is enabled').toBeTruthy();
    expect(CONTROLLED_SESSION, 'hosted acceptance requires a valid director session; malformed session must fail').toBe(true);
    await assertFeedbackChoices(page, { width: 390, height: 844 });
  });
});
