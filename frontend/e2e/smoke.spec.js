// @ts-check
import { test, expect } from '@playwright/test';

/**
 * #547 / Epic #535 Phase 4.3 — 前端 UI smoke（最小集）。
 *
 * 覆蓋 API smoke 沒涵蓋的關鍵前端路徑：
 *  1) 主任 → 課程管理頁載入（含待補課面板區塊渲染，issue #527）。
 *  2) 老師 → 教學工作台（TeacherHome）載入（今日打卡 / 信任面板）。
 *
 * ⛔ 無 secrets 即整檔 skip：未設定 SMOKE_BASE_URL + 登入帳密時，
 *    test.skip() 讓本檔安全存在、不阻塞任何人。實際 CI 執行需 #537 提供
 *    SMOKE_* secrets，並由 .github/workflows/ui-smoke.yml 手動 / 排程觸發。
 *
 * 選擇器策略：優先用使用者可見文字 + 既有 class（resilient）。日後若要更穩，
 *    可在目標面板補 data-testid（見各 assertion 註解），屬 follow-up。
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

test.describe('UI smoke', () => {
  test.skip(
    !BASE || !DIRECTOR.account || !TEACHER.account,
    '未設定 SMOKE_BASE_URL / SMOKE_*_USER / SMOKE_*_PASS（需 #537 secrets）— 略過 UI smoke'
  );

  test('director: 課程管理頁與待補課面板載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'director', DIRECTOR);

    // 進入課程管理（側欄文字導航；SPA 用 active ref 切頁，無 Vue Router）
    await page.getByText('課程管理', { exact: false }).first().click();

    // 課程管理頁可見（待補課面板 class=pending-makeups-panel 視資料而定，故以頁面穩定錨點斷言）
    // TODO(#547 follow-up): 可於 CourseManagement 根容器補 data-testid="course-mgmt-page" 讓此斷言更穩。
    await expect(page.getByText('課程管理', { exact: false }).first()).toBeVisible();
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });

  test('teacher: 教學工作台（TeacherHome）載入', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    await login(page, 'teacher', TEACHER);

    // 老師預設首頁為教學工作台；今日打卡狀態卡為穩定錨點。
    // TODO(#547 follow-up): 可於 TeacherHome 信任面板補 data-testid="teacher-trust-panel"。
    await expect(page.getByText('今日打卡狀態', { exact: false }).first()).toBeVisible({ timeout: 15_000 });
    expect(errors, `頁面 JS 錯誤：\n${errors.join('\n')}`).toEqual([]);
  });
});
