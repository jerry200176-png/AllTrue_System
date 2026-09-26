// @ts-check
import { test, expect } from '@playwright/test';

const students = [
  { id: 2000, name: '測試學生長名字驗證用名稱避免真實個資', grade: 'J1', school: '測試國民中學附設高級中等學校名稱很長', parent_name: '測試家長長名稱', rfid: null, status: 'active', notes: '', line_bound: false },
  { id: 2001, name: '測試學生02', grade: 'J1', school: '測試國中', parent_name: '測試家長', rfid: 'TEST1001', status: 'active', notes: '', line_bound: false },
];

async function installStudentMocks(page, apiRequests) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    apiRequests.push({ method: route.request().method(), pathname: url.pathname });
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
      const apiRequests = [];
      page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installStudentMocks(page, apiRequests);
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
      expect(apiRequests.every((request) => request.method === 'GET')).toBe(true);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-students-list-after-20260909/vue-students-list-${viewport.name}.png`, fullPage: true });
      }
    });
  }
});

test.describe('In-App #367 shared-pool ownership', () => {
  for (const width of [390, 1440]) {
    test(`shows a pool once and no balance on member cards @${width}`, async ({ page }) => {
      const requests = [];
      const courses = ['English', 'Science'].map((subject, index) => ({
        id: 7001 + index, student_id: 2001, subject, subject_name: index ? '自然' : '英文',
        payment_type: 'session', status: 'active', class_type: 'one_on_three',
        payment_status: 'unpaid', PackageID: 9000, package_total_sessions: 25,
        package_remaining_sessions: 25, package_used_sessions: 0,
        sessions_purchased: 99, remaining_sessions: 99,
      }));
      const sessions = courses.flatMap((course) => Array.from({ length: 25 }, (_, i) => ({
        id: course.id * 100 + i, student_class_id: course.id, student_id: 2001,
        session_date: `2026-10-${String(i + 1).padStart(2, '0')}`,
        start_time: '15:00', end_time: '17:00', status: 'scheduled',
      })));
      await page.route('**/api/v1/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        requests.push({ method: request.method(), path });
        const body = path === '/api/v1/students' ? { data: [students[1]], total: 1 }
          : path === '/api/v1/student-classes' ? { data: courses }
            : path === '/api/v1/class-sessions' ? { data: sessions }
              : { data: [] };
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
      });
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/pilot-mount.html?page=students');
      await page.locator('.student-name-cell').click();
      const cards = page.locator('.student-course-card');
      await expect(cards).toHaveCount(2);
      await expect(page.locator('.subject-pill strong')).toHaveText(['共用方案', '共用方案']);
      const pool = page.locator('.student-package-summary');
      await expect(pool).toHaveCount(1);
      const poolBounds = await pool.boundingBox();
      expect(poolBounds.x).toBeGreaterThanOrEqual(0);
      expect(poolBounds.x + poolBounds.width).toBeLessThanOrEqual(width);
      await expect(pool.getByTestId('package-remaining')).toHaveText('25');
      await expect(pool.getByTestId('package-total')).toHaveText('25');
      await expect(pool.getByTestId('package-used')).toHaveText('0');
      for (const card of await cards.all()) {
        await expect(card.locator('.student-course-card__progress')).toHaveCount(0);
        await expect(card.getByRole('progressbar')).toHaveCount(0);
        await expect(card).not.toContainText('堂剩餘');
        await expect(card).not.toContainText('99');
        await expect(card).toContainText('共用方案');
        await expect(card.locator('.student-course-card__primary')).toBeVisible();
        await card.getByRole('button', { name: '再顯示 22 堂' }).click();
        await expect(card.locator('.student-course-dates__list li')).toHaveCount(25);
        const bounds = await card.locator('.student-course-card__progress-empty').evaluate((element) => ({
          width: element.clientWidth, content: element.scrollWidth,
        }));
        expect(bounds.content).toBeLessThanOrEqual(bounds.width);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      expect(requests.every((request) => request.method === 'GET')).toBe(true);
      await page.screenshot({ path: `/tmp/inapp367-shared-pool-${width}.png`, fullPage: true });
    });
  }
});
