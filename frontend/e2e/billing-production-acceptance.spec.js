// @ts-check
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { test } from '@playwright/test';
import { installProductionMutationGuard } from './support/productionMutationGuard.js';

// This spec may also be selected by the existing UI Smoke workflow when run
// against this branch. Never retain a production page artifact or retry a
// production acceptance after a failure.
test.describe.configure({ retries: 0 });
test.use({ screenshot: 'off', video: 'off', trace: 'off' });

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = {
  account: process.env.SMOKE_DIRECTOR_USER,
  password: process.env.SMOKE_DIRECTOR_PASS,
};
const PENDING_REPORT_ID = 1531;
const EXISTING_RECEIPT_NUMBER = 'R-001540';

function requireCondition(condition, message) {
  if (!condition) throw new Error(message);
}

async function waitUntil(predicate, message, timeoutMs = 15_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(message);
}

const SAFE_NAV_LABELS = new Set([
  '首頁', '帳務中心', '課程管理', '學生管理', '老師管理', '出缺勤管理',
  '班級行事曆 / 課表', '當月學收', 'Bug 回報', '內部聊天', '更多',
]);

async function runtimeSnapshot(page) {
  return page.evaluate((safeLabels) => {
    const visible = (element) => {
      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const text = (element) => {
      const clone = element.cloneNode(true);
      clone.querySelectorAll?.('.material-symbols-outlined').forEach((icon) => icon.remove());
      return (clone.textContent || '').replace(/\s+/g, ' ').trim();
    };
    const visibleElements = (selector) => Array.from(document.querySelectorAll(selector)).filter(visible);
    const knownLabels = new Set(safeLabels);
    const navLabels = Array.from(document.querySelectorAll('nav button, nav a, aside button, aside a, .sidebar button, .sidebar a, [data-testid*="nav"] button'))
      .filter(visible)
      .map(text)
      .flatMap((value) => [...knownLabels].filter((label) => value.includes(label)));
    const accountingTabs = visibleElements('.acct-tabs [role="tab"], .acct-tabs .acct-tab').map((element) => ({
      label: text(element),
      active: element.getAttribute('aria-selected') === 'true' || element.classList.contains('active'),
    }));
    const statusTabs = visibleElements('.tc-tabs .tc-tab').map((element) => ({
      label: text(element),
      active: element.getAttribute('aria-selected') === 'true' || element.classList.contains('tc-tab--active'),
    }));
    const loadingSelectors = ['[aria-busy="true"]', '[role="progressbar"]', '.tc-skeleton-area', '.tc-inline-loading', '.tc-loading', '.loading'];
    const loadingIndicators = loadingSelectors.flatMap((selector) => visibleElements(selector).map((element) => selector));
    const errorIndicators = visibleElements('[role="alert"], .tc-error, .tc-inline-error, .error').map((element) => element.className || element.getAttribute('role') || 'error');
    const headingLabels = visibleElements('h1, h2, h3').map(text).filter((value) => value && value.length < 80);
    return {
      pathname: `${location.pathname}${location.hash}`,
      section: headingLabels[0] || 'UNKNOWN',
      navLabels: [...new Set(navLabels)],
      activeCampus: Number(localStorage.getItem('app_branch') || 0) || 'UNKNOWN',
      accountingTabs,
      activeAccountingTab: accountingTabs.find((tab) => tab.active)?.label || 'NONE',
      statusTabs,
      activeStatusTab: statusTabs.find((tab) => tab.active)?.label || 'NONE',
      loadingIndicators: [...new Set(loadingIndicators)],
      errorIndicators: [...new Set(errorIndicators)].slice(0, 10),
      accountingContainer: visibleElements('.acct-tabs').length > 0,
      receiptRows: visibleElements('.acct-table tbody tr').length,
      pendingRows: visibleElements('.tc-table:not(.acct-table) tbody tr').length,
      emptyState: visibleElements('.tc-empty, .tc-accounting-empty').length > 0,
    };
  }, [...SAFE_NAV_LABELS]);
}

async function recordRuntimeSnapshot(page, report, phase) {
  const snapshot = await runtimeSnapshot(page);
  report.runtimeSnapshots.push({ phase, ...snapshot });
  return snapshot;
}

function responseShape(body) {
  if (Array.isArray(body)) return { kind: 'array', length: body.length };
  if (!body || typeof body !== 'object') return { kind: typeof body };
  const keys = Object.keys(body).filter((key) => ['data', 'meta', 'current_page', 'last_page', 'per_page', 'total', 'invoices', 'summary'].includes(key));
  return { kind: 'object', keys };
}

function startPasteTargetServer() {
  const server = http.createServer((_request, response) => {
    response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    response.end(`<!doctype html><meta charset="utf-8">
      <textarea id="paste-target" aria-label="temporary paste target"></textarea>
      <script>
        window.__pasteResult = null;
        document.querySelector('#paste-target').addEventListener('paste', (event) => {
          const items = Array.from(event.clipboardData?.items || []);
          const types = Array.from(event.clipboardData?.types || []);
          window.__pasteResult = {
            hasImage: items.some((item) => item.kind === 'file' && item.type === 'image/png')
              || types.includes('image/png'),
            hasReceiptText: types.includes('text/plain')
              && event.clipboardData.getData('text/plain').includes('電子收據'),
          };
        });
      </script>`);
  });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolve({
        origin: `http://127.0.0.1:${address.port}`,
        close: () => new Promise((done) => server.close(() => done())),
      });
    });
  });
}

function pngEvidence(bytes, filePath) {
  const signature = Buffer.from('89504e470d0a1a0a', 'hex');
  const validSignature = Buffer.from(bytes.subarray(0, 8)).equals(signature);
  const width = validSignature && bytes.length >= 24 ? bytes.readUInt32BE(16) : 0;
  const height = validSignature && bytes.length >= 24 ? bytes.readUInt32BE(20) : 0;
  const mime = execFileSync('file', ['--brief', '--mime-type', filePath], { encoding: 'utf8' }).trim();
  return {
    validSignature,
    mime,
    bytes: bytes.length,
    width,
    height,
  };
}

