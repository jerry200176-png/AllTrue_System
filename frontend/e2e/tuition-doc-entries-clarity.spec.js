import { test, expect } from '@playwright/test';
import path from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// #3894 — 真 Vue 頁 + mock API（synthetic）；列與預期結果取自 PHPUnit 契約測試比對真 API 的同一份 fixture。
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.UI_FOUNDATION_SHOT_DIR || process.env.TUITION_COLLECTION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/tuition-doc-entries');
const fixture = JSON.parse(readFileSync(path.resolve(__dirname, '../../backend/tests/Fixtures/slip_receipt_states_3894.json'), 'utf8'));
const states = Object.entries(fixture.states).filter(([, s]) => s.role === 'director').map(([name, s], i) => ({ name, id: 101 + i, ...s }));
const LEGACY = states.find((s) => s.name === 'legacy_paid_no_invoice');
const rows = states.map((s) => ({
  id: s.id, student_id: 9000 + s.id, student_name: `測試${s.name}`, subject: '數學', schedule_mode: 'date', charge: 4000, paid_amount: 0,
  outstanding: 4000, payable_amount: 4000, payable_outstanding: 4000, ...s.row,
}));
const SIZES = [['1280', 1280, 800], ['390', 390, 844]];
const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
const rowLoc = (page, id) => page.locator(`#tuition-row-${id}:visible`).first();
async function setup(page) {
  const requests = [];
  page.on('request', (r) => { if (r.url().includes('/api/')) requests.push(`${r.method()} ${new URL(r.url()).pathname}`); });
  await page.route('**/api/v1/alerts/tuition-slip/**', (r) => json(r, { student_name: '測試', student_class_id: 101, subject: '數學', amount: 4000, items: [] }));
  await page.route('**/api/v1/alerts/tuition**', (r) => (r.request().url().includes('tuition-slip') ? r.fallback() : json(r, rows)));
  await page.route('**/api/v1/payment-reports**', (r) => json(r, { data: [] }));
  await page.route('**/api/v1/accounting/**', (r) => json(r, { data: [], summary: {} }));
  await page.route('**/api/v1/branches**', (r) => json(r, [{ id: 1, name: '內湖分校', code: 'neihu' }]));
  await page.route('**/api/v1/class-sessions**', (r) => json(r, { data: [] }));
  return requests;
}
for (const [name, width, height] of SIZES) {
  test(`繳費單／收據入口狀態矩陣 @${name}`, async ({ page }) => {
    const requests = await setup(page);
    await page.setViewportSize({ width, height });
    await page.goto('/tuition-collection-pilot-mount.html?tab=all');
    await expect(page.getByText(`測試${states[0].name}`).first()).toBeVisible();
    for (const s of states) {
      const row = rowLoc(page, s.id);
      for (const k of ['slip', 'receipt']) {
        const btn = row.getByTestId(`tc-doc-${k}`);
        if (s.expect[k].enabled) await expect(btn).toBeEnabled(); else await expect(btn).toBeDisabled();
        if (s.expect[k].reason) await expect(row.getByTestId(`tc-doc-${k}-reason`)).toHaveText(s.expect[k].reason);
      }
      await expect(row.getByTestId('tc-doc-ledger')).toHaveCount(s.expect.ledger_link ? 1 : 0);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
    await page.screenshot({ path: path.join(outDir, `slip-receipt-states-${name}.png`), fullPage: true });
    // 開啟既有繳費單預覽（雙擊只開一個；取消／關閉與 GET-only 由 vitest 頁面測試鎖定）
    await rowLoc(page, states[0].id).getByTestId('tc-doc-slip').dblclick();
    await expect(page.locator('.slip-modal')).toHaveCount(1);
    await page.screenshot({ path: path.join(outDir, `slip-preview-${name}.png`) });
    expect(requests.filter((r) => !r.startsWith('GET '))).toEqual([]);
  });
  test(`舊制已繳：繳費明細不誤導、其他課程帳單有標示 @${name}`, async ({ page }) => {
    const requests = await setup(page);
    const cid = LEGACY.id;
    await page.route(`**/api/v1/accounting/contracts/${cid}/sessions`, (r) => json(r, {
      student_class_id: cid, subject: '數學', schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31', memo: '', unscheduled_count: 0,
      sessions: [{ class_session_id: 1, date: '2026-09-01', status: 'attended', payment: 'no_invoice' }, { class_session_id: 2, date: '2026-09-08', status: 'scheduled', payment: 'no_invoice' }],
    }));
    await page.route('**/api/v1/accounting/ledger**', (r) => json(r, {
      summary: {}, scope: { student_class_id: cid }, anomalies: [], receipts: [],
      courses: [{ id: cid, subject: '數學', paid: true, schedule_mode: 'count', start_date: '2026-09-01', end_date: '2026-12-31' }],
      invoices: [{ id: 9, student_class_id: 777, subject: '其他科', total_amount: 100, outstanding_amount: 100, calculated_applied_amount: 0, status: 'unpaid', payments: [], period_start: '2026-01-01', period_end: '2026-01-31' }],
    }));
    await page.setViewportSize({ width, height });
    await page.goto('/tuition-collection-pilot-mount.html?tab=all');
    await rowLoc(page, cid).getByTestId('tc-doc-ledger').click();
    const dialog = page.locator('.ledger-modal');
    await expect(dialog.getByText('目前只有繳費單')).toHaveCount(0);
    await expect(dialog.locator('.ledger-owed__label')).toHaveText('此學生到今天未繳（含其他課程）');
    await expect(page.getByTestId('ledger-target-empty')).toContainText('這門課沒有帳單和收款');
    await expect(page.getByTestId('ledger-other-course')).toHaveText('其他課程');
    await page.screenshot({ path: path.join(outDir, `legacy-ledger-${name}.png`), fullPage: true });
    expect(requests.filter((r) => !r.startsWith('GET '))).toEqual([]);
  });
}