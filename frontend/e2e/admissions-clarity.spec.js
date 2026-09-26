// @ts-check
import { test, expect } from '@playwright/test';

const inquiry = {
  id: 7101,
  status: 'new',
  student_name: '林小安超長姓名用來驗證招生清單與詳情在手機仍然可讀',
  parent_name: '林媽媽',
  parent_phone: '0912-345-678',
  grade: '國中一年級',
  school_name: '台北市立示範國民中學',
  subject: '英文',
  preferred_slots: ['平日晚上', '週六下午'],
  public_notes: '希望加強長篇閱讀與段落寫作，家長希望先了解老師教學方式與可安排時段。',
  owner_id: null,
  owner_name: null,
  next_action: 'claim',
  follow_up_at: '2099-12-31',
  staff_notes: '',
  history: [{ occurred_at: '2026-09-09T08:00:00Z', reason_code: 'submit' }],
};

const detail = { ...inquiry };

async function installAdmissionMocks(page, mode = 'normal') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/api/v1/admission-inquiries' && request.method() === 'GET') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '招生詢問暫時無法載入' }) });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mode === 'empty' ? [] : [inquiry] }) });
      return;
    }
    if (url.pathname === '/api/v1/admission-inquiries/7101' && request.method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detail) });
      return;
    }
    if (url.pathname === '/api/v1/teachers' && request.method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ id: 81, name: '王老師' }]) });
      return;
    }
    if (url.pathname.startsWith('/api/v1/admission-inquiries/')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Admissions Director workflow real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps inquiry actions reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installAdmissionMocks(page);
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=admissions&branch=1');

      await expect(page.getByRole('heading', { name: '新生問班' })).toBeVisible();
      await expect(page.getByRole('heading', { name: '林小安超長姓名用來驗證招生清單與詳情在手機仍然可讀' })).toBeVisible();
      await expect(page.getByRole('button', { name: '由我負責' })).toBeVisible();
      await expect(page.getByRole('button', { name: '記錄已電訪聯絡' })).toBeVisible();

      const controls = page.locator('.admission-page-staff button:visible');
      const dimensions = await controls.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);

      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-admissions-after-20260909/vue-admissions-normal-${viewport.name}.png`, fullPage: true });
      }
    });
  }

  test('supports the one-next-action workflow and status feedback', async ({ page }) => {
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    await installAdmissionMocks(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=admissions&branch=1');

    await page.getByRole('button', { name: '由我負責' }).click();
    await expect(page.getByRole('button', { name: '由我負責' })).toBeVisible();
    await page.getByRole('button', { name: /家長已明確預約/ }).click();
    await expect(page.getByRole('button', { name: '建立試聽（帶入學生資料）' })).toBeVisible();
    await page.getByRole('button', { name: '複製公開問班連結' }).first().click();
    await expect(page.getByRole('button', { name: '已複製連結' })).toBeVisible();
  });

  test('makes empty and loading states explicit', async ({ page }) => {
    await installAdmissionMocks(page, 'empty');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=admissions&branch=1');
    await expect(page.getByRole('heading', { name: '目前沒有新詢問' })).toBeVisible();
    await expect(page.getByRole('button', { name: '複製公開問班連結' }).last()).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installAdmissionMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status', { name: '載入詢問中' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('surfaces controlled load errors without unexpected console or request failures', async ({ page }) => {
    const consoleErrors = [];
    const failedRequests = [];
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
    await installAdmissionMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=admissions&branch=1');
    await expect(page.getByRole('alert')).toContainText('招生詢問暫時無法載入');
    await expect(page.getByRole('heading', { name: '目前沒有新詢問' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
    expect(consoleErrors.filter((message) => !message.includes('status of 503 (Service Unavailable)'))).toEqual([]);
    expect(failedRequests).toEqual([]);
  });
});