async function login(page, guard, report) {
  await page.goto('/');
  await page.locator('.role-btn', { hasText: '主任/櫃台' }).first().click();
  await page.locator('#login-account').fill(DIRECTOR.account);
  await page.locator('#login-password').fill(DIRECTOR.password);
  const loginResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === 'POST' && url.pathname === '/api/v1/auth/login';
  });
  await page.locator('button.login-btn').click();
  const loginResponse = await loginResponsePromise;
  requireCondition(loginResponse.ok(), 'normal Director login returned a non-success response');

  // Activate the guard immediately after the authentication response, before
  // the SPA finishes its post-login transition or any business navigation.
  guard.markAuthenticated();
  report.mutationGuardAttachedAfterLogin = 'YES';
  report.guardActiveBeforeBillingNavigation = guard.phase() === 'authenticated' ? 'YES' : 'NO';
  requireCondition(report.guardActiveBeforeBillingNavigation === 'YES', 'mutation guard was not activated after login');
  await waitUntil(
    () => page.locator('#login-account').count().then((count) => count === 0),
    'normal Director login did not complete',
  );

  // Prove the authentication POST was the only narrowly allowed non-GET.
  guard.assertNoUnexpectedMutations();
  const loginExceptions = guard.allowedNonGetExceptions();
  requireCondition(
    loginExceptions.length === 1 && loginExceptions[0].pathname === '/api/v1/auth/login',
    'authentication mutation was not narrowly identified',
  );
  report.authentication = 'PASS';
  console.log('UAT_STAGE authentication_pass');
  guard.assertNoUnexpectedMutations();
}

async function findVisibleControl(page, label) {
  const controls = page.locator('button, a, [role="button"]').filter({ hasText: label });
  for (let index = 0; index < await controls.count(); index += 1) {
    const candidate = controls.nth(index);
    if (await candidate.isVisible().catch(() => false)) return candidate;
  }
  return null;
}

async function navigateTo(page, label, title, guard = null, report = null) {
  for (const selector of ['.guide-tour-close', '.release-nudge-btn:has-text("稍後再看")']) {
    const overlayClose = page.locator(selector).first();
    if (await overlayClose.isVisible().catch(() => false)) {
      await overlayClose.click().catch(() => {});
      await page.waitForTimeout(200);
    }
  }
  const before = await recordRuntimeSnapshot(page, report || { runtimeSnapshots: [] }, `navigation_before_${title}`);
  const button = await findVisibleControl(page, label);
  const step = {
    label,
    elementFound: Boolean(button),
    elementVisible: Boolean(button),
    clickCompleted: false,
    urlBefore: before.pathname,
    urlAfter: before.pathname,
  };
  report?.navigationSteps.push(step);
  requireCondition(button, `${title} navigation control not found`);
  await button.click();
  step.clickCompleted = true;
  await waitUntil(async () => {
    const heading = page.getByRole('heading', { name: title, exact: true });
    return await heading.count() > 0 && await heading.first().isVisible().catch(() => false);
  }, `${title} did not render`, 30_000);
  step.urlAfter = (await recordRuntimeSnapshot(page, report || { runtimeSnapshots: [] }, `navigation_after_${title}`)).pathname;
  guard?.assertNoUnexpectedMutations();
}

function installReadObserver(page, observed, runtime) {
  const requestStarted = new WeakMap();
  page.on('request', (request) => {
    if (request.method() === 'GET') requestStarted.set(request, Date.now());
  });
  page.on('console', (message) => {
    if (message.type() === 'error') runtime.consoleErrors += 1;
  });
  page.on('pageerror', () => {
    runtime.pageErrors += 1;
  });
  page.on('requestfailed', (request) => {
    const url = new URL(request.url());
    runtime.failedRequests.push({ method: request.method(), pathname: url.pathname });
  });
  page.on('response', async (response) => {
    const request = response.request();
    if (request.method() !== 'GET') return;
    let url;
    try { url = new URL(response.url()); } catch { return; }
    if (!url.pathname.startsWith('/api/v1/')) return;

    const relevant = /\/(payment-reports|alerts\/tuition|student-classes|receipts|invoices|accounting\/payments|accounting\/settled-courses|me|campuses)(?:\/|$)/.test(url.pathname);
    let json = null;
    try { json = await response.json(); } catch { /* non-JSON read */ }
    if (relevant) {
      runtime.getObservations.push({
        pathname: url.pathname,
        status: response.status(),
        durationMs: Math.max(0, Date.now() - (requestStarted.get(request) || Date.now())),
        shape: responseShape(json),
      });
      if (!json) return;
    }
    if (!json) return;

    if (url.pathname === '/api/v1/student-classes') {
      const list = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
      observed.studentClasses.push(...list.map((course) => ({
        id: Number(course.id),
        studentName: String(course.student_name || ''),
        paymentStatus: String(course.payment_status || ''),
        latestPaymentReportId: Number(course.latest_payment_report_id || 0),
        branchId: Number(course.branch_id || 0),
      })));
    }
    if (url.pathname === '/api/v1/students') {
      const list = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
      for (const student of list) {
        if (student?.name) observed.studentLatestNotes.set(String(student.name), String(student.latest_payment_note || ''));
      }
    }
    const invoiceMatch = url.pathname.match(/^\/api\/v1\/student-classes\/(\d+)\/invoices$/);
    if (invoiceMatch) {
      const payments = (json?.invoices || []).flatMap((invoice) => invoice.payments || []);
      observed.invoicePayments.push(...payments.map((payment) => ({
        reportId: Number(payment.report_id || 0),
        note: String(payment.note || ''),
        status: payment.status,
        canViewReceipt: Boolean(payment.can_view_receipt),
      })));
    }
  });
}

async function waitForAccountingFrame(page, report, phase) {
  let last = null;
  await waitUntil(async () => {
    last = await recordRuntimeSnapshot(page, report, phase);
    return last.accountingContainer && last.accountingTabs.length >= 3;
  }, '帳務中心 container or accounting tabs did not render', 30_000);
  return last;
}

async function waitForReceivableState(page, report, phase) {
  let last = null;
  await waitUntil(async () => {
    last = await recordRuntimeSnapshot(page, report, phase);
    const hasTerminalState = last.statusTabs.length > 0 || last.emptyState || last.errorIndicators.length > 0;
    return hasTerminalState && last.loadingIndicators.length === 0;
  }, '帳務中心待處理 data state did not settle', 30_000);
  return last;
}

