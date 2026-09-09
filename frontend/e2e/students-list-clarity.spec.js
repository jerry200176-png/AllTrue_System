// @ts-check
import { test, expect } from '@playwright/test';

const students = [
  { id: 2000, name: '測試學生長名字驗證用名稱避免真實個資', grade: 'J1', school: '測試國民中學附設高級中等學校名稱很長', parent_name: '測試家長長名稱', rfid: null, status: 'active', notes: '', line_bound: false },
  { id: 2001, name: '測試學生02', grade: 'J1', school: '測試國中', parent_name: '測試家長', rfid: 'TEST1001', status: 'active', notes: '', line_bound: false },
];

async function installStudentMocks(page) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/v1/students') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: students, total: students.length }) });
      return;
    }
    if (url.pathname === '/api/v1/student-classes') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return;
    }
    if (url.pathname === '/api/v1/teachers') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Students List width acceptance', () => {
  for (const viewport of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`keeps header, filters, and actions reachable @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installStudentMocks(page);
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=students');
      await expect(page.getByRole('heading', { name: '學生管理' })).toBeVisible();
      await expect(page.getByRole('button', { name: '匯入學生名單' })).toBeVisible();
      await expect(page.getByRole('button', { name: '新增學生' })).toBeVisible();
      await expect(page.getByLabel('搜尋姓名')).toBeVisible();
      await expect(page.getByText('測試學生長名字驗證用名稱避免真實個資')).toBeVisible();
      const controls = page.locator('.students-page button:visible');
      const heights = await controls.evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().height));
      expect(heights.every((height) => height >= 44)).toBe(true);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-students-list-after-20260909/vue-students-list-${viewport.name}.png`, fullPage: true });
      }
    });
  }
});
