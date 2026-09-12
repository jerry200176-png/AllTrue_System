// @ts-check
import { test, expect } from '@playwright/test';

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

function recordsPayload() {
  return {
    data: [
      {
        id: 501,
        student_id: 1,
        student_name: '測試學生甲',
        student_class_label: 'J1',
        Subject: '數學',
        teacher_name: '測試老師',
        SessionDate: '2026-08-01',
        StartTime: '10:00',
        Status: 'approved',
        HomeworkStatus: 'partial',
        QuizScore: '92',
        Progress: '完成分數與比例的應用題',
        NextHomework: '課本第 12 頁',
        NextWeekTestScope: '第 3 單元',
        Performance: 'good',
        Comment: '今天能主動說明解題步驟。',
      },
      {
        id: 502,
        student_id: 1,
        student_name: '測試學生甲',
        student_class_label: 'J1',
        Subject: '英文',
        teacher_name: '測試老師',
        SessionDate: '2026-07-31',
        StartTime: '14:00',
        Status: 'pending',
        HomeworkStatus: 'completed',
        QuizScore: '待補考',
        Progress: '完成閱讀理解練習。',
        NextHomework: '複習單字 1–20',
        Performance: 'average',
        Comment: '需要再加強段落主旨判讀。',
      },
    ],
    total: 2,
    current_page: 1,
    last_page: 1,
  };
}

async function openLearning(page, viewport, role = 'teacher', mode = 'normal') {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    const path = new URL(request.url()).pathname;
    if (path.includes('/learning-records') && !path.includes('/feedbacks')) {
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '評量資料暫時無法載入' }) });
      }
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0, current_page: 1, last_page: 1 }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(recordsPayload()) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto(`/pilot-mount.html?page=learning&role=${role}&mode=preview`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
  if (mode === 'normal') {
    await expect(page.locator('.lr-record-card, .lr-table-row').first()).toBeVisible({ timeout: 15_000 });
  } else {
    await expect(page.locator('.lr-table-card')).toBeVisible({ timeout: 15_000 });
  }
}

for (const viewport of viewports) {
  test(`teacher can preview learning records without opening each form @${viewport.name}`, async ({ page }) => {
    await openLearning(page, viewport, 'teacher');

    await expect(page.locator('#lr-teacher-tab-pending')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByText('完成分數與比例的應用題', { exact: true })).toHaveCount(0);
    await page.locator('#lr-teacher-tab-all').click();
    await expect(page.locator('#lr-teacher-tab-all')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByText('完成分數與比例的應用題', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '顯示完整評量', exact: true })).toHaveAttribute('aria-pressed', 'false');
    await page.getByRole('button', { name: '顯示完整評量', exact: true }).click();
    await expect(page.getByText('內容預覽', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('完成分數與比例的應用題', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('第 3 單元', { exact: true }).first()).toBeVisible();

    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      tableOverflow: [...document.querySelectorAll('.lr-table-scroll')].map((node) => ({
        scrollWidth: node.scrollWidth,
        clientWidth: node.clientWidth,
      })),
    }));
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
    if (viewport.width <= 640) {
      expect(await page.locator('.lr-card-view').count()).toBe(1);
      expect(await page.locator('.lr-table-scroll').count()).toBe(0);
      await expect(page.locator('.lr-view-toggle')).toHaveCount(0);
    } else {
      await expect(page.locator('.lr-view-toggle')).toBeVisible();
    }
    if (viewport.name === '390' || viewport.name === '1440') {
      await page.locator('.lr-page').screenshot({ path: `/tmp/learning-record-preview-${viewport.name}.png` });
    }
  });
}

test('director can turn the read-only preview on and off', async ({ page }) => {
  await openLearning(page, { width: 1280, height: 900 }, 'director');

  await expect(page.getByText('內容預覽', { exact: true })).toHaveCount(0);
  const toggle = page.locator('.lr-preview-toggle');
  await toggle.click();
  await expect(page.getByText('內容預覽', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: '收合完整評量', exact: true })).toBeVisible();
  await toggle.click();
  await expect(page.getByText('內容預覽', { exact: true })).toHaveCount(0);
});

test('learning records distinguish API error from empty data', async ({ page }) => {
  await openLearning(page, { width: 390, height: 844 }, 'teacher', 'error');
  await expect(page.getByRole('alert')).toContainText('評量資料載入失敗');
  await expect(page.getByRole('button', { name: '重新載入', exact: true })).toBeVisible();

  await page.close();
  const emptyPage = await page.context().newPage();
  await openLearning(emptyPage, { width: 390, height: 844 }, 'teacher', 'empty');
  await expect(emptyPage.locator('.lr-empty-state')).toBeVisible();
  await expect(emptyPage.locator('.lr-empty-title')).toContainText(/無評量記錄|尚無評量資料/);
  await emptyPage.close();
});

test('learning records expose a loading state before the queue resolves', async ({ page }) => {
  let resolveRecords;
  const recordsPending = new Promise((resolve) => { resolveRecords = resolve; });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    if (request.method() !== 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    const path = new URL(request.url()).pathname;
    if (path.includes('/learning-records') && !path.includes('/feedbacks')) {
      await recordsPending;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(recordsPayload()) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  await page.goto('/pilot-mount.html?page=learning&role=director&mode=preview&loading=1');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
  await expect(page.locator('.lr-record-skeleton-grid')).toBeVisible();
  resolveRecords();
  await expect(page.locator('.lr-record-card, .lr-table-row').first()).toBeVisible({ timeout: 15_000 });
});

test('director note dialog keeps one clear action and usable bounds', async ({ page }) => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];
  const consoleErrors = [];
  const failedRequests = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.route('**/api/v1/**', async (route) => {
      const request = route.request();
      if (request.method() !== 'GET') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, comment: { content: '已儲存' } }) });
      }
      const path = new URL(request.url()).pathname;
      if (path.includes('/learning-records') && !path.includes('/feedbacks')) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(recordsPayload()) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });

    await page.goto(`/pilot-mount.html?page=learning&role=director&mode=preview&dialog=${viewport.name}`);
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    const noteButton = page.locator('.lr-btn-director-note').first();
    await expect(noteButton).toBeVisible({ timeout: 15_000 });
    await noteButton.click();

    const dialog = page.getByRole('dialog', { name: '主任給老師評語' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: '儲存', exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: '取消', exact: true })).toBeVisible();
    await expect(dialog).toBeFocused();
    if (viewport.name === '390') {
      await page.keyboard.press('Shift+Tab');
      await expect(dialog.getByRole('button', { name: '儲存', exact: true })).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(dialog.getByRole('button', { name: '關閉主任評語', exact: true })).toBeFocused();
    }

    await dialog.locator('textarea').fill('請確認孩子今天的閱讀理解與錯題訂正，並在下堂課延續這項練習。這段長內容用來確認繁體中文在窄螢幕不會被截斷。');
    const bounds = await dialog.boundingBox();
    const layout = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(bounds).not.toBeNull();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(viewport.height);
    expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);

    if (viewport.name === '390' || viewport.name === '1440') {
      await dialog.screenshot({ path: `/tmp/learning-record-dialog-after-${viewport.name}.png` });
    }

    if (viewport.name === '390') {
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(noteButton).toBeFocused();
      await noteButton.click();
      await expect(dialog).toBeVisible();
      await dialog.locator('textarea').fill('請補充本堂課的錯題訂正與下次練習重點。');
    }
    await dialog.getByRole('button', { name: '儲存', exact: true }).click();
    await expect(dialog).toBeHidden();
  }

  expect(consoleErrors).toEqual([]);
  expect(failedRequests).toEqual([]);
});