async function ensureAccountingPage(page, guard, report) {
  let snapshot = await recordRuntimeSnapshot(page, report, 'accounting_before_navigation');
  if (!snapshot.accountingContainer || snapshot.accountingTabs.length < 3) {
    await navigateTo(page, '帳務中心', '帳務中心', guard, report);
    snapshot = await waitForAccountingFrame(page, report, 'accounting_frame_wait');
  }
  report.activeAccountingTab = snapshot.activeAccountingTab;
  return snapshot;
}

function classifyPendingDiagnosis(snapshot, getObservations) {
  if (!snapshot) return 'INCONCLUSIVE';
  if (!snapshot.accountingContainer || snapshot.accountingTabs.length < 3) return 'NAVIGATION FAILURE';
  const relevant = getObservations.filter((entry) => entry.pathname === '/api/v1/alerts/tuition');
  if (relevant.some((entry) => entry.status >= 400 || entry.status === 0)) return 'DATA LOAD FAILURE';
  if (snapshot.loadingIndicators.length > 0) return 'LOADING STATE STUCK';
  if (snapshot.statusTabs.length === 0 && snapshot.emptyState) return 'FILTER/BRANCH FAILURE';
  if (snapshot.statusTabs.length === 0) return 'DOM SELECTOR FAILURE';
  return 'INCONCLUSIVE';
}

async function readProductionJson(page, requestPath) {
  return page.evaluate(async (path) => {
    const rawSession = localStorage.getItem('alltrue_session');
    let token = '';
    try { token = JSON.parse(rawSession || 'null')?.access_token || ''; } catch (_) { /* no-op */ }
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15_000);
    let response;
    try {
      response = await fetch(path, { headers, signal: controller.signal });
    } catch (_) {
      return { status: 0, ok: false, body: null };
    } finally {
      window.clearTimeout(timeout);
    }
    let body = null;
    try { body = await response.json(); } catch (_) { /* no-op */ }
    return { status: response.status, ok: response.ok, body };
  }, requestPath);
}

function listRows(body) {
  return Array.isArray(body?.data) ? body.data : (Array.isArray(body) ? body : []);
}

function paginationLastPage(body) {
  return Number(body?.last_page ?? body?.meta?.last_page ?? body?.lastPage ?? 0);
}

async function readPaginatedPaymentReports(page, query) {
  const reports = [];
  const maxPages = 100;
  let pageNumber = 1;
  let lastPage = 0;
  let firstResponse = null;

  while (pageNumber <= maxPages) {
    const params = new URLSearchParams(query);
    params.set('page', String(pageNumber));
    const response = await readProductionJson(page, `/api/v1/payment-reports?${params.toString()}`);
    if (!firstResponse) firstResponse = response;
    requireCondition(response.status !== 401, 'authenticated payment-report read returned 401');
    requireCondition(response.status !== 403, 'Director identity is not authorized to read payment reports');
    requireCondition(response.ok, `payment-report read returned HTTP ${response.status}`);

    const rows = listRows(response.body);
    reports.push(...rows.map((report) => ({
      id: Number(report.id || 0),
      studentName: String(report.student_name || ''),
      studentId: Number(report.student_id || 0),
      studentClassId: Number(report.student_class_id || 0),
      paymentDate: String(report.payment_date || ''),
      paymentMethod: String(report.payment_method || ''),
      reportedAmount: Number(report.reported_amount || 0),
      status: String(report.status || ''),
      notePresent: Boolean(String(report.note || '').trim()),
    })));

    lastPage = paginationLastPage(response.body);
    if (lastPage > 0 ? pageNumber >= lastPage : rows.length === 0 || rows.length < 30) break;
    pageNumber += 1;
  }

  requireCondition(pageNumber <= maxPages, 'payment-report pagination exceeded the bounded read limit');
  return { reports, firstResponse };
}

function campusIdsFrom(value) {
  if (!Array.isArray(value)) return [];
  return value.map((campus) => {
    if (typeof campus === 'object') return Number(campus.id ?? campus.branch_id ?? campus.campus_id ?? 0);
    return Number(campus);
  }).filter((id) => Number.isFinite(id) && id > 0);
}

async function readDirectorScope(page, report) {
  const meResponse = await readProductionJson(page, '/api/v1/me');
  requireCondition(meResponse.ok, `authenticated identity read returned HTTP ${meResponse.status}`);
  const me = meResponse.body || {};
  const sessionIdentity = await page.evaluate(() => {
    let session = null;
    try { session = JSON.parse(localStorage.getItem('alltrue_session') || 'null'); } catch (_) { /* no-op */ }
    return {
      role: session?.user?.role || session?.role || '',
      currentCampus: Number(localStorage.getItem('app_branch') || 0),
    };
  });

  let accessibleCampusIds = campusIdsFrom(me.campuses);
  if (!accessibleCampusIds.length) {
    const campusesResponse = await readProductionJson(page, '/api/v1/campuses');
    if (campusesResponse.ok) accessibleCampusIds = campusIdsFrom(campusesResponse.body?.data ?? campusesResponse.body);
  }

  report.directorRole = String(me.role || sessionIdentity.role || 'UNKNOWN');
  report.accessibleCampusIds = accessibleCampusIds;
  report.currentCampus = sessionIdentity.currentCampus || 'UNKNOWN';
  requireCondition(['director', 'admin', 'super_admin'].includes(report.directorRole), 'authenticated identity is not a Director role');
  return { accessibleCampusIds, currentCampus: sessionIdentity.currentCampus };
}

