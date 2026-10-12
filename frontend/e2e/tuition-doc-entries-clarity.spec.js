import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// #3894 — 繳費單／收據入口狀態矩陣。真 Vue 頁 + mock API（synthetic，無 production）。
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || process.env.TUITION_COLLECTION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/tuition-doc-entries');
const base = { schedule_mode: 'date', charge: 4000, paid_amount: 0, outstanding: 4000, payable_amount: 4000, payable_outstanding: 4000 };
const rows = [
  { ...base, id: 101, student_name: '測試甲', subject: '數學', payment_status: 'unpaid', payable_status: 'invoiced', payable_invoice_id: 501 },
  { ...base, id: 102, student_name: '測試乙', subject: '英文', payment_status: 'partial', paid_amount: 1000, outstanding: 3000, payable_outstanding: 3000, payable_status: 'invoiced', payable_invoice_id: 502 },
  { ...base, id: 103, student_name: '測試丙', subject: '自然', payment_status: 'paid', paid_amount: 4000, outstanding: 0, payable_outstanding: 0, payable_status: 'invoiced', payable_invoice_id: 503 },
  { ...base, id: 104, student_name: '測試丁', subject: '國文', payment_status: 'none', payable_status: 'unbilled', charge: 0, payable_amount: 0, outstanding: 0, payable_outstanding: 0 },
  { ...base, id: 105, student_name: '測試戊', subject: '社會', payment_status: 'paid', payable_status: 'unbilled', paid_amount: 4000, outstanding: 0, payable_outstanding: 0 },
];
const expected = {
  101: { slip: true, receipt: false, receiptReason: '收款確認後才有收據' },
  102: { slip: true, receipt: false, receiptReason: '收款確認後才有收據' },
  103: { slip: false, receipt: true, slipReason: '已繳清，不用繳費單' },
  104: { slip: false, receipt: false, slipReason: '還沒開帳單', receiptReason: '收款確認後才有收據' },
  105: { slip: false, receipt: false, slipReason: '已在舊系統繳清，沒有新帳單', receiptReason: '舊系統繳費，系統內沒有電子收據；可看繳費明細' },
};
const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

async function setup(page) {
  const requests = [];
  page.on('request', (r) => { if (r.url().includes('/api/')) requests.push(`${r.method()} ${new URL(r.url()).pathname}`); });
  await page.route('**/api/v1/alerts/tuition-slip/**', (r) => json(r, { student_name: '測試甲', student_class_id: 101, subject: '數學', amount: 4000, items: [] }));
  await page.route('**/api/v1/alerts/tuition**', (r) => (r.request().url().includes('tuition-slip') ? r.fallback() : json(r, rows)));
  await page.route('**/api/v1/payment-reports**', (r) => json(r, { data: [] }));
  await page.route('**/api/v1/accounting/**', (r) => json(r, { data: [], summary: {} }));
  await page.route('**/api/v1/branches**', (r) => json(r, [{ id: 1, name: '內湖分校', code: 'neihu' }]));
  await page.route('**/api/v1/class-sessions**', (r) => json(r, { data: [] }));
  return requests;
}

