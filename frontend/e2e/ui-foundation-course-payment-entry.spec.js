// @ts-check
import { test, expect } from '@playwright/test';

import { LEGACY_FLAG_UNVERIFIED_HELP, REPORT_STATUS_LABELS, REVIEW_REQUIRED_LABEL, REVIEW_REQUIRED_SHORT_LABEL, TUITION_STATUS_CONFIG } from '../src/lib/courseMoneyState.js';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { expectCoursePaymentEntryOpensLedgerInPlace, recordCourseLists } from './fixtures/coursePaymentEntry.js';

/**
 * #4130 follow-up regression: the production acceptance checks the 課程查找 payment
 * entry through the shared expectCoursePaymentEntryOpensLedgerInPlace fixture. Run
 * the same fixture against the real App shell (mocked API) for every payment state,
 * so a label or behaviour change fails here instead of on the production gate.
 *
 * Each case lists the target course next to another student's course with a
 * different action, and the ledger mock answers only for the requested
 * student_class_id, so opening the wrong row's billing fails.
 */
// #4186: each state also pins the badge text the row must show (labels from courseMoneyState.js).
const STATES = [
  { paymentStatus: 'unpaid', actionLabel: '登記收款', badge: TUITION_STATUS_CONFIG.unpaid.label },
  { paymentStatus: 'partial', actionLabel: '登記收款', badge: TUITION_STATUS_CONFIG.partial.label },
  { paymentStatus: 'pending_report', actionLabel: `查看「${REPORT_STATUS_LABELS.pending}」`, badge: TUITION_STATUS_CONFIG.pending_report.label },
  { paymentStatus: 'paid', actionLabel: '在這裡看帳務', badge: TUITION_STATUS_CONFIG.paid.label },
  // #4174: paid by the legacy flag alone (no payment record) reads 需核對, never 已收. The course page keeps the full wording (#4900 shortens only payment pages).
  { paymentStatus: 'paid', legacyFlagOnly: true, actionLabel: '補登收款', badge: LEGACY_FLAG_UNVERIFIED_HELP },
];

/**
 * Non-GET endpoints the App shell sends that change no course or billing state:
 * - session-dates: read-only query sent as POST (classSessionsApi.js) to carry the course list in the body.
 * - adoption/events: fire-and-forget usage telemetry (adoptionTelemetry.js).
 * Any other non-GET request fails the test.
 */
const READ_ONLY_POSTS = new Set(['/api/v1/student-classes/session-dates', '/api/v1/adoption/events']);