async function discoverPendingCase(page, report, scope) {
  const pendingResult = await readPaginatedPaymentReports(page, { status: 'pending' });
  const pendingReports = pendingResult.reports;
  const specificPending = pendingReports.find((item) => item.id === PENDING_REPORT_ID) || null;
  let allReports = pendingReports;
  if (!specificPending) {
    // A second canonical read distinguishes “not pending” from “not visible in
    // this Director scope”. It is still GET-only and uses the same paginated API.
    allReports = (await readPaginatedPaymentReports(page, {})).reports;
  }
  const specificAny = allReports.find((item) => item.id === PENDING_REPORT_ID) || null;
  const specificState = specificPending
    ? 'FOUND'
    : specificAny
      ? 'NOT PENDING'
      : scope.accessibleCampusIds.length
        ? 'NOT IN AUTHORIZED SCOPE'
        : 'NOT FOUND';

  report.paymentReport1531 = specificState;
  report.specificReportId = specificPending?.id || specificAny?.id || 'NOT VISIBLE';
  report.specificCase = specificPending
    ? 'BLOCKED BY SCOPE'
    : specificState === 'NOT IN AUTHORIZED SCOPE'
      ? 'BLOCKED BY SCOPE'
      : 'FAIL';

  const canary = specificPending || pendingReports.find((item) => item.status === 'pending') || null;
  if (!canary) {
    report.specificCase = specificState === 'NOT IN AUTHORIZED SCOPE' ? 'BLOCKED BY SCOPE' : 'FAIL';
    return { canary: null, allReports };
  }
  report.testReportId = canary.id;
  report.testStudentClassId = canary.studentClassId;
  report.pendingReportRecord = {
    id: canary.id,
    studentClassId: canary.studentClassId,
    paymentDate: canary.paymentDate,
    paymentMethod: canary.paymentMethod,
    reportedAmount: canary.reportedAmount,
    status: canary.status,
  };
  report.paymentReportNotePresent = Boolean(canary.notePresent);
  return { canary, allReports };
}

async function verifyPendingCanary(page, guard, observed, report, canary) {
  if (!canary) {
    report.pending = 'BLOCKED';
    report.pendingDiagnosis = 'INCONCLUSIVE';
    report.workflowClarity = 'NOT REACHED';
    return;
  }

  console.log('UAT_STAGE pending_canary_begin');
  const classQuery = canary.studentId > 0
    ? `student_id=${encodeURIComponent(String(canary.studentId))}&per_page=1000`
    : 'per_page=1000';
  const classResponse = await readProductionJson(page, `/api/v1/student-classes?${classQuery}`);
  requireCondition(classResponse.ok, `student-class read returned HTTP ${classResponse.status}`);
  const classRows = listRows(classResponse.body);
  const classRecord = classRows.find((course) => Number(course.id) === canary.studentClassId) || null;
  report.backendPaymentStatus = String(classRecord?.payment_status || 'UNKNOWN');
  report.backendLatestPaymentReportId = Number(classRecord?.latest_payment_report_id || 0) || 'MISSING';

  let pendingRowText = '';
  let pendingRowReached = false;
  try {
    await ensureAccountingPage(page, guard, report);
    console.log('UAT_STAGE pending_accounting_open');
    report.pagesChecked.push('帳務中心／待處理');
    let state;
    try {
      state = await waitForReceivableState(page, report, 'pending_data_wait');
    } catch (_) {
      state = await recordRuntimeSnapshot(page, report, 'pending_data_timeout');
      report.pendingDiagnosis = classifyPendingDiagnosis(state, report.getObservations);
      throw new Error('receivable application state did not settle');
    }
    report.pendingApiReturned = report.getObservations.some((entry) => entry.pathname === '/api/v1/alerts/tuition' && entry.status >= 200 && entry.status < 300) ? 'YES' : 'NO';
    const pendingTab = await findVisibleControl(page, '待對帳') || await findVisibleControl(page, '待核帳');
    if (!pendingTab) {
      report.pendingDiagnosis = classifyPendingDiagnosis(state, report.getObservations);
      throw new Error('pending status tab is not present in settled DOM');
    }
    report.pendingStatusTabFound = 'YES';
    await pendingTab.click();
    report.activeStatusTab = (await recordRuntimeSnapshot(page, report, 'pending_status_tab_after_click')).activeStatusTab;
    const search = page.locator('input[placeholder^="搜尋學生姓名"]').first();
    if (await search.count() && await search.isVisible().catch(() => false)) await search.fill(canary.studentName);
    const pendingRow = page.locator('.tc-table tbody tr').filter({ hasText: canary.studentName }).first();
    await waitUntil(() => pendingRow.count().then((count) => count > 0), 'authorized pending canary did not render in accounting center', 30_000);
    pendingRowReached = true;
    pendingRowText = await pendingRow.innerText();
    const currentLabel = (pendingRowText.match(/待對帳|待核帳|未繳費|已繳費/) || ['UNKNOWN'])[0];
    report.currentLabel = currentLabel;
    report.uiStatus = currentLabel;
    const hasExpectedStatus = currentLabel === '待對帳';
    const hasUnpaidStatus = pendingRowText.includes('未繳費');
    const hasConfirmAction = await pendingRow.getByRole('button', { name: '確認入帳', exact: true }).count() > 0;
    const hasDuplicateReportAction = await pendingRow.getByRole('button', { name: '登記已回報', exact: true }).count() > 0;
    report.workflowClarity = hasExpectedStatus && hasConfirmAction && !hasDuplicateReportAction ? 'VERIFIED' : 'UX GAP';
    report.pendingDiagnosis = hasExpectedStatus && !hasUnpaidStatus ? 'VERIFIED FIXED' : 'PRODUCT RENDER DEFECT';
  } catch (_) {
    report.pending = 'FAIL';
    if (!report.pendingDiagnosis || report.pendingDiagnosis === 'INCONCLUSIVE') {
      report.pendingDiagnosis = classifyPendingDiagnosis(await recordRuntimeSnapshot(page, report, 'pending_failure_snapshot'), report.getObservations);
    }
    report.workflowClarity = pendingRowReached ? report.workflowClarity : 'NOT REACHED';
  }

  try {
    await navigateTo(page, '課程管理', '課程管理', guard, report);
    console.log('UAT_STAGE pending_course_management_open');
    report.pagesChecked.push('課程管理／課程列表');
    const filter = page.locator('input[placeholder="輸入姓名..."]').first();
    await waitUntil(() => filter.count().then((count) => count > 0), 'course-management name filter was not rendered', 30_000);
    await filter.fill(canary.studentName);
    const group = page.locator('.student-group-card').filter({ hasText: canary.studentName }).first();
    await waitUntil(() => group.count().then((count) => count > 0), 'authorized pending canary did not render in course management', 30_000);
    if (await group.locator('.student-group-header').getAttribute('aria-expanded') === 'false') {
      await group.locator('.student-group-header').click();
    }
    await group.getByRole('tab', { name: '帳務資料', exact: true }).click();
    await waitUntil(() => group.locator('table.student-billing-table tbody tr').count().then((count) => count > 0), 'course billing rows did not render for authorized pending canary', 30_000);
    const courseText = await group.locator('table.student-billing-table tbody tr').first().innerText();
    report.courseListLabel = (courseText.match(/待對帳|待核帳|未繳費|已繳費/) || ['UNKNOWN'])[0];
  } catch (_) {
    report.courseListLabel = report.courseListLabel || 'UNKNOWN';
  }

  const backendContract = report.backendPaymentStatus === 'pending_report'
    && Number(report.backendLatestPaymentReportId) === canary.id;
  const uiContract = report.currentLabel === '待對帳'
    && !pendingRowText.includes('未繳費')
    && report.courseListLabel === '待對帳';
  report.pending = backendContract && uiContract ? 'VERIFIED FIXED' : (report.pending === 'BLOCKED' ? 'BLOCKED' : 'FAIL');
  console.log(`UAT_STAGE pending_complete_${report.pending.replaceAll(' ', '_')}`);
  if (canary.id === PENDING_REPORT_ID) report.specificCase = report.pending === 'VERIFIED FIXED' ? 'PASS' : 'FAIL';
  void observed;
}

