// @ts-check
import { test, expect } from '@playwright/test';
async function open(page, { role = 'director', size = 'standard' } = {}) {
  await page.clock.setFixedTime('2026-11-15T10:00:00+08:00');
  await page.addInitScript(size => {
    localStorage.setItem('alltrue_text_size', size);
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'e2e-545-only' }));
  }, size);
  const record = { id: 4, teacher_id: 701, branch_id: 1, base_salary: 0, effective_from: '2026-11-01', status: 'pending' };
  const teacher = { teacher_id: 701, teacher_name: '測試老師甲', overall_status: 'qualifies', review_required: false,
    settlement: { base_salary: 33000, total_payout: 35000, multiplier_pct: 100, multiplier_parts: [], adjustments: [] }, components: {},
    ...(role === 'super_admin' ? { pending_salary: record } : {}) };
  await page.route('**/api/**', async route => {
    const r = route.request(), u = new URL(r.url());
    if (r.method() === 'GET' && u.pathname.endsWith('/teacher-eligibility/inputs')) return route.fulfill({ json: { events: [], achievements: [], deductions: [], admin_allowances: [], cash_adjustments: [] } });
    if (r.method() === 'GET' && u.pathname.endsWith('/teacher-eligibility')) return route.fulfill({ json: { teachers: [teacher], period: { start: '2026-11-01', end: '2026-11-30' }, policy_version: '115.07', lock: { status: 'draft' } } });
    if (r.method() === 'POST' && u.pathname.endsWith('/salary-profiles')) {
      expect(r.postDataJSON()).toEqual({ teacher_id: 701, branch_id: 1, base_salary: 0, effective_from: '2026-11-01' });
      teacher.pending_salary = record; return route.fulfill({ status: 201, json: record });
    }
    if (r.method() === 'POST' && u.pathname.endsWith('/salary-profiles/4/approve')) {
      teacher.pending_salary = undefined; teacher.settlement.base_salary = 0;
      return route.fulfill({ json: { ...record, status: 'approved' } });
    }
    throw new Error(`Unexpected isolated request ${r.method()} ${u.pathname}`);
  });
  await page.goto(`/teacher-eligibility-pilot-mount.html?role=${role}`);
  await page.locator('#salary-teacher').selectOption('701');
  await expect(page.locator('.salary-profile')).toContainText('33,000 元');
}
for (const width of [375, 768, 1536]) for (const size of ['standard', 'large']) {
  test(`#545 explicit zero/profile approval at ${width} ${size}`, async ({ page }, info) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.setViewportSize({ width, height: 900 }); await open(page, { size });
    await page.locator('#salary-amount').fill('0'); await page.locator('#salary-effective').fill('2026-11-01');
    await page.getByRole('button', { name: '核對本薪更正', exact: true }).click();
    await expect(page.getByTestId('salary-confirm')).toContainText('測試老師甲 · 0 元');
    const panel = page.locator('.salary-profile');
    const bounds = await panel.evaluate(el => ({ overflow: el.scrollWidth - el.clientWidth, pageOverflow: document.documentElement.scrollWidth - innerWidth,
      controls: [...el.querySelectorAll('input, select, button')].map(n => n.getBoundingClientRect().height) }));
    expect(bounds.overflow).toBeLessThanOrEqual(1); expect(bounds.pageOverflow).toBeLessThanOrEqual(2); expect(bounds.controls.every(h => h >= 44)).toBe(true);
    const screenshot = info.outputPath('salary-zero-confirm.png'); await panel.screenshot({ path: screenshot }); await info.attach('confirmation', { path: screenshot, contentType: 'image/png' });
    await page.getByRole('button', { name: '送總部核准', exact: true }).click();
    await expect(page.getByText('本薪已送總部核准，尚未生效。', { exact: true })).toBeVisible();
    await page.locator('#salary-teacher').selectOption('701');
    await expect(panel).toContainText('33,000 元'); await expect(panel).toContainText('待總部核准：0 元');
    expect(errors).toEqual([]);
  });
}
test('#545 headquarters approves the existing zero profile with explicit teacher/scope confirmation', async ({ page }) => {
  await open(page, { role: 'super_admin' }); const panel = page.locator('.salary-profile');
  await panel.getByRole('button', { name: '核准這筆本薪', exact: true }).click();
  await expect(panel.getByTestId('salary-confirm')).toContainText('測試老師甲 · 0 元');
  await expect(panel.getByTestId('salary-confirm')).toContainText('目前校區');
  await panel.getByRole('button', { name: '確認核准本薪', exact: true }).click();
  await expect(page.getByText('本薪已核准，原設定紀錄保留。', { exact: true })).toBeVisible();
  await page.locator('#salary-teacher').selectOption('701');
  await expect(panel).toContainText('目前報表本薪：0 元');
});