// #5410: a paused course with teaching history must not require a temporary resume to close.
for (const width of [390, 1440]) {
  for (const { cancelClose, closedReason } of [
    { cancelClose: true, closedReason: null }, { cancelClose: false, closedReason: null },
    ...['refunded', 'usage_settled', 'unknown_future_closure'].map(closedReason => ({ cancelClose: true, closedReason })),
  ]) {
    test(`history-protected deletion offers the existing paused-course close, cancel=${cancelClose}, closed=${closedReason} @${width}`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 900 });
      const target = { ...course(7101, 7201, '合成結案學生', 'unpaid'), status: 'inactive', stop: 1,
        payment_type: 'session',
        closed_reason: closedReason, sessions_purchased: 8, sessions_used: 5, remaining_sessions: 3,
        UsedSessions: 5, RemainingSessions: 3 };
      const writes = [];
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.origin !== new URL(testInfo.project.use.baseURL).origin) return route.abort();
        if (!url.pathname.startsWith('/api/')) return route.continue();
        const path = url.pathname;
        if (request.method() === 'POST' && READ_ONLY_POSTS.has(path)) return route.fulfill({ json: { data: [] } });
        if (request.method() !== 'GET') {
          writes.push({ method: request.method(), path, body: request.postDataJSON() });
          if (request.method() === 'DELETE' && path === '/api/v1/student-classes/7101') {
            return route.fulfill({ status: 422, json: { code: 'course_has_history', reasons: ['sign_in', 'held_session'],
              message: '這門課已有點名、上課或請假紀錄，不能刪除；尚未收款的費用不會自動免除。' } });
          }
          if (request.method() === 'POST' && path === '/api/v1/student-classes/7101/pause') {
            target.closed_reason = 'settled_pending';
            return route.fulfill({ json: { pending_reconciliation: true } });
          }
          return route.fulfill({ status: 500, json: { message: 'unexpected write' } });
        }
        if (path === '/api/v1/student-classes') return route.fulfill({ json: { data: [target], total: 1, current_page: 1, last_page: 1 } });
        if (path === '/api/v1/students') return route.fulfill({ json: { data: [{ id: 7201, name: '合成結案學生' }] } });
        return route.fulfill({ json: { data: [], total: 0 } });
      });
      await page.goto('/pilot-mount.html?page=course');
      const row = page.locator('[data-contract-id="7101"]');
      await expect(row).toBeVisible();
      await row.locator('.action-menu-trigger').click();
      if (closedReason) {
        await expect(page.getByRole('menuitem', { name: '結束課程（不再續課）…', exact: true })).toHaveCount(0);
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
        return;
      }
      // The normal menu also exposes close while merely paused.
      await expect(page.getByRole('menuitem', { name: '結束課程（不再續課）…', exact: true })).toBeVisible();
      await page.getByRole('menuitem', { name: '刪除課程…', exact: true }).click();
      const deletion = page.getByTestId('delete-course-dialog');
      await deletion.getByRole('button', { name: '刪除課程', exact: true }).click();
      await expect(page.getByTestId('delete-course-refusal')).toContainText('點名');
      await expect(deletion).toContainText('尚未收款的費用不會自動免除');
      await expect(deletion.getByRole('button', { name: '刪除課程', exact: true })).toHaveCount(0);
      await deletion.getByRole('button', { name: '結束課程（保留紀錄）', exact: true }).click();
      const confirmation = page.getByRole('alertdialog', { name: '結束數學？', exact: true });
      await expect(confirmation).toContainText('放棄這 3 堂剩餘額度');
      await expect(confirmation).toContainText('不會視為已收');
      await expect(confirmation).toContainText('已繳費與已上課紀錄仍會保留');
      await confirmation.getByRole('button', { name: cancelClose ? '取消' : '結束課程', exact: true }).click();
      if (!cancelClose) {
        await expect.poll(() => writes.length).toBe(2);
        expect(writes[1]).toEqual({ method: 'POST', path: '/api/v1/student-classes/7101/pause',
          body: { action: 'pause', reason: 'settled', forfeit_remaining: true } });
      }
      expect(writes).toHaveLength(cancelClose ? 1 : 2);
      expect(writes[0]).toMatchObject({ method: 'DELETE', path: '/api/v1/student-classes/7101' });
      expect(writes.some(write => /resume|waive|payment|correction/.test(write.path) || write.body?.action === 'resume')).toBe(false);
      expect(target.UsedSessions).toBe(5);
      expect(target.charge).toBe(4000);
      expect(errors).toEqual([]);
    });
  }
}

function course(id, studentId, studentName, paymentStatus, legacyFlagOnly = false) {
  return {
    legacy_paid_flag_only: legacyFlagOnly,
    id, student_id: studentId, student_name: studentName, teacher_name: '合成老師', TeacherID: 7301,
    subject: '數學', subject_name: '數學', class_type: 'one_on_one', payment_type: 'count', schedule_mode: 'count',
    SessionCount: 8, UsedSessions: 2, RemainingSessions: 6, payment_status: paymentStatus, charge: 4000,
    paid_amount: paymentStatus === 'paid' && !legacyFlagOnly ? 4000 : 0, has_valid_payment: paymentStatus === 'paid' && !legacyFlagOnly,
    latest_payment_report_id: paymentStatus === 'pending_report' ? 7401 : null,
    days_of_week: [2], start_time: '17:00', end_time: '18:00', status: 'active', branch_id: 1,
  };
}

