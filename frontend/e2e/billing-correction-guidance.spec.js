import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const baseURL = process.env.BILLING_CORRECTION_BASE_URL;
test.skip(!baseURL, 'Explicit isolated browser target required');

const course = {
  id: 9001,
  student_id: 901,
  student_name: '隔離測試學生',
  subject: 'math',
  subject_name: '數學',
  teacher_id: 902,
  teacher_name: '隔離測試老師',
  branch_id: 16,
  class_type: 'one_on_one',
  payment_type: 'session',
  payment_status: 'unpaid',
  status: 'active',
  sessions_purchased: 4,
  remaining_sessions: 4,
  rate_per_30min: 2500,
  days_of_week: [2],
  start_time: '20:00',
  end_time: '22:00',
};

async function install(page) {
  const state = { corrections: 0, unexpectedMutations: [], errors: [] };
  await page.addInitScript(() => {
    localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'isolated-billing-correction-only',
      user: { id: 900, role: 'director', name: '隔離測試主任' },
    }));
    localStorage.setItem('app_branch', '16');
  });
  page.on('pageerror', (error) => state.errors.push(error.message));
  page.on('dialog', (dialog) => dialog.dismiss());
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort();
    if (!url.pathname.startsWith('/api/')) return route.continue();

    const path = url.pathname;
    if (request.method() === 'POST' && path === '/api/v1/student-classes/9001/billing-correction') {
      state.corrections += 1;
      const payload = request.postDataJSON();
      expect(payload).toMatchObject({
        new_session_count: 3,
        new_charge: 7500,
        reason: '測試：本期改收三堂',
      });
      if (payload.preview === true) {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            requires_confirmation: true,
            confirmation_token: 'a'.repeat(64),
            old_session_count: 4,
            new_session_count: 3,
            old_charge: 10000,
            new_charge: 7500,
            affected_scheduled_sessions: [{
              session_id: 35056,
              session_date: '2026-09-29',
              start_time: '20:00',
              end_time: '22:00',
              status: 'scheduled',
            }],
          }),
        });
      }
      expect(payload.confirmation_token).toBe('a'.repeat(64));
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ new_session_count: 3, new_charge: 7500, cancelled_scheduled_sessions: [{ session_id: 35056 }] }),
      });
    }
    if (request.method() === 'POST' && path === '/api/v1/student-classes/session-dates') {
      return route.fulfill({ json: { data: [] } });
    }
    if (request.method() !== 'GET') {
      if (/adoption|engagement|read/.test(path)) return route.fulfill({ json: { ok: true } });
      state.unexpectedMutations.push(`${request.method()} ${path}`);
      return route.abort();
    }
    if (path === '/api/v1/me') {
      return route.fulfill({ json: { id: 900, name: '隔離測試主任', role: 'director', campuses: [16] } });
    }
    if (/\/(branches|campuses)$/.test(path)) {
      return route.fulfill({ json: [{ id: 16, name: '隔離測試分校' }] });
    }
    if (path === '/api/v1/student-classes') {
      return route.fulfill({ json: { data: [course], current_page: 1, last_page: 1, total: 1 } });
    }
    if (path.includes('/class-sessions')) {
      return route.fulfill({ json: { api_kind: 'projection', completeness: 'full', by_class: {}, data: [] } });
    }
    if (path.includes('/session-dates')) return route.fulfill({ json: { data: [] } });
    if (path.includes('/teachers')) return route.fulfill({ json: { data: [{ id: 902, username: '隔離測試老師', status: 'active', branch_id: 16 }] } });
    if (path.includes('/students')) return route.fulfill({ json: { data: [{ id: 901, name: '隔離測試學生' }] } });
    return route.fulfill({ json: { data: [], total: 0 } });
  });
  return state;
}

for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 720 }]) {
  test(`in-app #287 預覽確認與超額預排取消 ${viewport.width}`, async ({ page }, testInfo) => {
    await page.setViewportSize(viewport);
    const state = await install(page);
    await page.goto(`${baseURL}/?app_page=course-mgmt`);
    await expect(page.getByRole('heading', { name: '課程查找', exact: true })).toBeVisible();
    await dismissOverlays(page);

    await page.locator('.action-menu-trigger').first().click();
    await page.getByRole('menuitem', { name: /合約／堂次調整/ }).first().click();
    await page.getByRole('button', { name: /未付款，堂數改少/ }).click();
    const modal = page.locator('.billing-correction-modal');
    await expect(modal).toBeVisible();
    await expect(modal).toContainText('先檢視新舊堂數、金額及受影響未來堂次');

    await modal.getByLabel('更正後購買堂數').fill('3');
    await modal.getByLabel('更正後總費用').fill('7500');
    await modal.getByLabel('更正原因').fill('測試：本期改收三堂');
    await modal.getByRole('button', { name: '預覽更正內容', exact: true }).click();

    await expect(modal.getByText('請確認本次更正', { exact: true })).toBeVisible();
    await expect(modal.getByText('2026-09-29 20:00–22:00', { exact: true })).toBeVisible();
    await expect(modal).not.toContainText('#35056');
    await expect.poll(() => state.corrections).toBe(1);
    await expect(page.locator('html')).toHaveJSProperty('scrollWidth', viewport.width);
    await page.screenshot({ path: testInfo.outputPath('billing-correction-blocked.png'), fullPage: true });

    await modal.getByRole('button', { name: '確認並取消超額預排', exact: true }).click();
    await expect(page.locator('.billing-correction-modal')).toHaveCount(0);
    await expect.poll(() => state.corrections).toBe(2);
    expect(state.unexpectedMutations).toEqual([]);
    expect(state.errors).toEqual([]);
  });
}
