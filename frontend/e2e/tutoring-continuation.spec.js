import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const baseURL = process.env.TUTORING_CONTINUATION_BASE_URL;
test.skip(!baseURL, 'Explicit isolated browser target required');

async function install(page, { packageCourse = false, before = false } = {}) {
  const state = { writes: [], errors: [], dialogs: [], fail: true };
  const course = {
    id: 9001, student_id: 901, subject: 'Math', subject_name: '數學',
    class_type: 'tutoring', payment_type: 'session', payment_status: 'no_payment_required',
    remaining_sessions: 1, sessions_purchased: 4, sessions_used: 3,
    teacher_id: 902, teacher_name: '隔離測試老師', days_of_week: [1],
    start_time: '14:00', end_time: '16:00', end_date: '2026-09-30',
    branch_name: '隔離測試分校', branch_id: 16, room_name: '101', status: 'active',
    ...(packageCourse ? { PackageID: 7, package_id: 7, package_total_sessions: 8 } : {}),
  };
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'isolated-tutoring-fixture', user: { id: 900, role: 'director', name: '隔離測試主任' } }));
    localStorage.setItem('app_branch', '16');
  });
  page.on('pageerror', error => state.errors.push(error.message));
  page.on('dialog', async dialog => { state.dialogs.push(dialog.message()); await dialog.dismiss(); });
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort();
    const path = url.pathname;
    if (!path.startsWith('/api/')) return route.continue();
    if (request.method() === 'POST' && path.endsWith('/session-dates')) return route.fulfill({ json: { data: [] } });
    if (request.method() !== 'GET') {
      if (/adoption|engagement|read/.test(path)) return route.fulfill({ json: { ok: true } });
      state.writes.push({ path, body: request.postDataJSON() });
      if (path === '/api/v1/student-classes/9001/continue-tutoring' && !before) {
        await new Promise(resolve => setTimeout(resolve, 150));
        return state.fail
          ? route.fulfill({ status: 422, json: { message: '下一期時段有衝突，請先調整原課程固定時段。' } })
          : route.fulfill({ status: 201, json: {
            message: '已建立下一期輔導課並保留前後期關聯；費用 0 元，無須繳費。',
            source_course_id: 9001, continuity_group_id: 8,
            new_course: { id: 9002, charge: 0, start_date: '2026-10-01', end_date: '2026-10-26', created_sessions: 4 },
          } });
      }
      return route.abort();
    }
    if (path === '/api/v1/me') return route.fulfill({ json: { id: 900, name: '隔離測試主任', role: 'director', campuses: [16] } });
    if (/\/(branches|campuses)$/.test(path)) return route.fulfill({ json: [{ id: 16, name: '隔離測試分校' }] });
    if (path.includes('/active-courses')) return route.fulfill({ json: { courses: [course] } });
    if (path === '/api/v1/student-classes') return route.fulfill({ json: { data: [course], current_page: 1, last_page: 1, total: 1 } });
    if (path.includes('/students')) return route.fulfill({ json: { data: [{ id: 901, name: '隔離測試學生', grade: '小四', branch_id: 16, status: 'active', enable: 1 }], total: 1 } });
    return route.fulfill({ json: { data: [], total: 0 } });
  });
  return state;
}

for (const width of [390, 1440]) {
  test(`輔導課延續：失敗保留、重試、零應收 ${width}`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    const before = process.env.TUTORING_CONTINUATION_BEFORE === '1';
    const state = await install(page, { before });
    await page.goto(`${baseURL}/?app_page=students`);
    await dismissOverlays(page);
    const row = page.locator('tr.student-row').first();
    await row.focus();
    await row.press('Enter');
    const workspace = page.getByTestId('student-course-workspace');
    await workspace.locator('.student-course-card__actions > summary').click();
    if (before) {
      await workspace.getByRole('button', { name: /再次續報加購|^加購$/ }).first().click();
      await expect(page.getByRole('dialog', { name: /加購堂數/ })).toContainText('未繳課程批次');
      await page.screenshot({ path: testInfo.outputPath('before-tutoring-charge-copy.png'), fullPage: true });
      expect(state.writes).toEqual([]);
      return;
    }
    await workspace.getByRole('button', { name: '延續輔導課（不收費）', exact: true }).click();
    const modal = page.getByRole('dialog', { name: /延續輔導課/ });
    await expect(modal).toContainText('保留前後期關聯');
    await expect(modal).not.toContainText('未繳課程批次');
    await modal.locator('input[type=number]').fill('4');
    await modal.locator('input[type=date]').fill('2026-10-01');
    const submit = modal.getByRole('button', { name: '確認建立下一期輔導課', exact: true });
    await submit.dblclick();
    await expect.poll(() => state.dialogs.length).toBe(1);
    await expect(modal).toBeVisible();
    await expect(modal.locator('input[type=number]')).toHaveValue('4');
    expect(state.writes).toHaveLength(1);
    expect(state.writes[0]).toEqual({ path: '/api/v1/student-classes/9001/continue-tutoring', body: { sessions: 4, start_date: '2026-10-01' } });
    await expect(page.locator('html')).toHaveJSProperty('scrollWidth', width);
    await page.screenshot({ path: testInfo.outputPath('after-tutoring-zero-charge.png'), fullPage: true });
    state.fail = false;
    await submit.click();
    await expect(modal).toHaveCount(0);
    await expect.poll(() => state.dialogs.length).toBe(2);
    expect(state.dialogs[1]).toContain('2026-10-01 ～ 2026-10-26；已排 4 堂。');
    expect(state.dialogs[1]).not.toMatch(/#9001|#9002/);
    expect(state.writes).toHaveLength(2);
    expect(state.errors).toEqual([]);
  });
}

test('共用方案輔導課不會誤走方案加購', async ({ page }) => {
  test.skip(process.env.TUTORING_CONTINUATION_BEFORE === '1');
  const state = await install(page, { packageCourse: true });
  await page.goto(`${baseURL}/?app_page=students`);
  await dismissOverlays(page);
  await page.locator('tr.student-row').first().focus();
  await page.locator('tr.student-row').first().press('Enter');
  await page.getByTestId('student-course-workspace').locator('.student-course-card__actions > summary').click();
  await page.getByTestId('student-course-workspace').getByRole('button', { name: '延續輔導課（不收費）', exact: true }).click();
  await expect.poll(() => state.dialogs.length).toBe(1);
  expect(state.dialogs[0]).toContain('共用方案');
  expect(state.writes).toEqual([]);
});
