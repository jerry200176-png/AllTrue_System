// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// This suite mounts a mocked Vue pilot page. Production smoke has its own
// classroom-management-production-smoke.spec.js and must not treat the pilot
// route as a deployed runtime endpoint.
test.skip(Boolean(process.env.SMOKE_BASE_URL), 'Pilot visual matrix runs in the UI-foundation build job.');

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.CLASSROOM_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/classroom-clarity');
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const rooms = [
  { id: 101, name: '201 教室', capacity: 12, memo: '靠窗，適合小組討論', is_active: true },
  { id: 102, name: '多功能教室', capacity: 24, memo: '設備收納於後方櫃體，請保持走道暢通', is_active: false },
];

async function installMock(page) {
  let calls = 0;
  await page.route('**/api/v1/rooms**', async (route) => {
    const mode = new URL(page.url()).searchParams.get('mode') || 'normal';
    if (mode === 'loading') await new Promise((resolve) => setTimeout(resolve, 500));
    if (mode === 'error' && !await page.evaluate(() => Boolean(window.__classroomRetryAllowed))) {
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'temporary failure' }) });
    }
    calls += 1;
    const data = mode === 'empty' ? [] : mode === 'long'
      ? [{ ...rooms[0], name: '超長中文教室名稱驗證手機折行與操作仍可達', memo: '這是一段很長的中文備註，用來確認資訊能自然折行且不造成頁面水平溢出。' }]
      : rooms;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
  });
  await page.addInitScript(() => { window.__classroomRetryAllowed = false; });
  void calls;
}

test.describe('Classroom clarity browser verification', () => {
  test('uses mobile cards and desktop table with reachable controls', async ({ page }) => {
    await installMock(page);
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/pilot-mount.html?page=classroom&mode=normal');
      await expect(page.getByRole('heading', { name: '教室管理' })).toBeVisible();
      await expect(page.getByRole('button', { name: '新增教室' }).first()).toBeVisible();
      if (viewport.width <= 720) {
        await expect(page.locator('.room-mobile-list')).toBeVisible();
        await expect(page.locator('.room-mobile-card')).toHaveCount(2);
        await expect(page.locator('.room-table-wrap')).toBeHidden();
      } else {
        await expect(page.locator('[data-guide="classroom-table"]')).toBeVisible();
        await expect(page.locator('.room-mobile-list')).toBeHidden();
      }
      const controls = await page.locator('button, input, textarea').evaluateAll((nodes) => nodes.filter((node) => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
      }).map((node) => ({ height: node.getBoundingClientRect().height })));
      expect(controls.filter((control) => control.height < 44)).toEqual([]);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
      await page.screenshot({ path: path.join(outDir, `populated-${viewport.name}.png`), fullPage: true });
    }
    expect(consoleErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
  });

  test('shared states and dialogs remain recoverable', async ({ page }) => {
    await installMock(page);
    await page.goto('/pilot-mount.html?page=classroom&mode=loading');
    await expect(page.locator('[data-testid="classroom-loading"][role="status"]')).toBeVisible();
    await expect(page.locator('.room-mobile-card').first()).toContainText('201 教室');

    await page.goto('/pilot-mount.html?page=classroom&mode=empty');
    await expect(page.getByText('目前此分校尚無教室', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: '新增教室' }).last().click();
    const createDialog = page.getByRole('dialog', { name: '新增教室' });
    await expect(createDialog).toBeVisible();
    // #4989 form template: focus starts on the first field.
    await expect(createDialog.locator('#classroom-name')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(createDialog).toBeHidden();

    await page.goto('/pilot-mount.html?page=classroom&mode=error');
    const error = page.getByRole('alert');
    await expect(error).toContainText('教室清單暫時無法載入');
    await page.evaluate(() => { window.__classroomRetryAllowed = true; });
    await error.getByRole('button', { name: '重試' }).click();
    await expect(page.locator('.room-mobile-card').first()).toContainText('201 教室');
  });

  test('long Chinese content remains usable at mobile width', async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=classroom&mode=long');
    await expect(page.locator('.room-mobile-card').first()).toContainText('超長中文教室名稱');
    await expect(page.getByRole('button', { name: /編輯教室：超長中文/ })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2)).toBe(true);
  });
});

// #4989: a name of spaces only passes the browser's own `required` check; the page must say why nothing was saved.
test('a spaces-only name or a capacity below 1 is refused next to the field and calls no API', async ({ page }) => {
  await installMock(page);
  const writes = [];
  page.on('request', (request) => { if (request.method() !== 'GET' && request.url().includes('/api/')) writes.push(request.url()); });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/pilot-mount.html?page=classroom&mode=normal');
  await page.getByRole('button', { name: '新增教室' }).first().click();
  const dialog = page.getByRole('dialog', { name: '新增教室' });
  const name = dialog.getByLabel('教室名稱');
  await name.fill('   ');
  await name.press('Enter');
  const nameError = dialog.locator('#classroom-name-error');
  await expect(nameError).toHaveText('請輸入教室名稱');
  await expect(nameError).toHaveAttribute('role', 'alert');
  await expect(name).toHaveAttribute('aria-describedby', 'classroom-name-error');
  await expect(name).toHaveAttribute('aria-invalid', 'true');
  await expect(name).toBeFocused();
  // Editing the field takes its error away.
  await name.fill('302 教室');
  await expect(nameError).toHaveCount(0);
  await expect(dialog).toBeVisible();
  expect(writes).toEqual([]);
});

// #4989: WCAG A/AA scan with rows shown and with each overlay open (the shared axe spec only sees the error state).
for (const viewport of [viewports[0], viewports[3]]) {
  test(`classroom has no axe violations with rows and overlays @${viewport.name}`, async ({ page }) => {
    await installMock(page);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/pilot-mount.html?page=classroom&mode=normal');
    await expect(page.locator('.room-mobile-card')).toHaveCount(2);
    const scan = async () => (await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze())
      .violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`);
    expect(await scan()).toEqual([]);
    await page.getByRole('button', { name: '新增教室' }).first().click();
    const dialog = page.getByRole('dialog', { name: '新增教室' });
    await expect(dialog).toHaveCSS('opacity', '1');
    await dialog.getByLabel('教室名稱').press('Enter');
    expect(await scan()).toEqual([]);
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: '刪除教室：多功能教室' }).locator('visible=true').click();
    await expect(page.getByRole('alertdialog', { name: '確認刪除' })).toHaveCSS('opacity', '1');
    expect(await scan()).toEqual([]);
  });
}