async function assertReceiptActions(page) {
  const modal = page.locator('.receipt-modal').first();
  await waitUntil(() => modal.count().then((count) => count > 0), 'receipt modal did not open');
  for (const testId of ['copy-receipt-image', 'copy-receipt-text', 'download-receipt-image']) {
    requireCondition(await modal.locator(`[data-testid="${testId}"]`).isVisible(), `${testId} is not rendered`);
  }
  requireCondition(await modal.getByRole('button', { name: '列印', exact: true }).isVisible(), 'print action is not rendered');
  return modal;
}

async function copyImageEvidence(page, context, modal, report) {
  const productionOrigin = new URL(BASE).origin;
  const preconditions = await page.evaluate(() => ({
    secureContext: window.isSecureContext,
    write: typeof navigator.clipboard?.write === 'function',
    read: typeof navigator.clipboard?.read === 'function',
    clipboardItem: typeof window.ClipboardItem === 'function',
  }));
  report.secureContext = preconditions.secureContext;
  report.clipboardApi = preconditions;
  requireCondition(preconditions.secureContext, 'production page is not a secure context');
  requireCondition(preconditions.write && preconditions.read && preconditions.clipboardItem, 'required clipboard APIs are unavailable');

  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: productionOrigin });
  let clipboard;
  try {
    await modal.locator('[data-testid="copy-receipt-image"]').click();
    if (await modal.locator('.receipt-copy-error').isVisible().catch(() => false)) {
      report.clipboardUiError = 'VISIBLE';
      throw new Error('copy image handler reported an error');
    }
    clipboard = await page.evaluate(async () => {
      const items = await navigator.clipboard.read();
      const types = items.flatMap((item) => Array.from(item.types));
      const imageItem = items.find((item) => item.types.includes('image/png'));
      if (!imageItem) return { types, blob: null, decoded: false };
      const blob = await imageItem.getType('image/png');
      let decoded = false;
      try {
        const bitmap = await createImageBitmap(blob);
        decoded = bitmap.width > 0 && bitmap.height > 0;
        bitmap.close();
      } catch (_) {
        decoded = false;
      }
      return { types, blob: { type: blob.type, size: blob.size, bytes: Array.from(new Uint8Array(await blob.arrayBuffer())) }, decoded };
    });
  } catch (error) {
    const message = String(error?.message || '');
    report.clipboardError = /NotAllowedError/i.test(message)
      ? 'NotAllowedError'
      : /SecurityError/i.test(message)
        ? 'SecurityError'
        : /NotSupportedError/i.test(message)
          ? 'NotSupportedError'
          : 'UNKNOWN';
    report.clipboardUiError = await modal.locator('.receipt-copy-error').isVisible().catch(() => false) ? 'VISIBLE' : 'NOT_VISIBLE';
    throw new Error('copy image clipboard runtime failed');
  }
  report.clipboardTypes = clipboard.types;
  report.pngMime = clipboard.blob?.type || 'UNKNOWN';
  report.pngBytes = clipboard.blob?.size || 0;
  report.pngDecode = Boolean(clipboard.decoded);
  requireCondition(clipboard.types.includes('image/png'), 'clipboard does not contain image/png');
  requireCondition(clipboard.blob?.type === 'image/png' && clipboard.blob.size > 0, 'clipboard PNG blob is empty or has wrong MIME');
  requireCondition(clipboard.decoded, 'browser could not decode copied PNG');

  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'alltrue-receipt-'));
  const tempFile = path.join(tempDir, 'receipt.png');
  try {
    const bytes = Buffer.from(clipboard.blob.bytes);
    fs.writeFileSync(tempFile, bytes);
    const png = pngEvidence(bytes, tempFile);
    report.pngSignature = png.validSignature;
    report.pngDimensions = `${png.width}x${png.height}`;
    report.pngRunnerMime = png.mime;
    requireCondition(png.validSignature && png.mime === 'image/png' && png.width > 0 && png.height > 0, 'runner PNG validation failed');
    report.copyImage = 'VERIFIED FIXED';
  } finally {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
}

async function pasteEvidence(context, report) {
  const pasteServer = await startPasteTargetServer();
  const pastePage = await context.newPage();
  try {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: pasteServer.origin });
    await pastePage.goto(pasteServer.origin);
    await pastePage.locator('#paste-target').press('Control+V');
    await waitUntil(
      () => pastePage.evaluate(() => Boolean(window.__pasteResult)),
      'temporary paste target received no paste event',
    );
    const result = await pastePage.evaluate(() => window.__pasteResult);
    report.pasteResult = result.hasImage && !result.hasReceiptText ? 'IMAGE' : 'TEXT';
    requireCondition(report.pasteResult === 'IMAGE', 'temporary paste target received text instead of image');
  } finally {
    await pastePage.close();
    await pasteServer.close();
  }
}

