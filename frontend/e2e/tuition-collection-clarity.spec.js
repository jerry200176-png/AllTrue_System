import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';

for (const width of [390, 1440]) for (const theme of ['light', 'dark']) {
  test(`In-App564: grouped contract periods keep owed priority and every record ${theme} @${width}`, async ({ page }, info) => {
    const errors = []; const writes = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/api/v1/') && r.method() !== 'GET') writes.push(r.url()); });
    await page.setViewportSize({ width, height: 950 });
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.addInitScript((theme) => {
      document.addEventListener('DOMContentLoaded', () => { document.documentElement.classList.toggle('dark', theme === 'dark'); document.documentElement.dataset.theme = theme; }, { once: true });
    }, theme);
    await page.route('**/*', (r) => ['127.0.0.1', 'localhost'].includes(new URL(r.request().url()).hostname) ? r.fallback() : r.abort());
    await page.route('**/api/v1/**', (r) => r.fulfill({ json: { data: [] } }));
    await installMock(page);
    const c = (id, subject, start_date, paid = true) => ({ id, subject, start_date, schedule_mode: 'count', paid, charge: 1000, stop: false });
    const courses = [c(1, '數學', '2026-08-08'), c(2, '英文', '2026-09-23'), c(3, '數學', '2026-10-08', false),
      c(4, '化學', '2026-10-02', false), c(5, '英文', '2026-10-07', false), c(6, '數學', '2026-10-09'), c(7, '英文', '2026-01-01', false)];
    await page.route('**/api/v1/students**', (r) => r.fulfill({ json: [{ id: 99, name: '合成排序學生', status: 'active', enable: 1 }] }));
    await page.route('**/api/v1/alerts/tuition**', (r) => r.fulfill({ json: [{ id: 6, student_id: 99, student_name: '合成排序學生', subject: '數學',
      schedule_mode: 'count', payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 1000 }] }));
    await page.route('**/api/v1/accounting/ledger?**', (r) => r.fulfill({ json: {
      student: { id: 99, name: '合成排序學生' }, scope: { student_class_id: 6 }, summary: {}, courses, receipts: [], anomalies: [],
      invoices: [{ id: 17, student_class_id: 7, status: 'unpaid', total_amount: 1000, outstanding_amount: 1000, calculated_applied_amount: 0, payments: [] }],
    } }));
    await page.route('**/api/v1/accounting/contracts/*/sessions', (r) => {
      const id = Number(new URL(r.request().url()).pathname.split('/').at(-2));
      return r.fulfill({ json: { ...courses.find((x) => x.id === id), sessions: [] } });
    });
    await page.goto('/tuition-collection-pilot-mount.html');
    await page.getByTestId('bill-search').fill('合成排序學生');
    await page.getByTestId('bill-documents-c6').click();
    const ledger = page.getByRole('dialog', { name: '合成排序學生的帳務', exact: true });
    const cards = ledger.locator('.ledger-contracts > [data-testid^="contract-card-"]');
    await expect(cards).toHaveCount(7);
    const ids = await cards.evaluateAll((els) => els.map((el) => Number(el.dataset.testid.replace('contract-card-', ''))));
    expect(ids[0]).toBe(7);
    expect(ids.filter((id) => [1, 3, 6].includes(id))).toEqual([6, 3, 1]);
    expect(ids.filter((id) => [2, 5].includes(id))).toEqual([5, 2]);
    expect(ids.indexOf(1) - ids.indexOf(6)).toBe(2);
    await expect(ledger.getByTestId('contract-focused')).toBeVisible();
    await expect(ledger.getByTestId('contract-card-3').getByTestId('contract-record')).toBeVisible();
    const scan = await new AxeBuilder({ page }).include('.ledger-contracts').analyze();
    expect(scan.violations.filter((v) => ['serious', 'critical'].includes(v.impact))).toEqual([]);
    await ledger.getByTestId('contract-card-6').getByTestId('contract-toggle').click();
    await ledger.evaluate((el) => { el.scrollTop = 0; });
    const shot = info.outputPath(`contract-order-${width}.png`);
    await ledger.screenshot({ path: shot }); await info.attach('contract-order', { path: shot, contentType: 'image/png' });
    expect(writes).toEqual([]); expect(errors).toEqual([]);
  });
}

