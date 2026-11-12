// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// #4989: 衝突審查 on the shadcn-vue template. Mounts the real Vue page on the pilot fixture with a mocked API
// (fake data only); it never runs against a deployed site.
test.skip(Boolean(process.env.SMOKE_BASE_URL), 'Pilot visual matrix runs in the UI-foundation build job.');

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.BINDING_CONFLICT_SHOT_DIR
  || path.resolve(__dirname, '../test-results/binding-conflict-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const conflicts = [
  {
    id: 7, student_id: 7, student_name: '測試學生甲', campus_count: 2,
    bindings: [
      { id: 11, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: 'U1111111…aaaa', bound_at: '2026-10-01T12:00:00+08:00' },
      { id: 12, campus_id: 2, campus_name: '測試分校乙', line_user_id_masked: 'U2222222…bbbb', bound_at: '2026-10-02T12:00:00+08:00' },
    ],
  },
  {
    id: 8, student_id: 8, student_name: '測試學生乙', campus_count: 1,
    bindings: [
      { id: 21, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: 'U3333333…cccc', bound_at: '2026-10-03T12:00:00+08:00' },
      { id: 22, campus_id: 1, campus_name: '測試分校甲', line_user_id_masked: 'U4444444…dddd', bound_at: '2026-10-04T12:00:00+08:00' },
    ],
  },
];
const longConflict = {
  ...conflicts[0],
  student_name: '超長中文學生姓名驗證手機折行與操作仍可達',
  bindings: conflicts[0].bindings.map((b) => ({ ...b, campus_name: `${b.campus_name}很長的分校名稱用來確認自然折行` })),
};

/** Mocks the two endpoints of the page. `state.resolve` decides the answer of the POST. */
async function installMock(page, state = {}) {
  const calls = { reads: [], writes: [] };
  await page.addInitScript(() => { window.__bindingConflictRetryAllowed = false; });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (!url.pathname.startsWith('/api/v1/bindings/conflicts')) return route.fulfill({ json: { data: [] } });
    if (request.method() !== 'GET') {
      calls.writes.push({ method: request.method(), path: url.pathname, body: request.postDataJSON() });
      if (state.resolve === 'refuse') return route.fulfill({ status: 422, json: { message: '測試：此衝突已由他人處理' } });
      return route.fulfill({ json: { message: 'ok', result: {} } });
    }
    calls.reads.push(`${url.pathname}${url.search}`);
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 500));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__bindingConflictRetryAllowed))) {
      return route.fulfill({ status: 503, json: { message: '測試：伺服器忙碌中' } });
    }
    const data = mode === 'empty' ? [] : mode === 'long' ? [longConflict] : conflicts;
    return route.fulfill({ json: { conflicts: data } });
  });
  return calls;
}
const open = (page, mode = 'normal', role = 'super_admin') => page.goto(`/pilot-mount.html?page=binding-conflicts&role=${role}&mode=${mode}`);

