// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.BINDING_MGMT_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/binding-management-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const rows = [
  { id: 101, student_id: 201, student_name: '林小明', line_user_id_masked: 'U•••1234', campus_id: 1, campus_name: '內湖分校', bound_at: '2026-09-08T09:30:00+08:00', verified_at: '2026-09-08T09:35:00+08:00', status: 'verified' },
  { id: 102, student_id: 202, student_name: '陳小華', line_user_id_masked: 'U•••5678', campus_id: 1, campus_name: '內湖分校', bound_at: '2026-09-07T14:15:00+08:00', verified_at: null, status: 'unverified' },
];

async function installMock(page, { rejectFirstUnbind = false, holdFirstUnbind = false, singleRow = false } = {}) {
  let listErrors = 0;
  const audit = { mutations: [], reads: [], release: null, removed: [] };
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (method !== 'GET') {
      audit.mutations.push({ method, path: url.pathname, body: route.request().postData() });
      if (method !== 'DELETE' || url.pathname !== '/api/v1/bindings/101') {
        return route.fulfill({ status: 400, json: { message: 'Unexpected mock mutation' } });
      }
      if (holdFirstUnbind && audit.mutations.length === 1) {
        await new Promise((resolve) => { audit.release = resolve; });
      }
      if (rejectFirstUnbind && audit.mutations.length === 1) {
        return route.fulfill({ status: 422, json: { message: '測試拒絕：綁定未解除' } });
      }
      audit.removed.push(101);
      return route.fulfill({ status: 200, json: { ok: true } });
    }
    audit.reads.push({ path: url.pathname, query: Object.fromEntries(url.searchParams) });
    if (url.pathname.endsWith('/branches')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 1, name: '內湖分校' }, { id: 2, name: '大安分校' }]) });
    }
    if (url.pathname.endsWith('/bindings/stats')) {
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '綁定資料暫時無法載入' }) });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ bound_count: (singleRow ? 1 : 2) - audit.removed.length, student_total: 8, unverified_bound_count: 1 }) });
    }
    if (url.pathname.endsWith('/bindings') && method === 'GET') {
      if (mode === 'error' && listErrors++ === 0) return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '綁定資料暫時無法載入' }) });
      if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 250));
      const sourceRows = url.searchParams.get('page') === '2'
        ? [{ ...rows[1], id: 103, student_name: '第二頁學生' }]
        : (singleRow ? rows.slice(0, 1) : rows);
      const data = mode === 'empty' ? [] : sourceRows.filter((row) => !audit.removed.includes(row.id)).map((row) => mode === 'long' && row.id === 101
        ? { ...row, student_name: '測試學生超長姓名驗證卡片折行與操作仍可達' }
        : row);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, meta: { last_page: 2, total: data.length + 20 } }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
  return audit;
}

async function assertNoConsoleOrFailedRequests(page) {
  const consoleErrors = [];
  const failedRequests = [];
  page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  return { consoleErrors, failedRequests };
}

test.describe('Binding Management clarity browser verification', () => {
  test('populated list uses cards on mobile and remains a table on desktop', async ({ page }) => {
    await installMock(page);
    const diagnostics = await assertNoConsoleOrFailedRequests(page);
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(`/binding-management-pilot-mount.html`);
      await page.waitForSelector('.bmp-page');
      await expect(page.getByRole('heading', { name: 'LINE 綁定管理' })).toBeVisible();
      await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
      await expect(page.getByLabel('搜尋學生姓名')).toBeVisible();
      await expect(page.locator('.bmp-page strong').filter({ hasText: '林小明' })).toHaveCount(2);
      await expect(page.getByText('未驗證', { exact: true })).toHaveCount(3);
      if (viewport.width <= 640) {
        await expect(page.locator('.bmp-mobile-list')).toBeVisible();
        await expect(page.locator('.bmp-mobile-card')).toHaveCount(2);
        await expect(page.locator('.bmp-desktop-table')).toBeHidden();
        await expect(page.getByRole('button', { name: '解除此筆綁定' }).first()).toBeVisible();
      } else {
        await expect(page.locator('.bmp-desktop-table')).toBeVisible();
        await expect(page.locator('.bmp-mobile-list')).toBeHidden();
        await expect(page.locator('[data-testid="at-icon-button"]').first()).toBeVisible();
      }
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
      expect(overflow).toBe(true);
      const controls = await page.locator('button, input, select').evaluateAll((nodes) => nodes.filter((node) => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      }).map((node) => ({ text: node.textContent?.trim(), width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height })));
      expect(controls.filter((control) => control.width <= 0 || control.height < 44)).toEqual([]);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('unbind confirmation is keyboard reachable and preserves the destructive action boundary', async ({ page }) => {
    const audit = await installMock(page);
    await page.goto('/binding-management-pilot-mount.html');
    await page.waitForSelector('.bmp-desktop-table');
    await page.locator('[data-testid="at-icon-button"]').first().click();
    const dialog = page.getByRole('dialog', { name: '解除 LINE 綁定' });
    await expect(dialog).toContainText('林小明');
    await expect(dialog.getByRole('button', { name: '取消' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: '確認解除' })).toBeVisible();
    await expect(dialog).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.locator('[data-testid="at-icon-button"]').first()).toBeFocused();
    expect(audit.mutations).toEqual([]);
  });

  test('loading, empty, error recovery, and long Chinese content remain usable', async ({ page }) => {
    await installMock(page);
    await page.goto('/binding-management-pilot-mount.html?mode=loading');
    await expect(page.getByTestId('at-skeleton')).toBeVisible();
    await expect(page.locator('.bmp-desktop-table')).toBeVisible();

    await page.goto('/binding-management-pilot-mount.html?mode=empty');
    await expect(page.getByText('沒有符合條件的綁定')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/binding-management-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert')).toContainText('無法載入綁定資料');
    await page.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.bmp-mobile-card')).toHaveCount(2);

    await page.goto('/binding-management-pilot-mount.html?mode=long');
    await expect(page.locator('.bmp-mobile-card').first()).toContainText('測試學生超長姓名');
    await expect(page.getByRole('button', { name: '解除此筆綁定' }).first()).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
    expect(overflow).toBe(true);
  });
});

// Preserve #2660 coverage against deployed canonical #2846; never use production bindings.
test('shared pagination remains visible and campus-scoped on mobile and desktop', async ({ page }) => {
  const audit = await installMock(page);
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/binding-management-pilot-mount.html');
    const pager = page.locator('.bmp-pagination');
    await expect(pager).toBeVisible();
    await expect(pager.getByRole('button', { name: '上一頁' })).toBeDisabled();
    await pager.getByRole('button', { name: '下一頁' }).click();
    await expect(page.locator('.bmp-page-info')).toContainText('第 2 / 2 頁');
    await expect(pager.getByRole('button', { name: '下一頁' })).toBeDisabled();
    const visibleList = page.locator(width === 390 ? '.bmp-mobile-list' : '.bmp-desktop-table');
    await expect(visibleList).toContainText('第二頁學生');
    expect(audit.reads.filter((r) => r.path === '/api/v1/bindings').at(-1).query)
      .toEqual({ page: '2', per_page: '20', campus_id: '1' });
    await pager.getByRole('button', { name: '上一頁' }).click();
    await expect(page.locator('.bmp-page-info')).toContainText('第 1 / 2 頁');
  }
  expect(audit.mutations).toEqual([]);
});

