// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';

const outDir = process.env.UI_FOUNDATION_SHOT_DIR || '/tmp/alltrue-attendance-after-20260909';
const beforeOnly = process.env.UI_CAPTURE_BEFORE === '1';
const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

const longSession = {
  id: 9101,
  class_session_id: 9101,
  student_id: 3101,
  student_class_id: 4101,
  session_date: new Date().toISOString().slice(0, 10),
  student_name: '測試學生超長姓名用於驗證出缺勤卡片折行與操作可達性',
  subject_name: '英文進階與閱讀理解課程',
  teacher_name: '測試老師超長顯示名稱',
  start_time: '10:00',
  end_time: '12:00',
  status: 'scheduled',
};

function sessionsFor(mode) {
  if (mode === 'empty' || mode === 'error' || mode === 'loading') return [];
  if (mode === 'long') return [longSession];
  if (mode === 'dense') {
    return Array.from({ length: 5 }, (_, index) => ({
      ...longSession,
      id: 9200 + index,
      class_session_id: 9200 + index,
      student_name: `測試學生${index + 1}`,
      subject_name: index % 2 ? '數學' : '自然科',
      teacher_name: `測試老師${index + 1}`,
      start_time: `${String(9 + index).padStart(2, '0')}:00`,
      end_time: `${String(10 + index).padStart(2, '0')}:00`,
    }));
  }
  return [{ ...longSession, student_name: '測試學生甲', subject_name: '數學', teacher_name: '測試老師' }];
}

async function installAttendanceMocks(page, mode) {
  const consoleErrors = [];
  const failedRequests = [];
  const mutations = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`));

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname;
    const method = route.request().method();

    if (method !== 'GET') {
      mutations.push({ path: pathname, method, body: route.request().postDataJSON() });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    if (pathname.endsWith('/class-sessions')) {
      if (mode === 'loading') await new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: '出缺勤資料暫時無法載入' }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: sessionsFor(mode) }) });
    }

    if (pathname.endsWith('/attendance')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) });
    }
    if (pathname.includes('/teacher-attendance')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }
    if (pathname.endsWith('/pending-swipes') || pathname.endsWith('/students') || pathname.includes('/student-classes') || pathname.includes('/teachers') || pathname.includes('/schedules')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  return { consoleErrors, failedRequests, mutations };
}

async function openAttendance(page, mode, viewport) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const diagnostics = await installAttendanceMocks(page, mode);
  await page.goto(`/pilot-mount.html?page=attendance&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return diagnostics;
}

