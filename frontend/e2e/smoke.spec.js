// @ts-check
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

/**
 * 前端 UI smoke / 關鍵業務路徑（#547 起，#730 擴充）。
 *
 * 覆蓋 API smoke 沒涵蓋的關鍵前端路徑（純讀取導航，不寫入 production）：
 *  - director：課程查找頁載入（含待補課面板區塊渲染，issue #527）。
 *  - teacher：教學工作台 + 出缺勤 / 課表與評量 / 我的課表 / 科目數統計逐頁載入，
 *             每頁斷言「無 JS 例外」（pageerror）——抓真正會讓老師白畫面的 crash。
 *
 * ⛔ Secrets 策略（granular skip）：
 *    - 無 SMOKE_BASE_URL → 整檔 skip。
 *    - 有 BASE 但無 director 帳密 → 只 skip director 區塊。
 *    - 有 BASE 但無 teacher 帳密 → 只 skip teacher 區塊。
 *    讓「只有 teacher secrets」也能跑 teacher 路徑（目前 repo 即此狀態）。
 *
 * 由 .github/workflows/ui-smoke.yml 手動 / 排程觸發，刻意不掛每個 PR（Actions
 * minutes 節流，見 OPERATIONS_RUNBOOK §B2）。
 *
 * 選擇器策略：優先用使用者可見文字 + 既有 class（resilient）。日後要更穩可補 data-testid。
 */

const BASE = process.env.SMOKE_BASE_URL;
const DIRECTOR = { account: process.env.SMOKE_DIRECTOR_USER, password: process.env.SMOKE_DIRECTOR_PASS };
const TEACHER = { account: process.env.SMOKE_TEACHER_USER, password: process.env.SMOKE_TEACHER_PASS };
const PARENT = { name: process.env.SMOKE_PARENT_STUDENT_NAME, phone: process.env.SMOKE_PARENT_PHONE };

/** 以登入頁完成登入；role: 'teacher' | 'director'。 */
async function login(page, role, creds) {
  await page.goto('/');
  const roleTitle = role === 'teacher' ? '老師' : '主任/櫃台';
  // 角色切換（radiogroup 內的 .role-btn）
  await page.locator('.role-btn', { hasText: roleTitle }).first().click({ trial: false }).catch(() => {});
  await page.locator('#login-account').fill(creds.account);
  await page.locator('#login-password').fill(creds.password);
  await page.locator('button.login-btn').click({ force: true });
  if (await page.locator('#login-account').count()) {
    await page.locator('button.login-btn').click({ force: true });
  }
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

/**
 * 點側欄導航切頁（SPA 用 active ref，無 Vue Router）。點 <button>（label span 為 pointer 容器），
 * 先清覆蓋層；點完斷言該 nav 按鈕進入 active，確保確實切頁而非空點。
 */
async function navTo(page, navLabel) {
  await dismissOverlays(page);
  // 側欄項目是 <button>，可及名稱含 nav-label 文字；可能尾隨 badge 數字故用 substring。
  const navBtn = page.getByRole('button', { name: navLabel, exact: false }).first();
  await navBtn.click();
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect(navBtn).toHaveClass(/active/, { timeout: 10_000 });
}

test.describe('UI smoke — director', () => {
  test.skip(!BASE || !DIRECTOR.account, '未設定 SMOKE_BASE_URL / SMOKE_DIRECTOR_*（缺 director secrets）— 略過');

  test('director: 課程查找頁與待補課面板載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'director', DIRECTOR);
    await dismissOverlays(page);
    await navTo(page, '課程查找');

    // TODO: 可於 CourseManagement 根容器補 data-testid="course-mgmt-page" 讓斷言更穩。
    await expect(page.getByText('課程管理', { exact: false }).first()).toBeVisible();
    // 「帳務資料」tab 只在有學生分組時渲染；smoke 帳號可能無學生，不能硬性要求。
    const billingTab = page.getByRole('tab', { name: '帳務資料' });
    if (await billingTab.count()) {
      await expect(billingTab.first()).toBeVisible({ timeout: 10_000 });
    }
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});

test.describe('UI smoke — parent portal（#983）', () => {
  test.skip(!BASE || !PARENT.name || !PARENT.phone, '未設定 SMOKE_BASE_URL / SMOKE_PARENT_*（缺 parent secrets）— 略過');

  test('parent: ?parent=1 standalone 入口登入後首頁載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await page.goto('/?parent=1');
    await page.locator('input[type="text"]').first().fill(PARENT.name);
    await page.locator('input[type="tel"]').first().fill(PARENT.phone);
    await page.getByRole('button', { name: /登入|login/i }).first().click();

    // 登入成功後學生 hub（進度/課表/帳務）應該渲染，登入表單消失。
    await expect(page.locator('input[type="tel"]')).toHaveCount(0, { timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});

test.describe('UI smoke — teacher 關鍵業務路徑', () => {
  test.skip(!BASE || !TEACHER.account, '未設定 SMOKE_BASE_URL / SMOKE_TEACHER_*（缺 teacher secrets）— 略過');

  test('teacher: 教學工作台（TeacherHome）載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);

    // 老師預設首頁為教學工作台；今日打卡狀態卡為穩定錨點。
    await expect(page.getByText('今日打卡狀態', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });

  test('teacher: 出缺勤頁載入無 JS 例外', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '出缺勤');

    await expect(page.getByText('出缺勤', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });

  test('teacher: 課表與評量頁載入無 JS 例外', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '課表與評量');

    // 學習評量表頁面錨點（避免硬綁特定列資料）。
    await expect(page.getByText('評量', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });

  test('teacher: 我的課表載入無 JS 例外（G-007 守護的合併路徑）', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '我的課表');

    // 老師入口顯示「我的課表」；週/日檢視仍是 G-007 高風險區。
    await expect(page.getByText('我的課表', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });

  test('teacher: 科目數統計頁載入無 JS 例外', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '科目數統計');

    await expect(page.getByText('科目數', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});
