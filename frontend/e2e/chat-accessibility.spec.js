import { expect, test } from '@playwright/test';

const threads = [{
  id: 101,
  type: 'dm',
  name: '教務協作',
  unread_count: 0,
  last_message: { sender_name: '林主任', body: '請確認明日課表', created_at: '2026-09-09T09:00:00Z' },
}];

async function openChat(page, { delayed = false } = {}) {
  await page.route('**/api/v1/profiles*', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: [{ id: 9002, name: '林主任', role: 'director' }] }),
  }));
  await page.route('**/api/v1/chat/**', async (route) => {
    if (delayed && route.request().method() === 'GET') await new Promise((resolve) => setTimeout(resolve, 300));
    const url = route.request().url();
    if (url.includes('/messages')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    if (url.includes('/read')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: {} }) });
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: threads }) });
  });
  await page.goto('/pilot-mount.html?page=chat');
  await expect(page.locator('[data-pilot-ready="1"]')).toHaveCount(1);
}

test('chat loading and composer controls announce purpose without changing requests', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests = [];
  page.on('request', (request) => {
    if (request.url().includes('/api/v1/chat/')) requests.push(`${request.method()} ${new URL(request.url()).pathname}`);
  });
  const opening = openChat(page, { delayed: true });
  await expect(page.getByRole('status')).toContainText('載入聊天列表中');
  await opening;
  await page.getByText('教務協作', { exact: true }).click();
  await expect(page.getByRole('button', { name: '返回對話列表' })).toBeVisible();
  await expect(page.getByRole('button', { name: '傳送附件' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: '訊息內容' })).toBeVisible();
  await expect(page.getByRole('button', { name: '傳送訊息' })).toBeVisible();
  expect(requests).toContain('GET /api/v1/chat/threads');
});

test('visible chat input and action controls meet the mobile touch target', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openChat(page);
  await page.getByText('教務協作', { exact: true }).click();
  const tooSmall = await page.locator('.chat-page button, .chat-page input').evaluateAll((elements) => elements
    .filter((element) => element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden')
    .map((element) => ({ label: element.getAttribute('aria-label') || element.textContent?.trim(), height: element.getBoundingClientRect().height }))
    .filter(({ height }) => height < 44));
  expect(tooSmall).toEqual([]);
  const uncenteredIcons = await page.locator('.btn-back-mobile, .btn-header-action, .reply-bar-close, .btn-attach').evaluateAll((elements) => elements
    .filter((element) => element.getClientRects().length > 0)
    .map((element) => getComputedStyle(element).justifyContent)
    .filter((justifyContent) => justifyContent !== 'center'));
  expect(uncenteredIcons).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});
