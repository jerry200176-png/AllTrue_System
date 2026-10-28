// @ts-check
import { test, expect } from '@playwright/test';

// #4331: use the existing enrollment prompt once, then open the existing renewal.
test.describe('one existing entry for renewal (#429)', () => {
  for (const width of [390, 1440]) {
    for (const classType of ['one_on_one', 'tutoring']) {
      test(`opens ${classType} renewal without a second chooser @${width}`, async ({ page }, testInfo) => {
        const writes = [];
        const errors = [];
        const course = {
          id: 9101, student_id: 2000, subject: 'Math', subject_name: '數學',
          class_type: classType, payment_type: 'session', payment_status: 'paid',
          sessions_purchased: 8, sessions_used: 7, remaining_sessions: 1,
          status: 'active', stop: 0, branch_id: 1, teacher_id: 3000,
          teacher_name: '合成測試老師', days_of_week: [1], start_time: '14:00', end_time: '16:00',
        };
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
          const request = route.request();
          const url = new URL(request.url());
          if (url.origin !== new URL(testInfo.project.use.baseURL).origin) return route.abort();
          if (!url.pathname.startsWith('/api/')) return route.continue();
          if (request.method() === 'POST' && url.pathname.endsWith('/session-dates')) {
            return route.fulfill({ json: { data: [] } }); // Existing read-only batch query.
          }
          if (request.method() !== 'GET') {
            writes.push({ method: request.method(), path: url.pathname });
            return route.abort();
          }
          if (url.pathname === '/api/v1/students') {
            return route.fulfill({ json: { data: [students[0]], total: 1 } });
          }
          if (url.pathname.endsWith('/active-courses')) {
            return route.fulfill({ json: { courses: [course] } });
          }
          if (url.pathname === '/api/v1/student-classes') {
            return route.fulfill({ json: { data: [course], total: 1 } });
          }
          return route.fulfill({ json: { data: [], total: 0 } });
        });
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/pilot-mount.html?page=students');
        await page.locator('tr.student-row').first().focus();
        await page.locator('tr.student-row').first().press('Enter');
        await page.getByRole('button', { name: /新增課程/ }).click();
        const prompt = page.getByRole('dialog', { name: '此學生已有進行中的課程', exact: true });
        await expect(prompt).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(1);
        const action = classType === 'tutoring' ? '延續輔導課（不收費）' : '續課';
        await expect(prompt.getByRole('button', { name: action, exact: true })).toBeVisible();
        await prompt.getByRole('button', { name: action, exact: true }).click();
        const renewal = page.getByRole('dialog', { name: `${action} — 數學`, exact: true });
        await expect(prompt).toHaveCount(0);
        await expect(renewal).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(1);
        await expect(renewal).toContainText(classType === 'tutoring' ? '費用 0 元' : '新的未繳課程批次');
        await expect(renewal.getByRole('button', {
          name: classType === 'tutoring' ? '確認建立下一期輔導課' : '確認續課', exact: true,
        })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath(`renewal-${classType}-${width}.png`), fullPage: true, animations: 'disabled' });
      });
    }
  }
});

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
      await expect(page.getByRole('button', { name: '匯入名單（學生）', exact: true })).toBeVisible();
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