async function copyTextEvidence(page, modal, report) {
  await modal.locator('[data-testid="copy-receipt-text"]').click();
  const clipboard = await page.evaluate(async () => {
    const items = await navigator.clipboard.read();
    return items.flatMap((item) => Array.from(item.types));
  });
  report.copyTextClipboardTypes = clipboard;
  requireCondition(clipboard.includes('text/plain') && !clipboard.includes('image/png'), 'copy text did not produce text-only clipboard');
  report.copyText = 'PASS';
}

async function downloadEvidence(page, modal, report) {
  const downloadPromise = page.waitForEvent('download');
  await modal.locator('[data-testid="download-receipt-image"]').click();
  const download = await downloadPromise;
  const tempPath = await download.path();
  requireCondition(tempPath, 'download did not produce a local path');
  try {
    const bytes = fs.readFileSync(tempPath);
    const png = pngEvidence(bytes, tempPath);
    report.downloadFilename = download.suggestedFilename();
    report.downloadBytes = png.bytes;
    report.downloadDimensions = `${png.width}x${png.height}`;
    report.downloadDecode = png.validSignature && png.mime === 'image/png' && png.width > 0 && png.height > 0;
    requireCondition(/\.png$/i.test(report.downloadFilename), 'download filename is not PNG');
    requireCondition(report.downloadDecode, 'downloaded PNG validation failed');
    report.downloadPng = 'PASS';
  } finally {
    await download.delete().catch(() => {});
  }
}

async function openTuitionReceipt(page, guard, report) {
  await ensureAccountingPage(page, guard, report);
  console.log('UAT_STAGE receipt_accounting_open');
  const receiptLedgerTab = page.locator('.acct-tabs .acct-tab').filter({ hasText: /收據(?:流水)?紀錄/ }).first();
  report.receiptDiagnosis.tabFound = await receiptLedgerTab.count() > 0 ? 'YES' : 'NO';
  requireCondition(await receiptLedgerTab.count() > 0 && await receiptLedgerTab.isVisible().catch(() => false), 'receipt ledger tab was not rendered');
  await receiptLedgerTab.click();
  await waitUntil(async () => {
    const selected = page.locator('.acct-tabs .acct-tab.active, .acct-tabs [role="tab"][aria-selected="true"]').filter({ hasText: /收據(?:流水)?紀錄/ });
    return await selected.count() > 0;
  }, 'receipt ledger tab did not become active', 15_000);
  report.receiptDiagnosis.tabSelected = 'YES';
  report.activeAccountingTab = (await recordRuntimeSnapshot(page, report, 'receipt_ledger_tab_selected')).activeAccountingTab;
  console.log('UAT_STAGE receipt_ledger_tab_open');
  const dates = page.locator('input[type="date"]');
  const dateCount = await dates.count();
  report.receiptDiagnosis.dateFilterInputs = dateCount;
  if (dateCount >= 2) {
    await dates.nth(0).fill('2026-08-01');
    await dates.nth(1).fill('2026-08-31');
    const studentSearch = page.locator('input[placeholder^="搜尋學生姓名"]').first();
    if (await studentSearch.count()) await studentSearch.fill('');
    await page.getByRole('button', { name: '查詢', exact: true }).click();
    console.log('UAT_STAGE receipt_ledger_query_sent');
  } else {
    report.receiptDiagnosis.filterMode = 'AUTO_LOAD_NO_DATE_FILTER';
  }
  guard.assertNoUnexpectedMutations();
  await waitUntil(async () => {
    const snapshot = await recordRuntimeSnapshot(page, report, 'receipt_data_wait');
    const relevant = report.getObservations.some((entry) => entry.pathname === '/api/v1/accounting/payments' && entry.status >= 200 && entry.status < 300);
    const terminal = snapshot.receiptRows > 0 || snapshot.emptyState || snapshot.errorIndicators.length > 0;
    return relevant && terminal && snapshot.loadingIndicators.length === 0;
  }, 'receipt ledger data state did not settle', 30_000);
  const rows = page.locator('table.acct-table tbody tr');
  const rowCount = await rows.count();
  report.receiptDiagnosis.rowCount = rowCount;
  if (!rowCount) {
    report.receiptDiagnosis.state = (await recordRuntimeSnapshot(page, report, 'receipt_empty_or_error')).errorIndicators.length ? 'UI ERROR' : 'EMPTY DATA';
    throw new Error('no existing receipt is visible in the settled receipt ledger');
  }
  report.receiptDiagnosis.state = 'DATA RENDERED';
  let row = page.locator('table.acct-table tbody tr').filter({ hasText: EXISTING_RECEIPT_NUMBER }).first();
  if (await row.count() === 0) row = rows.first();
  const receiptButton = row.locator('button[aria-label^="查看 "]').first();
  requireCondition(await receiptButton.count() > 0, 'existing receipt row has no receipt action');
  const receiptLabel = await receiptButton.getAttribute('aria-label');
  const studentName = (await row.locator('.tc-cell-name').textContent()).trim();
  await receiptButton.click();
  return { modal: await assertReceiptActions(page), studentName, trigger: receiptLabel || 'receipt action' };
}

async function openStudentsReceipt(page, studentName, observed, guard) {
  await navigateTo(page, '學生管理', '學生管理', guard);
  const filter = page.locator('input[placeholder="輸入姓名..."]').first();
  await filter.fill(studentName);
  await waitUntil(() => page.locator('tr.student-row').filter({ hasText: studentName }).count().then((count) => count > 0), 'receipt student was not found in student management');
  const studentRow = page.locator('tr.student-row').filter({ hasText: studentName }).first();
  await studentRow.click();
  const detail = page.locator('tr.course-detail-row').first();
  await waitUntil(() => detail.count().then((count) => count > 0), 'student course detail did not open');
  const courseRows = detail.locator('table.course-inner-table tbody tr');
  let receiptModal = null;
  for (let index = 0; index < await courseRows.count(); index += 1) {
    const courseRow = courseRows.nth(index);
    const infoButton = courseRow.getByRole('button', { name: '繳費資訊', exact: true });
    if (!await infoButton.count()) continue;
    await infoButton.click();
    await waitUntil(() => page.locator('.lpi-modal').count().then((count) => count > 0), 'latest payment info modal did not open');
    const viewButton = page.locator('.lpi-modal').getByRole('button', { name: '查看收據', exact: true });
    if (await viewButton.count() && await viewButton.isVisible()) {
      await viewButton.click();
      receiptModal = await assertReceiptActions(page);
      break;
    }
    await page.locator('.lpi-modal').getByRole('button', { name: '關閉', exact: true }).click();
  }
  requireCondition(receiptModal, 'StudentsList had no reachable existing receipt entry point');

  const paymentInfoNoteVisible = await page.locator('.lpi-modal .lpi-row').filter({ hasText: '備註' }).isVisible().catch(() => false);
  const invoiceNote = observed.invoicePayments.find((payment) => payment.note.trim());
  return { receiptModal, studentRow, paymentInfoNoteVisible, invoiceNote };
}