for (const { paymentStatus, legacyFlagOnly = false, actionLabel, badge } of STATES) {
  test(`director 課程查找 ${paymentStatus}${legacyFlagOnly ? ' (legacy flag only)' : ''}: payment badge reads ${badge} and ${actionLabel} opens that course's billing in place`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort('blockedbyclient'));
    const target = course(7101, 7201, '合成目標學生', paymentStatus, legacyFlagOnly);
    const other = course(7102, 7202, '合成其他學生', paymentStatus === 'paid' ? 'unpaid' : 'paid');
    const ledgerIds = [];
    /** @type {string[]} */
    const writes = [];
    await page.route('**/api/v1/**', async (route) => {
      const url = new URL(route.request().url());
      const p = url.pathname;
      const method = route.request().method();
      if (method !== 'GET') {
        if (method === 'POST' && READ_ONLY_POSTS.has(p)) {
          await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
          return;
        }
        // The payment entry must stay read-only: record any other write and fail it
        // so the assertion below (not a silent 200) decides the test.
        writes.push(`${method} ${p}`);
        await route.fulfill({ status: 500, json: { message: `unexpected ${method} in read-only payment entry test` } });
        return;
      }
      if (p.endsWith('/me')) {
        await route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
        return;
      }
      if (p.endsWith('/branches') || p.endsWith('/campuses')) {
        await route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
        return;
      }
      if (p.includes('/notifications')) {
        await route.fulfill({ json: { data: [], unread_count: 0 } });
        return;
      }
      if (p === '/api/v1/student-classes') {
        await route.fulfill({ json: { data: [other, target], current_page: 1, last_page: 1, total: 2 } });
        return;
      }
      if (p === '/api/v1/accounting/ledger') {
        const requested = Number(url.searchParams.get('student_class_id'));
        ledgerIds.push(requested);
        const match = [target, other].find((candidate) => candidate.id === requested);
        if (!match) {
          await route.fulfill({ status: 404, json: { message: 'not found' } });
          return;
        }
        // An unpaid/legacy course carries its ledger card and open bill so the side sheet can open (same guards as the real ledger).
        const entry = ['登記收款', '補登收款'].includes(actionLabel) && match === target;
        const open = [{ id: 9001, student_class_id: match.id, status: 'unpaid', outstanding_amount: 4000, total_amount: 4000, due_date: '2026-10-01', payments: [] }];
        await route.fulfill({ json: {
          student: { id: match.student_id, name: match.student_name },
          scope: { student_class_id: match.id },
          courses: entry ? [{ id: match.id, subject: match.subject, schedule_mode: 'count', charge: 4000, paid: legacyFlagOnly, stop: false, package_id: null, payment_status: match.payment_status }] : [],
          invoices: entry && !legacyFlagOnly ? open : [], payments: [], reports: [], receipts: [], anomalies: [],
        } });
        return;
      }
      await route.fulfill({ json: { data: [] } });
    });

    const courses = recordCourseLists(page);
    await page.goto('/pilot-mount.html?page=app');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    await page.locator('nav.sidebar-nav').getByRole('button', { name: '課程查找' }).click();
    const heading = page.getByRole('heading', { name: '課程查找', exact: true }).first();
    await expect(heading).toBeVisible({ timeout: 15_000 });

    // In-app #532: the payment badge stays inside its own column, so the row's buttons never cover its text.
    const badgeCell = page.locator(`.payment-status-and-action[data-course-id="${target.id}"] .payment-status-badge`);
    await expect(badgeCell).toBeVisible({ timeout: 15_000 });
    const badgeBox = await badgeCell.boundingBox();
    const actionsBox = await page.locator(`.course-row:has(.payment-status-and-action[data-course-id="${target.id}"]) [data-testid="course-row-actions"]`).boundingBox();
    expect(badgeBox.x + badgeBox.width, 'payment badge must end before the row actions start').toBeLessThanOrEqual(actionsBox.x + 1);

    await expectCoursePaymentEntryOpensLedgerInPlace(page, { pageHeading: heading, courses, actionLabel });
    await expect(page.locator(`.payment-status-and-action[data-course-id="${target.id}"] .payment-status-badge`)).toHaveText(badge);
    expect(ledgerIds).toEqual([target.id]);
    if (['登記收款', '補登收款'].includes(actionLabel)) {
      // Side sheet: the form shows this student's course and the bill amount; the list is still on screen behind it.
      const sheet = page.locator('.at-dialog__panel--sheet');
      await expect(sheet).toContainText(target.student_name);
      if (!legacyFlagOnly) await expect(sheet).toContainText('4,000');
      await expect(page.locator(`.payment-status-and-action[data-course-id="${target.id}"]`)).toBeVisible();
    } else {
      await expect(page.locator('#ledger-modal-title')).toHaveText(`${target.student_name}的帳務`);
    }
    expect(writes, 'payment entry must not issue any non-GET API request').toEqual([]);
  });
}