async function expectNoOverflow(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function expectTouchTargets(page) {
  const targets = await page.locator('.att-page button:visible').evaluateAll((buttons) => buttons.map((button) => ({
    text: button.textContent?.trim(),
    className: button.className,
    height: Math.round(button.getBoundingClientRect().height),
  })));
  expect(targets.length).toBeGreaterThan(0);
  expect(Math.min(...targets.map((target) => target.height)), JSON.stringify(targets)).toBeGreaterThanOrEqual(44);
}

test.describe('Attendance clarity — real Director workflow', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(beforeOnly, 'Separate baseline capture below; assertions remain mandatory after the change.');

  for (const viewport of viewports) {
    test(`normal dense action queue @${viewport.name}`, async ({ page }) => {
      const diagnostics = await openAttendance(page, 'dense', viewport);
      await expect(page.getByRole('heading', { name: '出缺勤管理' })).toBeVisible();
      await expect(page.getByText('今日待點名堂次', { exact: true })).toBeVisible();
      await expect(page.getByRole('tab', { name: /學生點名/ })).toHaveAttribute('aria-selected', 'true');
      await expect(page.locator('.att-page .att-cards > .att-card:visible, .att-page .att-desktop-only tbody tr:visible')).toHaveCount(5);
      await expectTouchTargets(page);
      await expectNoOverflow(page);
      expect(diagnostics.consoleErrors).toEqual([]);
      expect(diagnostics.failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.locator('.att-page').screenshot({ path: path.join(outDir, `vue-attendance-normal-${viewport.name}.png`) });
      }
    });
  }

  test('empty state exposes the next action', async ({ page }) => {
    const diagnostics = await openAttendance(page, 'empty', viewports[0]);
    await expect(page.getByText('今日待點名堂次已全部完成', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '前往評量審核' })).toBeVisible();
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('loading state is announced', async ({ page }) => {
    const diagnostics = await openAttendance(page, 'loading', viewports[1]);
    await expect(page.getByRole('status').filter({ hasText: '載入中…' }).first()).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('error state keeps recovery visible', async ({ page }) => {
    const diagnostics = await openAttendance(page, 'error', viewports[3]);
    await expect(page.getByRole('alert')).toContainText('載入待點名堂次失敗');
    await expect(page.getByRole('button', { name: '重新整理今日堂次' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors.filter((message) => !message.includes('status of 503'))).toEqual([]);
    expect(diagnostics.consoleErrors.filter((message) => message.includes('status of 503'))).toHaveLength(1);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('long Chinese content remains readable on mobile', async ({ page }) => {
    const diagnostics = await openAttendance(page, 'long', viewports[0]);
    await expect(page.locator('.att-card-student').filter({ hasText: longSession.student_name })).toBeVisible();
    await expect(page.getByRole('button', { name: '確認' })).toBeVisible();
    await expectTouchTargets(page);
    await expectNoOverflow(page);
    expect(diagnostics.consoleErrors).toEqual([]);
    expect(diagnostics.failedRequests).toEqual([]);
  });

  test('mobile selection submits the same attendance identity and status once', async ({ page }) => {
    const diagnostics = await openAttendance(page, 'normal', viewports[0]);
    const card = page.locator('.att-mobile-only .att-card').first();
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: '遲到', exact: true }).click();
    await expect(card.getByRole('button', { name: '遲到', exact: true })).toHaveAttribute('aria-pressed', 'true');
    expect(diagnostics.mutations).toEqual([]);
    await card.getByRole('button', { name: '確認', exact: true }).click();
    await expect.poll(() => diagnostics.mutations.filter(m => m.path === '/api/v1/attendance')).toHaveLength(1);
    expect(diagnostics.mutations.find(m => m.path === '/api/v1/attendance')).toEqual({
      path: '/api/v1/attendance', method: 'POST',
      body: { StudentID: 3101, StudentClassID: 4101, TeacherID: 9001, ClassSessionID: 9101, Status: 'late', mark_mode: 'arrival' },
    });
  });
});

test.describe('Attendance baseline capture', () => {
  test.skip(!beforeOnly, 'Opt-in screenshots of unchanged main only.');
  for (const viewport of [viewports[0], viewports[4]]) {
    test(`before @${viewport.name}`, async ({ page }, testInfo) => {
      const diagnostics = await openAttendance(page, 'dense', viewport);
      await expect(page.getByRole('heading', { name: '出缺勤管理' })).toBeVisible();
      const heights = await page.locator('.att-page button:visible').evaluateAll(buttons => buttons.map(b => ({ text: b.textContent.trim(), height: b.getBoundingClientRect().height })));
      await testInfo.attach('baseline-touch-heights', { body: JSON.stringify(heights), contentType: 'application/json' });
      await page.locator('.att-page').screenshot({ path: path.join(outDir, `vue-attendance-before-${viewport.name}.png`) });
      expect(diagnostics.mutations).toEqual([]);
    });
  }
});

for (const width of [390, 1440]) {
  test(`existing tutoring form copy @${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/api/**', route => route.fulfill({ json: { data: [] } }));
    await page.goto('/pilot-mount.html?page=course-edit');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    const fees = page.locator('.form-section').filter({ hasText: '費用與繳費' });
    await expect(fees.locator('input[placeholder="1500"]')).toHaveValue('1500');
    if (!beforeOnly) {
      await expect(fees).toContainText('不是向學生收費');
      await expect(fees).toContainText('課務與核薪參考單價（元）');
      await expect(fees).toContainText('課程期間計算方式');
    }
    await fees.screenshot({ path: path.join(outDir, `course-edit-${beforeOnly ? 'before' : 'after'}-${width}.png`) });
    await page.goto('/pilot-mount.html?page=course-edit&mode=paid');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await expect(page.locator('.course-form-wrapper')).not.toContainText('不是向學生收費');
    await expect(page.locator('.course-form-wrapper')).toContainText('單堂費用（元）');
    await expect(page.locator('input[placeholder="1500"]')).toHaveValue('1500');
  });
}
