import { test, expect } from '@playwright/test';

// 收據移到正確帳單 (#5113): fake-data screenshot evidence on the built preview app. Every /api/ call is stubbed here (nothing leaves the
// page: other origins are aborted); the data is synthetic. Receipt 7 sits on the wrong student's bill; bill 21 can take it, bill 22 is a
// second choice. Receipt 8 is over the director's limit (the move goes to HQ).
const ORIGIN = 'http://127.0.0.1:5191';
const course = {
  id: 9001, student_id: 901, student_name: '合成學生', subject: 'math', subject_name: '數學', teacher_id: 902, teacher_name: '合成老師',
  branch_id: 16, class_type: 'one_on_one', payment_type: 'session', payment_status: 'unpaid', status: 'active',
  sessions_purchased: 4, remaining_sessions: 4, rate_per_30min: 2500, days_of_week: [2], start_time: '20:00', end_time: '22:00',
};
const receipt = (id, amount) => ({ report_id: id, student_class_id: 9001, amount, status: 'confirmed', payment_date: '2026-11-10', payment_method: 'transfer', invoice_id: null });
const anomaly = (id) => ({ code: 'receipt_without_invoice', severity: 'warning', message: '收據已確認，但帳單上找不到對應的收款', report_id: id, action: { type: 'void_report', label: '撤銷收據', report_id: id } });
const TARGETS = { amount: 3000, student_id: 901, targets: [
  { invoice_id: 21, course_id: 9001, subject: '數學', billing_period: '2026-11', total: 3000, remaining: 3000, status: 'unpaid' },
  { invoice_id: 22, course_id: 9002, subject: '英文', billing_period: '2026-12', total: 4000, remaining: 4000, status: 'unpaid' },
] };
const PLAN = { preview: true, amount: 3000, needs_role: 'director', report_age_days: 4, state_hash: 'v2:' + 'h'.repeat(64), warnings: ['source_bill_becomes_unpaid'],
  from: { student_id: 902, course_id: 9100, invoice_id: 20, balance_before: 0, balance_after: 3000 },
  to: { student_id: 901, course_id: 9001, invoice_id: 21, balance_before: 3000, balance_after: 0 } };

async function install(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'synthetic-director', user: { id: 900, role: 'director', name: '合成主任' } }));
    localStorage.setItem('app_branch', '16');
  });
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== ORIGIN) return route.abort();
    if (url.pathname === '/api/preview-config') return route.fulfill({ json: { proxy: true } }); // the preview then lets /api/v1/ reach this stub
    if (!url.pathname.startsWith('/api/')) return route.continue();
    const path = url.pathname;
    const json = (body, status = 200) => route.fulfill({ status, json: body });
    const hit = /\/payment-reports\/(\d+)\/reassign(-targets)?$/.exec(path);
    if (hit && hit[2]) return json(TARGETS);
    if (hit && request.method() === 'POST') {
      if (hit[1] === '8') return json({ code: 'reassign_needs_super_admin', message: 'x', needs_role: 'super_admin', hq_reason: 'amount' }, 422);
      return json(PLAN);
    }
    if (request.method() !== 'GET') return json({ ok: true });
    if (path === '/api/v1/me') return json({ id: 900, name: '合成主任', role: 'director', campuses: [16], must_change_password: false, capabilities: [] });
    if (/\/(branches|campuses)$/.test(path)) return json([{ id: 16, name: '合成分校' }]);
    if (path === '/api/v1/student-classes') return json({ data: [course], current_page: 1, last_page: 1, total: 1 });
    if (path === '/api/v1/accounting/ledger') {
      return json({ summary: {}, scope: { student_class_id: 9001 }, student: { id: 901, name: '合成學生', campus_id: 16 }, can_reassign_receipt: true,
        courses: [{ id: 9001, subject: '數學', start_date: '2026-11-01' }], invoices: [], receipts: [receipt(7, 3000), receipt(8, 90000)], anomalies: [anomaly(7), anomaly(8)] });
    }
    if (path.includes('/class-sessions')) return json({ api_kind: 'projection', completeness: 'full', by_class: {}, data: [] });
    if (path.includes('/teachers')) return json({ data: [{ id: 902, username: '合成老師', status: 'active', branch_id: 16 }] });
    if (path.includes('/students')) return json({ data: [{ id: 901, name: '合成學生' }] });
    return json({ data: [], total: 0 });
  });
  return errors;
}

async function openLedger(page) {
  await page.goto(`${ORIGIN}/?app_page=course-mgmt`);
  await expect(page.getByRole('heading', { name: '課程查找', exact: true })).toBeVisible();
  const direct = page.getByRole('button', { name: '在這裡看帳務' }).first();
  if (await direct.isVisible().catch(() => false)) await direct.click();
  else {
    await page.locator('.action-menu-trigger').first().click();
    await page.getByRole('menuitem', { name: /在這裡看帳務/ }).first().click();
  }
  await expect(page.getByTestId('ledger-move-7')).toBeVisible();
}

test('收據移到正確帳單：選帳單、看結果、填原因；超過上限改由總部', async ({ page }, testInfo) => {
  const errors = await install(page);
  const shot = async (name) => {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: false });
  };
  await openLedger(page);
  await expect(page.getByTestId('ledger-move-hint-7')).toHaveText('這張收據記在別人的帳單上。可以直接移到正確的帳單。');
  await shot('1-ledger-card-with-button');

  await page.getByTestId('ledger-move-7').click();
  await expect(page.getByTestId('rm-target-21')).toContainText('數學 · 2026/11');
  const box = await page.getByTestId('rm-target-21').boundingBox();
  expect(box.height).toBeGreaterThanOrEqual(44);
  await shot('2-pick-the-bill');

  await page.getByTestId('rm-target-21').click();
  await expect(page.getByTestId('rm-plan')).toContainText('NT$ 3,000');
  await expect(page.getByTestId('rm-apply')).toBeDisabled();
  await page.getByTestId('rm-chip-選錯學生').click();
  await expect(page.getByTestId('rm-apply')).toBeEnabled();
  await shot('3-review-reason-confirm');
  await page.keyboard.press('Escape');

  await page.getByTestId('ledger-move-8').click();
  await page.getByTestId('rm-target-21').click();
  await expect(page.getByTestId('rm-refusal')).toContainText('超過主任可以自行處理的上限');
  await expect(page.getByTestId('rm-ask-hq')).toBeVisible();
  await expect(page.getByTestId('rm-apply')).toHaveCount(0);
  await shot('4-over-the-limit-goes-to-hq');
  expect(errors).toEqual([]);
});