// In-app #538 / #539: layout of the 課程查找 header help and the review-required payment chip at phone, tablet and desktop widths.
// The page is opened at desktop width (the sidebar is reliable there) and then resized, so only CSS decides the layout.
for (const width of [375, 768, 1280]) {
  test(`課程查找 at ${width}px: opened help spans the header, no orphan summary card, review badge stays on one line`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort('blockedbyclient'));
    const review = course(7301, 7401, '合成核對學生', 'review_required');
    const paid = course(7302, 7402, '合成已收學生', 'paid');
    await page.route('**/api/v1/**', async (route) => {
      const p = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
        return;
      }
      if (p.endsWith('/me')) {
        await route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      } else if (p.endsWith('/branches') || p.endsWith('/campuses')) {
        await route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      } else if (p.includes('/notifications')) {
        await route.fulfill({ json: { data: [], unread_count: 0 } });
      } else if (p === '/api/v1/student-classes') {
        await route.fulfill({ json: { data: [review, paid], current_page: 1, last_page: 1, total: 2 } });
      } else {
        await route.fulfill({ json: { data: [] } });
      }
    });
    await page.goto('/pilot-mount.html?page=app');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    await page.locator('nav.sidebar-nav').getByRole('button', { name: '課程查找' }).click();
    const badge = page.locator(`.payment-status-and-action[data-course-id="${review.id}"] .payment-status-badge`);
    const paidBadge = page.locator(`.payment-status-and-action[data-course-id="${paid.id}"] .payment-status-badge`);
    await expect(badge).toBeVisible({ timeout: 15_000 });
    await page.setViewportSize({ width, height: 900 });

    // #539: short chip on one line (same height as the 已收 chip); the sentence stays as title and accessible name.
    await expect(badge).toHaveText(REVIEW_REQUIRED_SHORT_LABEL);
    await expect(badge).toHaveAttribute('aria-label', REVIEW_REQUIRED_LABEL);
    await expect(badge).toHaveAttribute('title', new RegExp(REVIEW_REQUIRED_LABEL));
    const [badgeBox, paidBox] = [await badge.boundingBox(), await paidBadge.boundingBox()];
    expect(Math.abs(badgeBox.height - paidBox.height), 'review chip is one line like the 已收 chip').toBeLessThanOrEqual(2);

    // #538: the opened help is as wide as the header content and no summary row holds a single card.
    const header = page.locator('[data-guide="course-mgmt-header"]');
    await page.locator('[data-testid="course-help"] > summary').click();
    const help = page.locator('[data-testid="course-help"]');
    await expect(help).toHaveAttribute('open', '');
    const [headerBox, helpBox] = [await header.boundingBox(), await help.boundingBox()];
    const pad = await header.evaluate((el) => { const cs = getComputedStyle(el); return parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight); });
    expect(Math.abs(helpBox.width - (headerBox.width - pad)), 'help spans the header content width').toBeLessThanOrEqual(2);
    // A row holding one card is an orphan unless that card spans the whole grid width (the intended 2 + 2 + 1 on phones).
    const rows = await page.locator('[data-testid="course-lens-summary"] > *').evaluateAll((cards) => {
      const gridWidth = cards[0].parentElement.getBoundingClientRect().width;
      const byTop = new Map();
      for (const c of cards) {
        const r = c.getBoundingClientRect();
        const row = byTop.get(Math.round(r.top)) || { count: 0, full: false };
        row.count += 1;
        row.full = r.width >= gridWidth - 2;
        byTop.set(Math.round(r.top), row);
      }
      return [...byTop.values()];
    });
    expect(rows.length, 'summary cards rendered').toBeGreaterThan(0);
    expect(rows.filter((row) => row.count === 1 && !row.full), 'single-card rows that do not span the grid').toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'no horizontal page scroll').toBe(true);
  });
}

