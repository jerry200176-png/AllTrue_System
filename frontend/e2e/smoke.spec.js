// @ts-check
import { test, expect } from '@playwright/test';

/**
 * 前端 UI smoke / 關鍵業務路徑（#547 起，#730 擴充）。
 *
 * 覆蓋 API smoke 沒涵蓋的關鍵前端路徑（純讀取導航，不寫入 production）：
 *  - director：課程管理頁載入（含待補課面板區塊渲染，issue #527）。
 *  - teacher：教學工作台 + 出缺勤 / 課表與評量 / 班級行事曆 / 科目數統計逐頁載入，
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

/** 以登入頁完成登入；role: 'teacher' | 'director'。 */
async function login(page, role, creds) {
  await page.goto('/');
  const roleTitle = role === 'teacher' ? '老師' : '主任/櫃台';
  // 角色切換（radiogroup 內的 .role-btn）
  await page.locator('.role-btn', { hasText: roleTitle }).first().click({ trial: false }).catch(() => {});
  await page.locator('#login-account').fill(creds.account);
  await page.locator('#login-password').fill(creds.password);
  await page.locator('button.login-btn').click();
  // 登入後離開登入頁（登入卡消失）
  await expect(page.locator('#login-account')).toHaveCount(0, { timeout: 15_000 });
}

/**
 * 關閉登入後可能自動彈出的覆蓋層（onboarding 導覽 popover、版本公告 nudge）。
 * 這些覆蓋層有 backdrop（pointer-events:auto），會攔截側欄點擊導致 click 逾時。
 * best-effort：不存在就略過。
 */
async function dismissOverlays(page) {
  for (const sel of ['.guide-tour-close', '.release-nudge-btn:has-text("稍後再看")']) {
    const el = page.locator(sel).first();
    if (await el.isVisible().catch(() => false)) {
      await el.click().catch(() => {});
      await page.waitForTimeout(200);
    }
  }
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

  test('director: 課程管理頁與待補課面板載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'director', DIRECTOR);
    await navTo(page, '課程管理');

    // TODO: 可於 CourseManagement 根容器補 data-testid="course-mgmt-page" 讓斷言更穩。
    await expect(page.getByText('課程管理', { exact: false }).first()).toBeVisible();
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

  test('teacher: 出缺勤管理頁載入無 JS 例外', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '出缺勤管理');

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

  test('teacher: 班級行事曆載入無 JS 例外（G-007 守護的合併路徑）', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);
    await navTo(page, '班級行事曆');

    // 行事曆週/日檢視為 G-007 高風險區，至少確保開頁不 crash。
    await expect(page.getByText('行事曆', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
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