for (const width of [390, 1440]) {
  async function openFirst(page) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/binding-management-pilot-mount.html');
    const opener = width === 390
      ? page.getByRole('button', { name: '解除此筆綁定' }).first()
      : page.locator('[data-testid="at-icon-button"]').first();
    await opener.click();
    const dialog = page.getByRole('dialog', { name: '解除 LINE 綁定' });
    await expect(dialog).toBeVisible();
    return { dialog, opener };
  }

  test(`cancel never sends an unbind mutation at ${width}`, async ({ page }) => {
    const audit = await installMock(page);
    const { dialog, opener } = await openFirst(page);
    await dialog.getByRole('button', { name: '取消' }).click();
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();
    expect(audit.mutations).toEqual([]);
  });

  test(`successful unbind closes confirmation once and preserves other rows at ${width}`, async ({ page }) => {
    const audit = await installMock(page);
    const { dialog } = await openFirst(page);
    await dialog.getByRole('button', { name: '確認解除' }).click();
    await expect(dialog).toBeHidden();
    expect(audit.mutations).toEqual([{ method: 'DELETE', path: '/api/v1/bindings/101', body: null }]);
    const visibleList = page.locator(width === 390 ? '.bmp-mobile-list' : '.bmp-desktop-table');
    await expect(visibleList).not.toContainText('林小明');
    await expect(visibleList).toContainText('陳小華');
    await expect.poll(() => audit.reads.filter((r) => r.path === '/api/v1/bindings/stats').length).toBe(2);
  });

  test(`last-row success closes dialog and refreshes the empty list at ${width}`, async ({ page }) => {
    const audit = await installMock(page, { singleRow: true });
    const { dialog } = await openFirst(page);
    await dialog.getByRole('button', { name: '確認解除' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('沒有符合條件的綁定')).toBeVisible();
    expect(audit.mutations).toEqual([{ method: 'DELETE', path: '/api/v1/bindings/101', body: null }]);
    await expect.poll(() => audit.reads.filter((r) => r.path === '/api/v1/bindings').length).toBe(2);
  });

  test(`pending guard and failed-unbind retry preserve confirmation at ${width}`, async ({ page }) => {
    const audit = await installMock(page, { rejectFirstUnbind: true, holdFirstUnbind: true });
    const { dialog } = await openFirst(page);
    const confirm = dialog.getByRole('button', { name: '確認解除' });
    await confirm.click();
    await expect(confirm).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    await expect.poll(() => audit.mutations.length).toBe(1);
    audit.release();
    await expect(dialog.getByText('測試拒絕：綁定未解除', { exact: true })).toBeVisible();
    await expect(confirm).toBeEnabled();
    await expect(page.locator(width === 390 ? '.bmp-mobile-card' : '.bmp-desktop-table tbody tr')).toHaveCount(2);
    await confirm.click();
    await expect(dialog).toBeHidden();
    expect(audit.mutations).toEqual(Array(2).fill({ method: 'DELETE', path: '/api/v1/bindings/101', body: null }));
  });
}