// #4148: real page -> scoped ledger -> existing receipt modal, synthetic data only.
for (const width of [375, 1280]) for (const [size, theme] of [['standard', 'light'], ['large', 'light'], ['standard', 'dark']]) {
  test(`4148 linked and historic receipt lookup ${size}/${theme} @${width}`, async ({ page }, info) => {
    const errors = []; const writes = []; const receiptIds = []; const ledgerQueries = []; const coverage = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => {
      const u = new URL(r.url());
      if (u.pathname.startsWith('/api/v1/') && r.method() !== 'GET') writes.push(u.pathname);
      if (/^\/api\/v1\/invoices\/.*\/slip-data$/.test(u.pathname)) coverage.push(u.pathname);
    });
    await page.setViewportSize({ width, height: 900 });
    await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
    await page.addInitScript(({ size, theme }) => {
      localStorage.setItem('alltrue_text_size', size);
      const apply = () => { document.documentElement.dataset.textSize = size; document.documentElement.classList.toggle('dark', theme === 'dark'); document.documentElement.dataset.theme = theme; };
      document.addEventListener('DOMContentLoaded', apply, { once: true });
    }, { size, theme });
    await page.route('**/*', r => ['127.0.0.1', 'localhost'].includes(new URL(r.request().url()).hostname) ? r.fallback() : r.abort());
    await page.route('**/api/v1/**', r => {
      return r.fulfill({ json: { data: [] } });
    });
    await installMock(page);
    await page.route('**/api/v1/students**', r => r.fulfill({ json: [{ id: 5, name: '合成學生', status: 'active', enable: 1 }, { id: 99, name: '合成暫停學生', status: 'paused', enable: 1 }] }));
    await page.route('**/api/v1/alerts/tuition**', r => r.fulfill({ json: [{ id: 42, student_id: 5, student_name: '合成學生', subject: '合成數學', schedule_mode: 'count', payment_status: 'unpaid', payable_status: 'invoiced', payable_outstanding: 1000, remaining_sessions: 4 }] }));
    await page.route('**/api/v1/accounting/ledger?**', r => {
      const q = new URL(r.request().url()).searchParams; ledgerQueries.push(Object.fromEntries(q));
      const historic = q.get('student_id') === '99';
      return r.fulfill({ json: { student: { id: historic ? 99 : 5, name: historic ? '合成暫停學生' : '合成學生' }, scope: { student_class_id: historic ? null : 42 }, summary: {}, courses: [], anomalies: [], invoices: historic ? [] : [{ id: 501, invoice_no: '合成帳單501', subject_name: '合成數學', first_session_date: '2026-11-01', billing_period: '2026-11', student_class_id: 42, status: 'partial', total_amount: 2000, calculated_applied_amount: 1000, outstanding_amount: 1000, payments: [71, 72].map((id, i) => ({ id, report_id: 801 + i, report_status: 'confirmed', receipt_no: `合成收據${801 + i}`, amount: 500, applied_amount: 500, application_status: 'applied', method: 'cash', paid_at: '2026-11-01' })) }], receipts: historic ? [{ report_id: 900, invoice_id: null, amount: 200, status: 'confirmed', payment_method: 'cash' }] : [801, 802].map(report_id => ({ report_id, invoice_id: 501, student_class_id: 42, amount: 500, status: 'confirmed', payment_method: 'cash', subject: '合成數學' })) } });
    });
    await page.route('**/api/v1/payment-reports/*/receipt', r => {
      const id = Number(new URL(r.request().url()).pathname.split('/').at(-2)); receiptIds.push(id);
      return r.fulfill({ json: { receipt_no: `合成收據${id}`, amount: 500, payment_date: '2026-11-01', payment_method: 'cash', status: 'confirmed', student_name: '合成學生', subject: '合成數學' } });
    });
    await page.goto('/tuition-collection-pilot-mount.html');
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    await page.getByTestId('bill-search').fill('合成學生');
    await page.getByTestId('bill-documents-c42').focus();
    await page.keyboard.press('Enter');
    const ledger = page.getByRole('dialog', { name: '合成學生的帳務', exact: true });
    const linked = page.getByTestId('ledger-invoice-payments-501');
    await expect(linked.getByRole('button', { name: /查看收據/ })).toHaveCount(2);
    await page.getByTestId('ledger-payment-receipt-72').focus(); await page.keyboard.press('Enter');
    const receipt = page.getByTestId('receipt-modal-panel');
    await expect(receipt).toContainText('合成收據802');
    await receipt.locator('button[title="關閉"]').click();
    await expect(page.getByTestId('ledger-payment-receipt-72')).toBeFocused();
    await page.getByTestId('ledger-invoice-501').hover();
    const scan = await new AxeBuilder({ page }).include('#ledger-sec-invoices').analyze();
    expect(scan.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([]);
    const linkedShot = info.outputPath(`linked-${width}-${size}-${theme}.png`);
    await ledger.screenshot({ path: linkedShot });
    await info.attach(`linked-${width}-${size}-${theme}`, { path: linkedShot, contentType: 'image/png' });
    await ledger.getByRole('button', { name: '關閉學生帳務', exact: true }).click();
    await expect(page.getByTestId('bill-search')).toHaveValue('合成學生');
    await expect(page.getByTestId('bill-documents-c42')).toBeFocused();
    await page.getByTestId('bill-search').fill('合成暫停學生');
    await expect(page.getByTestId('bill-student-99')).toHaveCount(0);
    await page.getByTestId('bill-find-receipts').click();
    await page.getByTestId('bill-document-student-99').click();
    const historic = page.getByRole('dialog', { name: '合成暫停學生的帳務', exact: true });
    await page.getByTestId('ledger-receipt-view-900').click();
    await expect(receipt).toContainText('合成收據900');
    await receipt.locator('button[title="關閉"]').click();
    await historic.getByRole('button', { name: '關閉學生帳務', exact: true }).click();
    await expect(page.getByTestId('bill-search')).toHaveValue('合成暫停學生');
    await expect(page.getByTestId('bill-document-student-99')).toBeFocused();
    const searchScan = await new AxeBuilder({ page }).include('[data-testid="bill-receipt-search"]').analyze();
    expect(searchScan.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    const historicShot = info.outputPath(`historic-${width}-${size}-${theme}.png`);
    await page.getByTestId('bill-receipt-search').screenshot({ path: historicShot });
    await info.attach(`historic-${width}-${size}-${theme}`, { path: historicShot, contentType: 'image/png' });
    expect(ledgerQueries).toEqual([{ student_class_id: '42', branch_id: '1' }, { student_id: '99', branch_id: '1' }]);
    expect(receiptIds).toEqual([802, 900]);
    expect(coverage).toEqual([]); expect(writes).toEqual([]); expect(errors).toEqual([]);
  });
}

for (const width of [375, 1280]) {
  test(`In-App549: paused empty lookup disappears after refresh, real bills remain @${width}`, async ({ page }, info) => {
    let paused = false; let studentReads = 0;
    const apiMethods = [];
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/*', route => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort());
    page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/api/v1/')) apiMethods.push(request.method()); });
    await installMock(page);
    await page.route('**/api/v1/students**', route => {
      studentReads += 1;
      expect(new URL(route.request().url()).searchParams.get('branch_id')).toBe('1');
      return route.fulfill({ json: [
        { id: 99, name: '合成學生暫停', status: paused ? 'paused' : 'active' },
        { id: 100, name: '合成學生在學', status: 'active' },
        { id: 101, name: '合成學生有欠款', status: 'paused' },
      ] });
    });
    await page.route('**/api/v1/alerts/tuition**', route => route.fulfill({ json: [{
      id: 9301, student_id: 101, student_name: '合成學生有欠款', subject: '數學',
      schedule_mode: 'count', payment_status: 'unpaid', payable_status: 'invoiced',
      payable_outstanding: 1000, remaining_sessions: 4,
    }] }));
    await page.goto('/tuition-collection-pilot-mount.html');
    await page.getByTestId('bill-search').fill('合成學生');
    await expect(page.getByTestId('bill-student-99')).toBeVisible();
    await expect(page.getByTestId('bill-row-c9301')).toContainText('1,000');
    paused = true;
    await page.getByRole('button', { name: '重新整理', exact: true }).click();
    await expect.poll(() => studentReads).toBe(2);
    await expect(page.getByTestId('bill-student-100')).toBeVisible();
    await expect(page.getByTestId('bill-student-99')).toHaveCount(0);
    await expect(page.getByTestId('bill-row-c9301')).toContainText('1,000');
    expect(apiMethods.every(method => method === 'GET')).toBe(true);
    await expectNoOverflowAndReachableControls(page);
    await page.screenshot({ path: info.outputPath(`paused-empty-${width}.png`), fullPage: true });
  });
}
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.TUITION_COLLECTION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/tuition-collection-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];
const rows = [
  {
    id: 101, student_id: 9101, student_name: '林宥辰', subject: '國中數學', schedule_mode: 'date', payment_status: 'unpaid',
    charge: 4200, paid_amount: 0, outstanding: 4200, payable_amount: 4200, payable_outstanding: 4200, due_date: '2026-09-12', days_until_settlement: 2,
    course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 501, payable_status: 'invoiced',
  },
  {
    id: 102, student_id: 9102, student_name: '陳品妤', subject: '高中英文', schedule_mode: 'count', payment_status: 'pending_report',
    charge: 5600, paid_amount: 5600, outstanding: 0, payable_amount: 5600, payable_outstanding: 0, last_paid_at: '2026-09-08',
    remaining_sessions: 4, sessions_purchased: 16, latest_payment_report_id: 601, invoice_id: 502, payable_status: 'invoiced',
  },
  {
    id: 103, student_id: 9103, student_name: '王子謙', subject: '國小自然', schedule_mode: 'date', payment_status: 'renew_needed',
    charge: 3800, paid_amount: 3800, outstanding: 0, payable_amount: 3800, payable_outstanding: 0, last_paid_at: '2026-09-02', due_date: '2026-09-01',
    days_until_settlement: -9, course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 503, payable_status: 'invoiced',
  },
];

