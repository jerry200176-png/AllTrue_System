// @ts-check
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { appOriginOf, installReadOnlyAppGuard } from './fixtures/readOnlyAppGuard.js';

/** #4237 deployed UI verification. All business APIs are synthetic, locally fulfilled fixtures. */
test.use({ serviceWorkers: 'block', trace: 'off', screenshot: 'off', video: 'off' });
const LOCAL = process.env.PAYMENT_CONFLICT_LOCAL_FIXTURE === '1';
const BASE = LOCAL ? process.env.PAYMENT_CONFLICT_LOCAL_BASE_URL : process.env.SMOKE_BASE_URL;
const MERGE = 'bf737232c41fa9d064414862287a22f195e04dc4';
const SOURCE_SHA = createHash('sha256').update(readFileSync(fileURLToPath(import.meta.url))).digest('hex');
const rowBase = () => ({ id: 9001, student_id: 901, student_name: 'Synthetic fixture', subject: 'Synthetic subject',
  schedule_mode: 'count', payment_status: 'unpaid', alert_type: 'unpaid', charge: 8000, outstanding: 8000,
  paid_amount: 0, paid: false, stop: false, remaining_sessions: 8, payable_status: 'invoiced',
  payable_amount: 8000, payable_outstanding: 8000, payable_invoice_id: 902 });
const safe = { api_gets: 0, ledger_gets: 0, local_refusals: 0, business_post_upstream: 0,
  unexpected_business_requests: 0, confirm_reject_void_batch_attempts: 0, page_errors: 0 };
const set = (s, k) => { s[k] += 1; };
const unexpected = (receipt, method, path) => {
  set(receipt, 'unexpected_business_requests');
  receipt.unexpected_routes ||= [];
  receipt.unexpected_routes.push(`${method} ${path.split('/').map((part) => /^\d+$/.test(part) ? ':id' : part).join('/')}`);
};
const bodyKeys = ['amount','invoice_id','payment_date','payment_method','student_class_id'];
const noCache = { headers: { 'cache-control': 'no-cache' } };

async function identity(request) {
  if (LOCAL) return { frontend_sha: 'local-fixture', backend_sha: '' };
  const [v, d] = await Promise.all([
    request.get(`${BASE}/version.json`, noCache), request.get(`${BASE}/deployment.json`, noCache),
  ]);
  expect(v.ok(), 'version.json is served').toBeTruthy();
  expect(d.ok(), 'deployment.json is served').toBeTruthy();
  const version = await v.json(); const deployment = await d.json();
  const sha = String(version.build_sha || '');
  expect(sha).toMatch(/^[a-f0-9]{40}$/i);
  if (deployment.frontend_build_sha) expect(deployment.frontend_build_sha).toBe(sha);
  if (!LOCAL) {
    execFileSync('git', ['cat-file', '-e', `${MERGE}^{commit}`]);
    execFileSync('git', ['merge-base', '--is-ancestor', MERGE, sha]);
  }
  return { frontend_sha: sha, backend_sha: String(deployment.backend_sha || ''), built_at: String(version.built_at || '') };
}