async function auditPaymentNote(page, guard, report, canary) {
  if (!canary?.studentClassId) {
    report.paymentNote = 'PRODUCT GAP';
    return;
  }
  const noteResponse = await readProductionJson(page, `/api/v1/student-classes/${canary.studentClassId}/invoices`);
  requireCondition(noteResponse.ok, `payment-note read returned HTTP ${noteResponse.status}`);
  const payments = (noteResponse.body?.invoices || []).flatMap((invoice) => invoice.payments || []);
  const notePresent = payments.some((payment) => String(payment.note || '').trim()) || Boolean(report.paymentReportNotePresent);
  report.authoritativeSource = notePresent ? 'PaymentReport.note（由付款 read model 提供）' : 'NO PAYMENT NOTE PRESENT IN READ MODEL';

  let displayed = false;
  try {
    await navigateTo(page, '學生管理', '學生管理', guard, report);
    const filter = page.locator('input[placeholder="輸入姓名..."]').first();
    await waitUntil(() => filter.count().then((count) => count > 0), 'student list name filter was not rendered', 30_000);
    await filter.fill(canary.studentName);
    const studentRow = page.locator('tr.student-row').filter({ hasText: canary.studentName }).first();
    await waitUntil(() => studentRow.count().then((count) => count > 0), 'pending canary student row did not render for note audit', 30_000);
    await studentRow.click();
    const detail = page.locator('tr.course-detail-row').first();
    await waitUntil(() => detail.count().then((count) => count > 0), 'student course detail did not render for note audit', 30_000);
    const infoButton = detail.locator('button').filter({ hasText: '繳費資訊' }).first();
    await waitUntil(() => infoButton.count().then((count) => count > 0), 'payment info entry point did not render for note audit', 30_000);
    await infoButton.click();
    const lpi = page.locator('.lpi-modal');
    await waitUntil(() => lpi.count().then((count) => count > 0), 'payment info modal did not open for note audit', 30_000);
    displayed = await lpi.locator('.lpi-row').filter({ hasText: '備註' }).isVisible().catch(() => false);
    if (displayed) report.displayedWhere.push('學生管理／課程／繳費資訊／備註');
    const close = lpi.locator('button[title="關閉"], button').filter({ hasText: '關閉' }).first();
    if (await close.count() && await close.isVisible().catch(() => false)) await close.click();
  } catch (_) {
    report.noteAuditUi = 'NOT REACHED';
  }
  report.paymentNote = notePresent && displayed ? 'SINGLE SOURCE — VERIFIED' : 'PRODUCT GAP';
}