test.describe('Binding conflict review clarity browser verification', () => {
  test('uses mobile cards and desktop table with reachable controls', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await open(page);
      await expect(page.getByRole('heading', { name: '衝突審查', level: 2 })).toBeVisible();
      await expect(page.getByRole('button', { name: '重新整理' })).toBeVisible();
      await expect(page.getByTestId('binding-conflicts-count')).toHaveText('待審衝突 2 筆');
      if (viewport.width <= 720) {
        await expect(page.locator('.bcrp-mobile-list')).toBeVisible();
        await expect(page.locator('.bcrp-mobile-card')).toHaveCount(2);
        await expect(page.locator('.bcrp-table-wrap')).toBeHidden();
      } else {
        await expect(page.locator('.bcrp-table')).toBeVisible();
        await expect(page.locator('.bcrp-table tbody tr')).toHaveCount(2);
        await expect(page.locator('.bcrp-mobile-list')).toBeHidden();
      }
      const controls = await page.locator('button, select').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
      }).map((node) => ({ height: node.getBoundingClientRect().height })));
      expect(controls.length).toBeGreaterThanOrEqual(8);
      expect(controls.filter((control) => control.height < 44)).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('loading, empty and error states remain recoverable', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, 'loading');
    await expect(page.locator('[data-testid="binding-conflicts-loading"][role="status"]')).toBeVisible();
    await expect(page.locator('.bcrp-mobile-card').first()).toContainText('測試學生甲');

    await open(page, 'empty');
    await expect(page.getByRole('heading', { name: '無待審衝突', level: 3 })).toBeVisible();
    await expect(page.getByText('目前沒有同一學生綁定多個 LINE 帳號的衝突案件。')).toBeVisible();
    await expect(page.getByTestId('binding-conflicts-count')).toHaveText('待審衝突 0 筆');

    await open(page, 'error');
    const error = page.getByRole('alert');
    await expect(error).toContainText('無法載入衝突清單');
    await expect(error).toContainText('測試：伺服器忙碌中');
    await page.evaluate(() => { window.__bindingConflictRetryAllowed = true; });
    await error.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.bcrp-mobile-card').first()).toContainText('測試學生甲');
  });

  test('long Chinese content remains usable at mobile width', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, 'long');
    await expect(page.locator('.bcrp-mobile-card').first()).toContainText('超長中文學生姓名');
    await expect(page.getByRole('button', { name: /選擇保留側：超長中文/ })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
  });

  test('the campus filter is locked for a director and reloads for a super admin', async ({ page }) => {
    const calls = await installMock(page);
    await page.setViewportSize({ width: 1280, height: 800 });
    await open(page, 'normal', 'director');
    const filter = page.getByRole('group', { name: '衝突篩選' }).getByLabel('分校');
    await expect(filter).toBeDisabled();
    await expect(page.locator('.bcrp-table tbody tr')).toHaveCount(2);
    expect(calls.reads).toEqual(['/api/v1/bindings/conflicts?campus_id=1']);
    await expect(page.getByRole('button', { name: '解除此綁定：測試學生甲 U1111111…aaaa' })).toBeVisible();

    calls.reads.length = 0;
    await open(page, 'normal', 'super_admin');
    await expect(page.locator('.bcrp-table tbody tr')).toHaveCount(2);
    await expect(filter).toBeEnabled();
    await filter.selectOption({ index: 1 });
    await expect.poll(() => calls.reads.length).toBe(2);
    expect(calls.reads[0]).toBe('/api/v1/bindings/conflicts');
    expect(calls.reads[1]).toMatch(/^\/api\/v1\/bindings\/conflicts\?campus_id=\d+$/);
  });

  test('the confirm starts on 取消, sends one request, and a refused request keeps the reason in the dialog', async ({ page }) => {
    const state = { resolve: 'refuse' };
    const calls = await installMock(page, state);
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page);
    await page.getByRole('button', { name: '保留此側：測試學生甲 U2222222…bbbb' }).locator('visible=true').click();
    const confirm = page.getByRole('alertdialog', { name: '保留綁定並解除另一側' });
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('學生 測試學生甲 綁定了 2 個 LINE 帳號。確認保留 U2222222…bbbb，並解除其他綁定嗎？');
    await expect(confirm.getByRole('button', { name: '取消' })).toBeFocused();
    // 取消 and Escape send nothing.
    await confirm.getByRole('button', { name: '取消' }).click();
    await expect(confirm).toBeHidden();
    expect(calls.writes).toEqual([]);

    await page.getByRole('button', { name: '保留此側：測試學生甲 U2222222…bbbb' }).locator('visible=true').click();
    await confirm.getByRole('button', { name: '確認保留' }).click();
    await expect(confirm).toContainText('測試：此衝突已由他人處理');
    await expect(confirm).toBeVisible();
    expect(calls.writes).toEqual([{ method: 'POST', path: '/api/v1/bindings/conflicts/7/resolve', body: { action: 'keep', binding_id: 12 } }]);

    // The retry succeeds: the dialog closes and the list is read again.
    state.resolve = 'ok';
    const readsBefore = calls.reads.length;
    await confirm.getByRole('button', { name: '確認保留' }).click();
    await expect(confirm).toBeHidden();
    await expect.poll(() => calls.reads.length).toBe(readsBefore + 1);
    expect(calls.writes).toHaveLength(2);
  });
});

// #4989: WCAG A/AA scan with rows shown, with the confirm open, and with the refusal reason in it
// (the shared axe spec only sees the empty state).
for (const viewport of [viewports[0], viewports[3]]) {
  test(`binding conflict review has no axe violations with rows, the confirm and its error state @${viewport.name}`, async ({ page }) => {
    await installMock(page, { resolve: 'refuse' });
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await open(page);
    await expect(page.locator(viewport.width <= 720 ? '.bcrp-mobile-card' : '.bcrp-table tbody tr')).toHaveCount(2);
    const scan = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze())
      .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`);
    expect(await scan()).toEqual([]);
    await page.getByRole('button', { name: '選擇保留側：測試學生乙' }).locator('visible=true').click();
    const confirm = page.getByRole('alertdialog', { name: '保留綁定並解除另一側' });
    await expect(confirm).toHaveCSS('opacity', '1');
    expect(await scan()).toEqual([]);
    await confirm.getByRole('button', { name: '確認保留' }).click();
    await expect(confirm).toContainText('測試：此衝突已由他人處理');
    expect(await scan()).toEqual([]);

    await open(page, 'error');
    await expect(page.getByRole('alert')).toContainText('無法載入衝突清單');
    expect(await scan()).toEqual([]);
  });
}