async function app(page, request, kind) {
  test.skip(!BASE, 'requires configured target URL');
  const receipt = { ...safe, frontend_sha: '', backend_sha: '', source_sha256: SOURCE_SHA,
    run_id: process.env.GITHUB_RUN_ID || 'local', run_attempt: Number(process.env.GITHUB_RUN_ATTEMPT || 1),
    app_origin: appOriginOf(BASE), assets: [] };
  const before = await identity(request);
  Object.assign(receipt, before);
  const origin = appOriginOf(BASE);
  const blocked = await installReadOnlyAppGuard(page.context(), origin);
  const state = { pending: false, calls: [] };
  const exactGet = new Map();
  const register = (path, value, predicate = () => true) => exactGet.set(path, { value, predicate });
  register('/api/v1/me', { id: 900, name: 'Synthetic director', role: 'director', campuses: [16], must_change_password: false, capabilities: [] });
  register('/api/v1/branches', [{ id: 16, name: 'Synthetic campus', code: 'synthetic' }]);
  register('/api/v1/campuses', [{ id: 16, name: 'Synthetic campus', code: 'synthetic' }]);
  register('/api/v1/students', { data: [{ id: 901, name: 'Synthetic fixture', enable: 1, campus_id: 16 }], current_page: 1, last_page: 1, total: 1 });
  register('/api/v1/alerts/tuition', () => { const r = rowBase(); if (state.pending) Object.assign(r, { payment_status: 'pending_report', latest_payment_report_id: 903 }); return [r]; }, (u) => u.search === '?branch_id=16');
  register('/api/v1/accounting/ledger', () => ({ summary: {}, student: { id: 901, name: 'Synthetic fixture' }, scope: { student_class_id: 9001 },
    courses: [Object.assign(rowBase(), state.pending ? { payment_status: 'pending_report', latest_payment_report_id: 903 } : {})],
    receipts: state.pending ? [{ report_id: 903, student_class_id: 9001, status: 'pending', amount: 8000, payment_date: '2026-10-20', payment_method: 'cash' }] : [], anomalies: [],
    invoices: [{ id: 902, student_class_id: 9001, total_amount: 8000, outstanding_amount: 8000, calculated_applied_amount: 0, status: 'unpaid', payments: [] }] }),
    (u) => u.searchParams.get('branch_id') === '16' && (u.searchParams.get('student_class_id') === '9001' || u.searchParams.get('student_id') === '901'));
  for (const p of ['/api/v1/accounting/payments','/api/v1/accounting/settled-courses']) register(p, { data: [], summary: {} });
  register('/api/v1/accounting/monthly-drafts', { data: [] });
  register('/api/v1/accounting/contracts/9001/sessions', { sessions: [], memo: '' });
  register('/api/v1/notifications/unread-count', { unread_count: 0, urgent_unread_count: 0, by_type: {} });
  register('/api/v1/action-inbox/count', { notifications_unread: 0, cases_unresolved: 0, cases_overdue: 0, cases_due_soon: 0, cases_candidate_ready: 0, urgent_total: 0, badge_total: 0 });
  register('/api/v1/bugs/unread-badge', { unread_count: 0 });
  register('/api/v1/chat/unread-count', { unread_count: 0 });
  register('/api/v1/schedule-discrepancies/summary', { pending: 0 });
  register('/api/v1/me/unread-feedback-count', { count: 0 });
  const expected = kind === 'pending' || kind === 'nested' ? 'pending_report_exists' : 'amount_exceeds_outstanding';
  const amount = kind === '7600' ? 7600 : 0;
  await page.route('**/api/**', async (route) => {
    const req = route.request(); const url = new URL(req.url()); const method = req.method();
    if (url.origin !== origin) { unexpected(receipt, method, url.pathname); await route.abort(); return; }
    if (method === 'GET' && exactGet.has(url.pathname)) {
      const entry = exactGet.get(url.pathname);
      if (!entry.predicate(url)) { unexpected(receipt, method, url.pathname); await route.abort(); return; }
      set(receipt, 'api_gets'); if (url.pathname.endsWith('/ledger')) set(receipt, 'ledger_gets');
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(typeof entry.value === 'function' ? entry.value() : entry.value) }); return;
    }
    if (method === 'POST' && url.pathname === '/api/v1/payment-reports/director-record') {
      let b; try { b = req.postDataJSON(); } catch { b = null; }
      const keys = b && Object.keys(b).sort();
      const valid = JSON.stringify(keys) === JSON.stringify(bodyKeys) && b.student_class_id === 9001 && b.invoice_id === 902
        && b.amount === 8800 && b.payment_method === 'cash' && b.payment_date === new Date().toISOString().slice(0,10);
      const pendingCase = kind === 'pending' || kind === 'nested';
      if (!valid || receipt.local_refusals || (pendingCase ? expected !== 'pending_report_exists' : expected !== 'amount_exceeds_outstanding')) {
        unexpected(receipt, method, url.pathname); await route.abort(); return;
      }
      set(receipt, 'local_refusals'); if (pendingCase) state.pending = true;
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify(kind === 'pending'
        ? { code: expected, message: 'synthetic refusal', report_id: 903, reported_amount: 8000 }
        : { code: expected, message: 'synthetic refusal', expected_amount: amount }) }); return;
    }
    if (method !== 'GET' && /confirm|reject|void|batch/.test(url.pathname)) set(receipt, 'confirm_reject_void_batch_attempts');
    unexpected(receipt, method, url.pathname); await route.abort();
  });
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-director-token', user: { id: 900, role: 'director', campuses: [16], must_change_password: false } }));
    localStorage.setItem('app_branch', '16'); localStorage.setItem('alltrue_release_notes_seen', '1');
  });
  page.on('pageerror', () => set(receipt, 'page_errors'));
  page.on('response', async (res) => {
    const u = new URL(res.url()); if (u.origin !== origin || !['document','script','stylesheet'].includes(res.request().resourceType())) return;
    try { const bytes = await res.body(); receipt.assets.push({ path: u.pathname, type: res.headers()['content-type'] || '', sha256: createHash('sha256').update(bytes).digest('hex') }); } catch { /* response may be unavailable after navigation */ }
  });
  await page.goto(`${BASE}/?app_page=tuition-collect`);
  await page.getByRole('button', { name: /待處理清單/ }).click();
  if (kind === 'nested') {
    await page.getByRole('button', { name: /繳費明細/ }).click();
    await expect(page.locator('[data-testid="contract-card-9001"] [data-testid="contract-record"]')).toBeVisible({ timeout: 15000 });
  } else {
    await expect(page.getByRole('button', { name: '登記已回報' })).toBeVisible({ timeout: 15000 });
  }
  return { receipt, blocked, state, before };
}

