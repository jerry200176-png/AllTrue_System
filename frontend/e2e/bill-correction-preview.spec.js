import { test, expect } from '@playwright/test';

// 更正帳單 (step 2): fake-data screenshot evidence on the built preview app. Every /api/ call is stubbed here (nothing leaves the
// page: other origins are aborted); the data is synthetic. Bills: 5 monthly (server priced, with an added lesson), 6 count (director
// types the total), 7 a dead refusal that ends in 回報給總部.
const ORIGIN = 'http://127.0.0.1:5190';
const course = {
  id: 9001, student_id: 901, student_name: '合成學生', subject: 'math', subject_name: '數學', teacher_id: 902, teacher_name: '合成老師',
  branch_id: 16, class_type: 'one_on_one', payment_type: 'session', payment_status: 'unpaid', status: 'active',
  sessions_purchased: 4, remaining_sessions: 4, rate_per_30min: 2500, days_of_week: [2], start_time: '20:00', end_time: '22:00',
};
const bill = (o) => ({ id: 5, invoice_no: 'INV-5', student_class_id: 9001, status: 'partial', total_amount: 4000, paid_amount: 2000, payments: [], reports: [], can_correct: true, ...o });
const MONTHLY = { shape: 'monthly', invoice_id: 5, old_total: 4000, new_total: 5200, moved_amount: 2000, outstanding_after: 3200, overpaid_after: 0, status_after: 'partial',
  added_lessons: ['2026-11-10', '2026-11-17'], range_shortfall: 0, period_end_after: '2026-11-17', state_hash: 'h'.repeat(64) };
const COUNT = { shape: 'count', invoice_id: 6, old_total: 6000, new_total: 4800, moved_amount: 6000, outstanding_after: 0, overpaid_after: 1200, status_after: 'paid',
  added_lessons: [], range_shortfall: 0, period_end_after: null, state_hash: 'c'.repeat(64) };

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
    const hit = /\/invoices\/(\d+)\/correction\/preview$/.exec(path);
    if (hit && request.method() === 'POST') {
      const body = request.postDataJSON() ?? {};
      if (hit[1] === '5') return json(MONTHLY);
      if (hit[1] === '6') return body.new_total ? json({ ...COUNT, new_total: body.new_total }) : json({ code: 'new_total_required', message: 'x' }, 422);
      return json({ code: 'paid_state_unverified', message: 'x' }, 422);
    }
    if (request.method() !== 'GET') return json({ ok: true });
    if (path === '/api/v1/me') return json({ id: 900, name: '合成主任', role: 'director', campuses: [16], must_change_password: false, capabilities: [] });
    if (/\/(branches|campuses)$/.test(path)) return json([{ id: 16, name: '合成分校' }]);
    if (path === '/api/v1/student-classes') return json({ data: [course], current_page: 1, last_page: 1, total: 1 });
    if (path === '/api/v1/accounting/ledger') {
      return json({ summary: {}, scope: { student_class_id: 9001 }, student: { name: '合成學生' }, receipts: [], anomalies: [],
        invoices: [bill({ id: 5 }), bill({ id: 6, invoice_no: 'INV-6', status: 'paid', total_amount: 6000, paid_amount: 6000 }), bill({ id: 7, invoice_no: 'INV-7', status: 'unpaid', total_amount: 3000, paid_amount: 0 })] });
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
  await expect(page.getByTestId('ledger-correct-5')).toBeVisible();
}

test('更正帳單：月結預覽、堂數制輸入總額、回報總部的死路', async ({ page }, testInfo) => {
  const errors = await install(page);
  const shot = async (name) => {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: false });
  };
  await openLedger(page);
  await shot('1-ledger-with-button');
  await expect(page.getByTestId('ledger-correct-5')).toHaveText('更正帳單');

  // monthly: the server priced it, one added-lesson line, nothing to type but the reason
  await page.getByTestId('ledger-correct-5').click();
  await expect(page.getByTestId('bc-plan')).toContainText('原本 NT$ 4,000，更正後 NT$ 5,200');
  await expect(page.getByTestId('bc-lessons')).toContainText('2026-11-10、2026-11-17');
  await expect(page.getByTestId('bc-new-total')).toHaveCount(0);
  await page.getByTestId('bc-reason').fill('補上漏收的課');
  await shot('2-monthly-preview');
  await page.keyboard.press('Escape');

  // count: type the new total, then the preview
  await page.getByTestId('ledger-correct-6').click();
  await page.getByTestId('bc-new-total').fill('4800');
  await page.getByTestId('bc-preview').click();
  await expect(page.getByTestId('bc-plan')).toContainText('更正後 NT$ 4,800');
  await shot('3-count-typed-total');
  await page.keyboard.press('Escape');

  // a dead refusal: no retry, only 回報給總部
  await page.getByTestId('ledger-correct-7').click();
  await expect(page.getByTestId('bc-refusal')).toContainText('請回報總部');
  await expect(page.getByTestId('bc-ask-hq')).toBeVisible();
  await expect(page.getByTestId('bc-apply')).toHaveCount(0);
  await shot('4-dead-refusal-ask-hq');
  expect(errors).toEqual([]);
});