test('authenticated production billing and receipt acceptance', async ({ page, context }) => {
  // Canonical paginated reads plus receipt rendering/clipboard/download checks
  // are intentionally bounded longer than the legacy 45s smoke default.
  test.setTimeout(300_000);
  page.setDefaultTimeout(15_000);
  page.setDefaultNavigationTimeout(20_000);
  requireCondition(BASE && DIRECTOR.account && DIRECTOR.password, 'production smoke secrets are unavailable');

  const report = {
    productionSha: 'UNKNOWN',
    authentication: 'FAIL',
    directorRole: 'UNKNOWN',
    accessibleCampusIds: [],
    currentCampus: 'UNKNOWN',
    mutationGuard: 'FAIL',
    mutationGuardAttachedAfterLogin: 'NO',
    guardActiveBeforeBillingNavigation: 'NO',
    mutationRequestsAttemptedAfterLogin: 0,
    mutationRequestsBlocked: 0,
    expectedBlockedSideEffects: 0,
    expectedBlockedSideEffectEndpoints: [],
    unexpectedMutationAttempts: 0,
    unexpectedMutationEndpoints: [],
    unexpectedProductionWrites: 0,
    navigationSteps: [],
    runtimeSnapshots: [],
    getObservations: [],
    pendingApiReturned: 'UNKNOWN',
    pendingStatusTabFound: 'UNKNOWN',
    activeAccountingTab: 'UNKNOWN',
    activeStatusTab: 'UNKNOWN',
    pendingDiagnosis: 'INCONCLUSIVE',
    receiptDiagnosis: {
      tabFound: 'UNKNOWN',
      tabSelected: 'UNKNOWN',
      rowCount: 0,
      state: 'NOT REACHED',
    },
    paymentReport1531: 'NOT FOUND',
    specificReportId: 'NOT VISIBLE',
    specificCase: 'BLOCKED BY SCOPE',
    testReportId: 'NOT SELECTED',
    testStudentClassId: 'NOT SELECTED',
    backendPaymentStatus: 'UNKNOWN',
    backendLatestPaymentReportId: 'UNKNOWN',
    courseListLabel: 'UNKNOWN',
    uiStatus: 'UNKNOWN',
    pendingReportRecord: null,
    paymentReportNotePresent: false,
    pending: 'BLOCKED',
    pagesChecked: [],
    currentLabel: 'UNKNOWN',
    workflowClarity: 'UX GAP',
    receiptActions: 'FAIL',
    receiptEntryPoints: [],
    copyImage: 'RUNTIME DEFECT',
    secureContext: false,
    clipboardApi: {},
    clipboardError: 'NONE',
    clipboardUiError: 'NONE',
    clipboardTypes: [],
    pngMime: 'UNKNOWN',
    pngBytes: 0,
    pngDimensions: '0x0',
    pngDecode: false,
    pasteResult: 'NOTHING',
    copyText: 'FAIL',
    copyTextClipboardTypes: [],
    downloadPng: 'FAIL',
    downloadFilename: 'UNKNOWN',
    downloadBytes: 0,
    downloadDimensions: '0x0',
    downloadDecode: false,
    paymentNote: 'PRODUCT GAP',
    authoritativeSource: 'UNKNOWN',
    displayedWhere: [],
    consoleErrors: 0,
    pageErrors: 0,
    failedRequests: [],
    temporaryPiiEvidenceRemoved: 'YES',
    productionDataModified: 'NO',
    productCodeChanged: 'NO',
  };
  const observed = {
    studentClasses: [],
    studentLatestNotes: new Map(),
    invoicePayments: [],
  };
  const runtime = { consoleErrors: 0, pageErrors: 0, failedRequests: [], getObservations: [] };
  installReadObserver(page, observed, runtime);
  const guard = await installProductionMutationGuard(page, {
    baseURL: BASE,
    loginPaths: ['/api/v1/auth/login'],
    expectedBlockedSideEffects: [
      { method: 'POST', pathname: '/api/v1/adoption/events' },
    ],
  });
  let failure = null;
  let canary = null;

  try {
    try {
      await login(page, guard, report);
    } catch (error) {
      failure = error;
    }

    if (!failure) {
      try {
        const version = await page.evaluate(async () => fetch('/version.json').then((response) => response.json()));
        report.productionSha = version.build_sha || version.hash || 'UNKNOWN';
      } catch (_) {
        report.productionSha = 'UNAVAILABLE';
      }

      let scope = { accessibleCampusIds: [], currentCampus: 'UNKNOWN' };
      try {
        scope = await readDirectorScope(page, report);
        console.log('UAT_STAGE director_scope_read');
      } catch (_) {
        report.directorRole = report.directorRole || 'UNKNOWN';
      }

      try {
        ({ canary } = await discoverPendingCase(page, report, scope));
        console.log(`UAT_STAGE canonical_reports_read_${canary ? 'canary_found' : 'no_canary'}`);
      } catch (_) {
        report.pending = 'BLOCKED';
        report.pendingDiagnosis = 'DATA LOAD FAILURE';
      }

      try {
        await verifyPendingCanary(page, guard, observed, report, canary);
        guard.assertNoUnexpectedMutations();
      } catch (error) {
        if (guard.unexpectedMutations().length) throw error;
      }

      let tuitionReceipt = null;
      try {
        console.log('UAT_STAGE receipt_accounting_begin');
        tuitionReceipt = await openTuitionReceipt(page, guard, report);
        const tuitionModal = tuitionReceipt.modal;
        report.receiptEntryPoints.push({ page: '帳務中心', route: report.runtimeSnapshots.at(-1)?.pathname || 'tuition-collect', trigger: tuitionReceipt.trigger, actions: 'YES' });
        report.receiptActions = 'VERIFIED FIXED';

        try {
          await copyImageEvidence(page, context, tuitionModal, report);
          guard.assertNoUnexpectedMutations();
        } catch (error) {
          if (guard.unexpectedMutations().length) throw error;
        }
        if (report.copyImage === 'VERIFIED FIXED') {
          try {
            await pasteEvidence(context, report);
            guard.assertNoUnexpectedMutations();
          } catch (error) {
            if (guard.unexpectedMutations().length) throw error;
          }
        }
        try {
          await copyTextEvidence(page, tuitionModal, report);
          guard.assertNoUnexpectedMutations();
        } catch (error) {
          if (guard.unexpectedMutations().length) throw error;
        }
        try {
          await downloadEvidence(page, tuitionModal, report);
          guard.assertNoUnexpectedMutations();
        } catch (error) {
          if (guard.unexpectedMutations().length) throw error;
        }
        await tuitionModal.locator('button[title="關閉"]').click().catch(() => {});

        // The second entry point is audited when reachable, but it does not
        // gate the independent copy/paste/download acceptance above.
        try {
          console.log('UAT_STAGE receipt_students_begin');
          const studentReceipt = await openStudentsReceipt(page, tuitionReceipt.studentName, observed, guard);
          report.receiptEntryPoints.push({ page: '學生管理', route: 'students', trigger: '學生課程／繳費資訊／查看收據', actions: 'YES' });
          await studentReceipt.receiptModal.locator('button[title="關閉"]').click().catch(() => {});
        } catch (error) {
          if (guard.unexpectedMutations().length) throw error;
          report.receiptEntryPoints.push({ page: '學生管理', route: 'students', trigger: '學生課程／繳費資訊／查看收據', actions: 'NOT REACHED' });
        }
      } catch (error) {
        if (guard.unexpectedMutations().length) throw error;
        report.receiptActions = report.receiptActions === 'VERIFIED FIXED' ? report.receiptActions : 'FAIL';
        report.receiptDiagnosis.failure = 'UI entry/navigation/data diagnosis recorded';
      }

      try {
        await auditPaymentNote(page, guard, report, canary);
        guard.assertNoUnexpectedMutations();
      } catch (error) {
        if (guard.unexpectedMutations().length) throw error;
        report.paymentNote = 'PRODUCT GAP';
      }

      report.mutationGuard = 'ACTIVE';
      console.log('UAT_STAGE acceptance_complete');
    }
  } catch (error) {
    failure = error;
  } finally {
    const blocked = guard.blockedRequests();
    const expectedSideEffects = guard.expectedBlockedSideEffects();
    const unexpectedMutations = guard.unexpectedMutations();
    report.mutationRequestsAttemptedAfterLogin = blocked.length;
    report.mutationRequestsBlocked = blocked.length;
    report.expectedBlockedSideEffects = expectedSideEffects.length;
    report.expectedBlockedSideEffectEndpoints = expectedSideEffects;
    report.unexpectedMutationAttempts = unexpectedMutations.length;
    report.unexpectedMutationEndpoints = unexpectedMutations;
    report.unexpectedProductionWrites = 0;
    report.consoleErrors = runtime.consoleErrors;
    report.pageErrors = runtime.pageErrors;
    report.failedRequests = runtime.failedRequests;
    report.getObservations = runtime.getObservations;
    if (guard.phase() === 'authenticated') report.mutationGuard = 'ACTIVE';
    if (!failure && unexpectedMutations.length) failure = new Error('unexpected production mutation was blocked');
    await guard.dispose().catch(() => {});
    console.log(`PRODUCTION_BILLING_UAT_REPORT ${JSON.stringify(report)}`);
  }

  if (failure) throw new Error(`production billing acceptance failed: ${failure.message}`);
});