async function enter(page, nested = false) {
  if (nested) await page.getByRole('button', { name: '登記這筆' }).click();
  else await page.getByRole('button', { name: '登記已回報' }).click();
  const modal = page.locator('.pe-modal'); await expect(modal).toBeVisible();
  await modal.locator('input[type=number]').fill('8800');
  await modal.locator('label.pe-radio', { hasText: '現金' }).click();
  await modal.locator('button[type=submit]').click();
  await expect(modal.locator('input[type=number]')).toHaveValue('8800');
  await expect(modal.locator('[role=alert]')).toBeVisible();
  return modal;
}
async function finish(page, x, kind) {
  expect(x.receipt.local_refusals).toBe(1); expect(x.receipt.unexpected_business_requests, JSON.stringify(x.receipt.unexpected_routes || [])).toBe(0);
  expect(x.receipt.business_post_upstream).toBe(0); expect(x.receipt.confirm_reject_void_batch_attempts).toBe(0);
  expect(x.receipt.page_errors).toBe(0); expect(x.blocked).toEqual([]);
  expect(x.receipt.assets.some((a) => a.type.includes('javascript'))).toBeTruthy();
  if (!LOCAL) expect(x.receipt.assets.some((a) => a.type.includes('text/css'))).toBeTruthy();
  const after = await identity(x.request); expect(after).toEqual(x.before);
  x.receipt.assets.sort((a,b) => a.path.localeCompare(b.path));
  await test.info().attach('payment-conflict-safe-receipt.json', { body: JSON.stringify({ ...x.receipt,
    case: kind === '7600' ? 'synthetic_expected_amount7600' : kind === 'zero' ? 'synthetic_expected_amount0' : kind }), contentType: 'application/json' });
}

