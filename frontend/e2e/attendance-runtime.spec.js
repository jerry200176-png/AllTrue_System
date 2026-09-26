import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const baseURL = process.env.ATTENDANCE_RUNTIME_BASE_URL;
test.skip(!baseURL, 'Explicit isolated runtime target required');

for (const width of [390, 1440]) {
  test(`deployed attendance recovery and controls ${width}`, async ({ page }, testInfo) => {
    const state = { fail: true, writes: [], errors: [] };
    await page.setViewportSize({ width, height: 900 });
    await page.addInitScript(() => {
      localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'isolated-attendance-fixture', user: { id: 900, role: 'director', name: '隔離測試主任' } }));
      localStorage.setItem('app_branch', '16');
    });
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/*', route => {
      const req = route.request(), url = new URL(req.url()), path = url.pathname;
      if (url.origin !== new URL(baseURL).origin) return route.abort();
      if (!path.startsWith('/api/')) return route.continue();
      if (req.method() !== 'GET') {
        if (!/adoption|engagement|read/.test(path)) state.writes.push(path);
        return route.fulfill({ json: { ok: true } });
      }
      if (path === '/api/v1/me') return route.fulfill({ json: { id: 900, role: 'director', name: '隔離測試主任', campuses: [16] } });
      if (/\/(branches|campuses)$/.test(path)) return route.fulfill({ json: [{ id: 16, name: '隔離測試分校' }] });
      if (path === '/api/v1/class-sessions') return state.fail
        ? route.fulfill({ status: 503, json: { message: '暫時無法載入' } })
        : route.fulfill({ json: { data: [{ id: 9101, class_session_id: 9101, student_id: 901, student_class_id: 9001, teacher_id: 902, session_date: new Date().toISOString().slice(0, 10), student_name: '隔離測試學生', subject_name: '數學', teacher_name: '隔離測試老師', start_time: '10:00', end_time: '12:00', status: 'scheduled' }] } });
      return route.fulfill({ json: { data: [], total: 0 } });
    });
    await page.goto(baseURL + '/?app_page=attendance');
    await dismissOverlays(page);
    await expect(page.getByRole('alert').filter({ hasText: '載入待點名堂次失敗' })).toBeVisible();
    state.fail = false;
    await page.getByRole('button', { name: '重新整理今日堂次', exact: true }).click();
    await expect(page.locator('.att-page')).toContainText('隔離測試學生');
    await expect(page.getByRole('alert').filter({ hasText: '載入待點名堂次失敗' })).toHaveCount(0);
    const heights = await page.locator('.att-page button:visible').evaluateAll(bs => bs.map(b => b.getBoundingClientRect().height));
    expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.writes).toEqual([]);
    expect(state.errors).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('attendance-runtime.png'), fullPage: true });
  });
}
