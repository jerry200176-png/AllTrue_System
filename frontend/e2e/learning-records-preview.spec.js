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
  await expect(page.getByRole('button', { name: '隱藏內容預覽', exact: true })).toBeVisible();
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
