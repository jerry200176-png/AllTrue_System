import { test, expect } from '@playwright/test';

const widths = [390, 412, 768, 1280, 1440];

const threads = [{
  id: 101,
  type: 'dm',
  name: '教務協作',
  unread_count: 0,
  last_message: { sender_name: '林主任', body: '請確認明日課表', created_at: '2026-09-09T09:00:00Z' },
}];

const messages = [
  { id: 1, sender_user_id: 9002, sender_name: '林主任', body: '請確認明日課表', message_type: 'text', created_at: '2026-09-09T09:00:00Z' },
  { id: 2, sender_user_id: 9001, sender_name: 'E2E Director', body: '已確認，謝謝。', message_type: 'text', created_at: '2026-09-09T09:01:00Z' },
];

async function openChat(page, mode = 'normal') {
  const consoleErrors = [];
  const failedRequests = [];
  let threadAttempts = 0;
  page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
  await page.route('**/api/v1/profiles*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: [{ id: 9002, name: '林主任', role: 'director' }, { id: 9003, name: '王老師', role: 'teacher' }] }),
  }));
  await page.route('**/api/v1/chat/**', async (route) => {
    const request = route.request();
    const url = request.url();
    if (mode === 'loading' && request.method() === 'GET') await new Promise((resolve) => setTimeout(resolve, 700));
    if (url.includes('/messages')) {
      if (request.method() === 'POST') {
        const body = JSON.parse(request.postData() || '{}');
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
          id: 3, sender_user_id: 9001, sender_name: 'E2E Director', body: body.body, message_type: 'text', created_at: '2026-09-09T09:02:00Z',
        }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: messages }) });
    }
    if (url.includes('/read')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) });
    if (request.method() === 'GET') {
      threadAttempts += 1;
      if (mode === 'error' && threadAttempts === 1) {
        return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '聊天列表暫時無法載入' }) });
      }
      const data = mode === 'empty' ? [] : threads;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: threads[0] }) });
  });
  await page.goto('/pilot-mount.html?page=chat&mode=' + mode);
  await expect(page.locator('[data-pilot-ready="1"]')).toHaveCount(1);
  return { consoleErrors, failedRequests };
}

for (const width of widths) {
  test(`conversation shell is usable at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const diagnostics = await openChat(page);
    await expect(page.getByRole('heading', { name: '內部聊天' })).toBeVisible();
    await expect(page.getByRole('button', { name: '新對話' })).toBeVisible();
    await expect(page.getByText('教務協作')).toBeVisible();
    await page.getByText('教務協作', { exact: true }).click();
    await expect(page.getByRole('textbox', { name: '訊息內容' })).toBeVisible();
    await expect(page.getByRole('button', { name: '傳送附件' })).toBeVisible();
    await expect(page.getByRole('button', { name: '傳送訊息' })).toBeVisible();
    if (process.env.CHAT_EVIDENCE_DIR && (width === 390 || width === 1440)) {
      await page.screenshot({ path: `${process.env.CHAT_EVIDENCE_DIR}/vue-chat-shell-normal-${width}.png`, fullPage: true });
    }
    if (width <= 768) {
      await expect(page.getByRole('button', { name: '返回對話列表' })).toBeVisible();
      await page.getByRole('button', { name: '返回對話列表' }).click();
      await expect(page.getByText('教務協作', { exact: true })).toBeVisible();
    }
    const controls = page.locator('.chat-page button, .chat-page input');
    const heights = await controls.evaluateAll((elements) => elements
      .filter((element) => getComputedStyle(element).display !== 'none' && getComputedStyle(element).visibility !== 'hidden')
      .map((element) => element.getBoundingClientRect().height));
    expect(heights.every((height) => height >= 44)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    expect(diagnostics.failedRequests).toEqual([]);
    expect(diagnostics.consoleErrors).toEqual([]);
  });
}

test('new-chat dialog and composer keep the primary path keyboard usable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await openChat(page);
  await page.getByRole('button', { name: '新對話' }).click();
  const dialog = page.getByRole('dialog', { name: '新對話' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('tab', { name: '私訊' })).toHaveAttribute('aria-selected', 'true');
  await expect(dialog.getByRole('button', { name: '開始聊天' })).toBeDisabled();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();

  await page.getByText('教務協作', { exact: true }).click();
  const input = page.getByRole('textbox', { name: '訊息內容' });
  await input.fill('明天請再次確認跨校課程的長訊息內容，避免老師漏看。');
  await page.getByRole('button', { name: '傳送訊息' }).click();
  await expect(page.getByText('明天請再次確認跨校課程的長訊息內容，避免老師漏看。')).toBeVisible();
});

test('empty, loading, error, and long-content states remain clear and recoverable', async ({ page, context }) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await openChat(page, 'empty');
  await expect(page.getByRole('status')).toContainText('尚無聊天記錄');

  const loadingPage = await context.newPage();
  await loadingPage.setViewportSize({ width: 390, height: 900 });
  const loading = openChat(loadingPage, 'loading');
  await expect(loadingPage.getByRole('status')).toContainText('載入聊天列表中');
  await loading;
  await expect(loadingPage.locator('.loading-box')).toHaveCount(0);
  await expect(loadingPage.getByText('教務協作')).toBeVisible();

  const errorPage = await context.newPage();
  await errorPage.setViewportSize({ width: 390, height: 900 });
  await openChat(errorPage, 'error');
  await expect(errorPage.getByRole('alert')).toContainText('聊天列表暫時無法載入');
  await errorPage.getByRole('button', { name: '重試' }).click();
  await expect(errorPage.getByText('教務協作')).toBeVisible();

  const longPage = await context.newPage();
  await longPage.setViewportSize({ width: 412, height: 900 });
  await openChat(longPage);
  await longPage.getByText('教務協作', { exact: true }).click();
  await longPage.getByRole('textbox', { name: '訊息內容' }).fill('這是一段很長的中文訊息，用來確認對話泡泡會換行且不會把頁面撐出水平捲軸。');
  await expect(longPage.getByRole('textbox', { name: '訊息內容' })).toHaveValue(/水平捲軸/);
  expect(await longPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(412);
  await loadingPage.close();
  await errorPage.close();
  await longPage.close();
});