test('In-App348: settled labels explain existing meaning without adding payment mutations', async ({ page }) => {
  await installMock(page);
  await page.route('**/api/v1/accounting/settled-courses**', route => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({
      data: [
        { ...settledRows[0], legacy_paid_without_invoice: true },
        { ...settledRows[1], has_exception: true, overpaid_amount: 200 },
      ],
      summary: { course_count: 2, legacy_count: 1, exception_count: 1, paid_total: 9800, overpaid_total: 200, pending_reconciliation_count: 1 },
    }),
  }));
  const writes = [];
  page.on('request', request => { if (!['GET', 'HEAD'].includes(request.method())) writes.push(request.method() + ' ' + request.url()); });
  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
    await page.locator('[data-testid="tc-reminder-settled"]').click();
    const help = page.getByRole('note', { name: '帳務標籤說明' });
    await expect(help).toBeVisible();
    await expect(help).toContainText('舊制無帳單：課程已標記繳費，但目前沒有有效帳單');
    await expect(help).toContainText('例外待處理：至少一張有效帳單的淨收款超過帳單金額');
    await expect(help).toContainText('請從同一列的「繳費明細」查看既有紀錄，再與帳務負責人核對');
    const box = await help.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
    await expect(page.locator('.acct-table--settled:visible tbody tr')).toHaveCount(2);
    await expect(page.locator('.tc-card').filter({ has: page.getByText('舊制無帳單', { exact: true }) }).locator('.tc-card-num')).toHaveText('1');
    await expect(page.locator('.tc-card').filter({ has: page.getByText('例外待處理', { exact: true }) }).locator('.tc-card-num')).toHaveText('1');
    await expect(page.locator('.acct-table--settled:visible tbody tr').first()).toContainText('舊制無帳單');
    await expect(page.locator('.acct-table--settled:visible tbody tr').last()).toContainText('例外待處理');
    await expect(page.locator('.acct-table--settled:visible tbody tr').first()).toContainText('4,200');
    await expect(page.locator('.acct-table--settled:visible tbody tr').last()).toContainText('5,600');
    await expect(page.locator('.acct-table--settled:visible button')).toHaveText(['account_balance繳費明細', 'account_balance繳費明細']);
    await page.screenshot({ path: path.join(outDir, `inapp348-label-help-${width}.png`), fullPage: true });
  }
  expect(writes).toEqual([]);
});