// In-app #548: the printed warning and all dates remain reachable on a phone.
for (const [recordCount, lastStatus] of [[12, 'scheduled'], [9, 'leave_adjusted']]) {
for (const width of [375, 1280]) {
  test(`繳費通知 ${recordCount} 筆紀錄、提示與日期可讀 at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.continue() : route.abort());
    await page.route('**/api/v1/**', (route) => {
      expect(route.request().method()).toBe('GET');
      expect(new URL(route.request().url()).pathname).toBe('/api/v1/alerts/tuition-slip/9');
      return route.fulfill({ json: {
        student_class_id: 9, student_name: '隔離測試學生', subject: '英文', schedule_mode: 'count',
        sessions_purchased: 8, remaining_sessions: 4, estimated_amount: 8000,
        deduction_basis: 'fixed_session', scheduling_policy: 'auto_recurrence', package_id: null,
        sessions: Array.from({ length: recordCount }, (_, i) => ({ class_session_id: i + 1,
          date: `2026-11-${String(i + 1).padStart(2, '0')}`, status: i < 4 ? 'attended' : (i === recordCount - 1 ? lastStatus : 'scheduled'),
          start_time: '13:00', end_time: '15:00' })),
      } });
    });
    await page.goto('/payment-slip-pilot-mount.html');
    await page.getByRole('button', { name: '預覽繳費通知' }).click();
    if (recordCount === 12) {
      await expect(page.getByTestId('slip-planning-notice')).toContainText('已超出購買堂數 4 堂');
    } else {
      await expect(page.getByTestId('slip-planning-notice')).toHaveCount(0);
      await expect(page.locator('.slip-status').last()).toHaveText('補請假');
    }
    await expect(page.locator('.slip-section-title')).toContainText(`共 ${recordCount} 筆`);
    await expect(page.locator('.slip-section-title')).toContainText('已上 4 堂');
    await expect(page.locator('.slip-session-list li')).toHaveCount(recordCount);
    const wrap = page.locator('.slip-preview-wrap');
    const left = await wrap.evaluate(el => el.getBoundingClientRect().left);
    const paper = await page.locator('article.slip').boundingBox();
    expect(paper.x).toBeGreaterThanOrEqual(left);
    expect(paper.width).toBe(580);
    await page.screenshot({ path: testInfo.outputPath('slip-left.png') });
    if (width === 375) {
      expect(await wrap.evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
      await wrap.evaluate(el => { el.scrollLeft = el.scrollWidth; });
      const after = await page.locator('article.slip').boundingBox();
      expect(after.x).toBeLessThan(paper.x);
      const status = await page.locator('.slip-status').last().boundingBox();
      expect(status.x).toBeGreaterThanOrEqual(left);
      expect(status.x + status.width).toBeLessThanOrEqual(left + await wrap.evaluate(el => el.clientWidth) + 1);
    }
    await page.screenshot({ path: testInfo.outputPath('slip-status.png') });
    await page.getByTestId('slip-modal-panel').focus();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });
}
}