for (const [name, width, height] of [['1280', 1280, 800], ['390', 390, 844]]) {
  test(`繳費單／收據入口狀態矩陣 @${name}`, async ({ page }) => {
    const requests = await setup(page);
    await page.setViewportSize({ width, height });
    await page.goto('/tuition-collection-pilot-mount.html?tab=all');
    await expect(page.getByText('測試甲').first()).toBeVisible();
    for (const [id, e] of Object.entries(expected)) {
      const row = page.locator(`#tuition-row-${id}:visible`).first();
      const slip = row.getByTestId('tc-doc-slip');
      const receipt = row.getByTestId('tc-doc-receipt');
      await expect(slip).toHaveText(/繳費單/);
      await expect(receipt).toHaveText(/收據/);
      if (e.slip) await expect(slip).toBeEnabled(); else await expect(slip).toBeDisabled();
      if (e.receipt) await expect(receipt).toBeEnabled(); else await expect(receipt).toBeDisabled();
      if (e.slipReason) await expect(row.getByTestId('tc-doc-slip-reason')).toHaveText(e.slipReason);
      if (e.receiptReason) await expect(row.getByTestId('tc-doc-receipt-reason')).toHaveText(e.receiptReason);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
    await expect(page.locator('#tuition-row-105:visible').first().getByTestId('tc-doc-ledger')).toBeVisible();
    await expect(page.locator('#tuition-row-105:visible').first().getByTestId('tc-void')).toBeDisabled();
    await expect(page.locator('#tuition-row-105:visible').first().getByTestId('tc-void-reason')).toHaveText('舊制沒有系統收款，無可撤銷');
    await page.screenshot({ path: path.join(outDir, `slip-receipt-states-${name}.png`), fullPage: true });

    // 開啟既有繳費單預覽 → 關閉；整個流程只能有 GET
    await page.locator('#tuition-row-101:visible').first().getByTestId('tc-doc-slip').dblclick();
    await expect(page.locator('.slip-modal')).toHaveCount(1);
    await page.screenshot({ path: path.join(outDir, `slip-preview-${name}.png`) });
    await page.waitForTimeout(500);
    await page.locator('.slip-modal .icon-btn').click();
    await expect(page.locator('.slip-modal')).toHaveCount(0);
    expect(requests.filter((r) => !r.startsWith('GET '))).toEqual([]);
  });
}

for (const [name, width, height] of [['1280', 1280, 800], ['390', 390, 844]]) {
  test(`舊制已繳：繳費明細不誤導、其他課程帳單有標示 @${name}`, async ({ page }) => {
    const requests = await setup(page);
    await page.route('**/api/v1/accounting/contracts/105/sessions', (r) => json(r, {
      student_class_id: 105, subject: '社會', schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31', memo: '', unscheduled_count: 0,
      sessions: [
        { class_session_id: 1, date: '2026-09-01', status: 'attended', payment: 'no_invoice' },
        { class_session_id: 2, date: '2026-09-08', status: 'scheduled', payment: 'no_invoice' },
      ],
    }));
    await page.route('**/api/v1/accounting/ledger**', (r) => json(r, {
      summary: {}, scope: { student_class_id: 105 }, anomalies: [], receipts: [],
      courses: [{ id: 105, subject: '社會', paid: true, schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31' }],
      invoices: [{ id: 9, student_class_id: 777, subject: '其他科', total_amount: 100, outstanding_amount: 100, calculated_applied_amount: 0, status: 'unpaid', payments: [], period_start: '2026-01-01', period_end: '2026-01-31' }],
    }));
    await page.setViewportSize({ width, height });
    await page.goto('/tuition-collection-pilot-mount.html?tab=all');
    await page.locator('#tuition-row-105:visible').first().getByTestId('tc-doc-ledger').click();
    const dialog = page.locator('.ledger-modal');
    await expect(page.getByTestId('contract-legacy-paid')).toHaveText('舊制已繳（無新系統帳單）');
    await expect(page.getByTestId('contract-session').first()).toContainText('舊制已繳（無新系統帳單）');
    await expect(dialog.getByText('還沒開帳單')).toHaveCount(0);
    await expect(dialog.getByText('還沒繳費')).toHaveCount(0);
    await expect(dialog.locator('.ledger-owed__label')).toHaveText('此學生到今天未繳（含其他課程）');
    await expect(page.getByTestId('contract-issue')).toHaveCount(0);
    await expect(page.getByTestId('contract-record')).toHaveCount(0);
    await expect(page.getByTestId('ledger-target-empty')).toContainText('這門課目前沒有帳單和收款');
    await expect(page.getByText('目前只有繳費單')).toHaveCount(0);
    await expect(page.getByTestId('ledger-other-course')).toHaveText('其他課程');
    await page.screenshot({ path: path.join(outDir, `legacy-ledger-${name}.png`), fullPage: true });
    expect(requests.filter((r) => !r.startsWith('GET '))).toEqual([]);
  });
}