const accountingRows = [
  { report_id: 701, payment_date: '2026-09-08', receipt_no: 'AT-260908-0001', student_name: '林宥辰', subject: '國中數學', cash_amount: 0, transfer_amount: 4200, total_amount: 4200, confirmed_by_name: 'E2E 主任' },
  { report_id: 702, payment_date: '2026-09-07', receipt_no: 'AT-260907-0002', student_name: '陳品妤', subject: '高中英文', cash_amount: 5600, transfer_amount: 0, total_amount: 5600, confirmed_by_name: 'E2E 主任', is_prepaid: true },
];
const settledRows = [
  { student_class_id: 101, course_ref: '000101', student_name: '林宥辰', subject: '國中數學', schedule_mode: 'date', paid_amount: 4200, last_paid_at: '2026-09-08' },
  { student_class_id: 102, course_ref: '000102', student_name: '陳品妤', subject: '高中英文', schedule_mode: 'count', paid_amount: 5600, last_paid_at: '2026-09-07', pending_reconciliation: true },
];
function payload(mode) {
  if (mode === 'empty') return [];
  if (mode === 'long') {
    return rows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證帳務佇列在手機上仍然可讀且操作可達',
      subject: '這是一個很長的科目名稱用來驗證帳務資料在手機上不會被裁切',
    }));
  }
  return rows;
}
async function installMock(page) {
  await page.addInitScript(() => { window.__tuitionCollectionRetryAllowed = false; });
  await page.route('**/api/v1/alerts/tuition**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'tuition alerts unavailable' }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload(mode)) });
  });
  await page.route('**/api/v1/accounting/payments**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'accounting payments unavailable' }) });
    }
    const data = mode === 'empty' ? [] : mode === 'long' ? accountingRows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證收據卡片折行與操作仍然可達',
      subject: '這是一個很長的科目名稱用來驗證收據資料在手機上不會被裁切',
    })) : accountingRows;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data, summary: { total_count: data.length, unique_paid_course_count: data.length, duplicate_payment_course_count: 0, cash_total: data.length ? 5600 : 0, transfer_total: data.length ? 4200 : 0, grand_total: data.length ? 9800 : 0, prepaid_count: data.length ? 1 : 0 } }),
    });
  });
  await page.route('**/api/v1/accounting/settled-courses**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 700));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__tuitionCollectionRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'settled courses unavailable' }) });
    }
    const data = mode === 'empty' ? [] : mode === 'long' ? settledRows.map((row) => ({
      ...row,
      student_name: '這是一個很長的學生姓名用來驗證結清卡片折行與操作仍然可達',
      subject: '這是一個很長的科目名稱用來驗證結清資料在手機上不會被裁切',
    })) : settledRows;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data, summary: { course_count: data.length, legacy_count: 0, exception_count: 0, paid_total: data.length ? 9800 : 0, overpaid_total: 0, pending_reconciliation_count: data.length ? 1 : 0 } }),
    });
  });
  await page.route('**/api/v1/students**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: new URL(page.url()).searchParams.get('mode') === 'empty' ? [] : rows.map((row) => ({ id: row.student_id, name: row.student_name, enable: 1 })) }) })); // 預設「學生」清單讀本校區學生名單
  await page.route('**/api/v1/branches**', (route) => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 1, name: '內湖分校', code: 'neihu' }]),
  }));
  await page.route('**/api/v1/class-sessions**', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [
      { id: 801, session_date: '2026-09-08', start_time: '18:00', end_time: '19:30', teacher_name: '李老師', status: 'attended' },
      { id: 802, session_date: '2026-09-10', start_time: '18:00', end_time: '19:30', teacher_name: '李老師', status: 'scheduled' },
    ] }),
  }));
}
async function expectNoOverflowAndReachableControls(page) {
  // Every control is 44px, with two narrow, explicit exceptions that are asserted below instead:
  //  - a role=checkbox button is a 20px box whose real hit area (its ::after) must be at least 44px;
  //  - the name and subject links INSIDE a bill row (the whole row is the target there, so each bill row must be at least 44px tall).
  // A student without a bill (bill-student-*) is not in a bill row and is measured like any other control.
  const measured = await page.locator('button, input:not([type="checkbox"]), select, textarea').evaluateAll((nodes) => {
    const visible = (node) => { const rect = node.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && getComputedStyle(node).visibility !== 'hidden'; };
    const inBillRow = (node) => Boolean(node.closest('tr[data-testid^="bill-row-"]'));
    const shown = nodes.filter(visible);
    const checkboxes = shown.filter((n) => n.getAttribute('role') === 'checkbox').map((n) => { const after = getComputedStyle(n, '::after'); return { w: parseFloat(after.width), h: parseFloat(after.height) }; });
    const rowLinks = shown.filter((n) => inBillRow(n) && n.matches('.bl__name, .bl__open'));
    const rows = [...document.querySelectorAll('tr[data-testid^="bill-row-"]')].filter(visible).map((n) => n.getBoundingClientRect().height);
    const controls = shown.filter((n) => n.getAttribute('role') !== 'checkbox' && !rowLinks.includes(n)).map((n) => ({ height: n.getBoundingClientRect().height, label: n.getAttribute('data-testid') || n.textContent.slice(0, 12) }));
    return { controls, checkboxes, rows };
  });
  if (!process.env.TUITION_COLLECTION_BASELINE) {
    expect(measured.controls.filter((control) => control.height < 44)).toEqual([]);
    expect(measured.checkboxes.filter((box) => !(box.w >= 44 && box.h >= 44)), 'checkbox hit area').toEqual([]);
    expect(measured.rows.filter((height) => height < 44), 'bill row height').toEqual([]);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
}
test.describe('Tuition Collection clarity browser verification', () => {
  test('uses clear loading, empty, error, and retry feedback', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    // 預設「帳單」清單的載入／錯誤／重試；
    await page.goto('/tuition-collection-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('bill-loading')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=empty');
    await expect(page.getByTestId('bill-empty')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=error');
    await expect(page.getByTestId('bill-error')).toContainText('帳務資料載入失敗');
    await page.evaluate(() => { window.__tuitionCollectionRetryAllowed = true; });
    await page.getByTestId('bill-retry').click();
    await expect(page.getByText('林宥辰', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });
  test('In-App471: bill list columns line up with the header on every row', async ({ page }) => {
    await installMock(page);
    for (const width of [1280, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
      await expect(page.locator('[data-testid^="bill-row-"]').first()).toBeVisible();
      const geo = await page.evaluate(() => {
        const box = (el) => el.getBoundingClientRect();
        const rows = [...document.querySelectorAll('.bl__row')];
        return {
          head: Math.round(box(document.querySelector('.bl__cols .bl__num')).right),
          rowLefts: rows.map((r) => Math.round(box(r).left)),
          nowRights: rows.map((r) => Math.round(box(r.querySelector('.bl__num')).right)),
          lastLefts: rows.map((r) => Math.round(box(r.querySelector('.bl__due')).left)),
        };
      });
      expect(geo.rowLefts.length).toBeGreaterThan(1);
      expect(new Set(geo.rowLefts).size).toBe(1);
      expect(new Set(geo.lastLefts).size).toBe(1);
      for (const right of geo.nowRights) expect(Math.abs(right - geo.head)).toBeLessThanOrEqual(1);
      await page.screenshot({ path: path.join(outDir, `inapp471-columns-${width}.png`), fullPage: true });
    }
  });

  test('v3: 未收 and 逾期 stay visible on a phone row', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
    const row = page.locator('[data-testid^="bill-row-"]').first();
    await expect(row).toBeVisible();
    await expect(row.locator('.bl__num')).toBeVisible();
    await expect(row.locator('.bl__due:visible')).toBeVisible(); // the due column hides on a phone, the same words sit under 未收
  });

  test('In-App480: 已結清課程 sits only on the bill list, never above 收款紀錄 / 代收代付', async ({ page }) => {
    await installMock(page);
    await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
    await expect(page.locator('[data-testid="tc-reminder-settled"]')).toHaveText('已結清課程');
    await page.getByRole('tab', { name: '收款紀錄' }).click();
    await expect(page.locator('[data-testid="tc-reminders"]')).toHaveCount(0);
    await page.getByRole('tab', { name: '帳單' }).click();
    await expect(page.locator('[data-testid="tc-reminder-settled"]')).toBeVisible();
  });

  test('captures the accounting tabs without page overflow', async ({ page }) => {
    await installMock(page);
    for (const tab of ['payments', 'settled']) {
      for (const viewport of viewports) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.goto('/tuition-collection-pilot-mount.html?mode=normal');
        await expect(page.getByText('帳務中心', { exact: true })).toBeVisible();
        if (tab === 'payments') await page.getByRole('tab', { name: '收款紀錄' }).click();
        else await page.locator('[data-testid="tc-reminder-settled"]').click();
        await expect(page.locator('.acct-table:visible')).toBeVisible();
        await expectNoOverflowAndReachableControls(page);
        await page.screenshot({ path: path.join(outDir, `${tab}-${viewport.name}.png`), fullPage: true });
      }
    }
  });

  test('uses shared accounting error, retry, and empty feedback', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=error');
    await page.getByRole('tab', { name: '收款紀錄' }).click();
    await expect(page.getByText('收據紀錄載入失敗', { exact: true })).toBeVisible();
    await page.evaluate(() => { window.__tuitionCollectionRetryAllowed = true; });
    await page.getByRole('button', { name: '重新載入' }).click();
    await expect(page.locator('.acct-table:visible')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=empty');
    await page.locator('[data-testid="tc-reminder-settled"]').click();
    await expect(page.getByText('目前查無已結清課程', { exact: true })).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });

  test('keeps accounting loading and long Chinese content usable', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/tuition-collection-pilot-mount.html?mode=loading');
    await page.getByRole('tab', { name: '收款紀錄' }).click();
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await page.goto('/tuition-collection-pilot-mount.html?mode=long');
    await page.getByRole('tab', { name: '收款紀錄' }).click();
    await expect(page.getByText(/很長的學生姓名/).first()).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
    await page.goto('/tuition-collection-pilot-mount.html?mode=long');
    await page.locator('[data-testid="tc-reminder-settled"]').click();
    await expect(page.getByText(/很長的學生姓名/).first()).toBeVisible();
    await expectNoOverflowAndReachableControls(page);
  });
});