for (const [kind, title] of [['pending','pending report conflict refreshes the collection owner'], ['nested','pending report conflict refreshes ledger and collection owners'], ['7600','synthetic expected_amount7600 refusal preserves entered amount'], ['zero','synthetic expected_amount0 refusal preserves entered amount']]) {
  test(`production UI: ${title}`, async ({ page, request }) => {
    const x = await app(page, request, kind); x.request = request;
    if (kind === 'nested') {
      await expect(page.locator('[data-testid="contract-card-9001"] [data-testid="contract-record"]')).toBeVisible();
    }
    const baseline = { api: x.receipt.api_gets, ledger: x.receipt.ledger_gets };
    const modal = await enter(page, kind === 'nested');
    const message = modal.locator('[role=alert]');
    if (kind.startsWith('pending') || kind === 'nested') {
      await expect(message).toContainText('金額不符請先退回，再重新登記');
      await expect(message).toContainText('相符才確認入帳');
      await expect.poll(() => x.receipt.api_gets).toBeGreaterThan(baseline.api);
      if (kind === 'nested') await expect.poll(() => x.receipt.ledger_gets).toBeGreaterThan(baseline.ledger);
      if (kind === 'nested') await expect(page.getByRole('dialog').getByText('待核對入帳')).toBeVisible();
      if (kind !== 'nested') await expect(page.getByRole('button', { name: '確認入帳' })).toBeVisible();
    } else {
      await expect(message).toContainText(kind === '7600' ? 'NT$7,600' : 'NT$0');
      await expect(message).toContainText('不要為了送出而改小實收金額');
      await expect(message).toContainText('請先核對約定費用');
      await expect(message).toContainText('尚未完成核對前，請勿確認入帳');
    }
    await expect.poll(() => x.receipt.api_gets).toBeGreaterThan(baseline.api);
    await expect(modal).toBeVisible(); await expect(modal.locator('input[type=number]')).toHaveValue('8800');
    await modal.getByRole('button', { name: '取消' }).click(); await expect(modal).toBeHidden();
    x.receipt.alert_owner_refreshes = x.receipt.api_gets - baseline.api;
    x.receipt.ledger_owner_refreshes = x.receipt.ledger_gets - baseline.ledger;
    if (kind === 'nested') await page.getByRole('button', { name: '關閉學生帳務' }).click();
    if (kind === 'pending' || kind === 'nested') await expect(page.locator('button[title="確認入帳"]')).toBeVisible();
    await finish(page, x, kind);
  });
}

test('local guard: fixture refusal is upstream-free and unexpected writes remain blocked', async ({ browser }) => {
  const http = await import('node:http'); let upstreamWrites = 0;
  const server = http.createServer((req, res) => { if (req.method !== 'GET') upstreamWrites += 1; res.writeHead(200, { 'content-type': 'text/html' }); res.end('<button id="send">send</button>'); });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const address = server.address(); const base = `http://127.0.0.1:${address.port}`;
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const blocked = await installReadOnlyAppGuard(context, base); const page = await context.newPage();
  await page.route('**/api/**', async (route) => {
    const r = route.request(); let body; try { body = r.postDataJSON(); } catch { body = null; }
    if (r.method() === 'POST' && new URL(r.url()).origin === base
      && new URL(r.url()).pathname === '/api/v1/payment-reports/director-record'
      && JSON.stringify(Object.keys(body || {}).sort()) === JSON.stringify(bodyKeys)
      && body.student_class_id === 9001 && body.invoice_id === 902 && body.amount === 8800 && body.payment_method === 'cash') {
      await route.fulfill({ status: 422, body: '{"code":"pending_report_exists"}' });
    } else await route.fallback();
  });
  await page.goto(base); await page.evaluate(async () => {
    await fetch('/api/v1/payment-reports/director-record', { method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ student_class_id: 9001, invoice_id: 902, amount: 8800, payment_method: 'cash', payment_date: new Date().toISOString().slice(0,10) }) });
    await fetch('/api/v1/payment-reports/confirm/903', { method: 'POST', body: '{}' }).catch(() => {});
  });
  expect(upstreamWrites).toBe(0); expect(blocked).toEqual([{ method: 'POST', origin: base, path: '/api/v1/payment-reports/confirm/903' }]);
  await context.close(); await new Promise((resolve) => server.close(resolve));
});
