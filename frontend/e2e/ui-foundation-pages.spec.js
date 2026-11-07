// @ts-check
/**
 * Merge-acceptance visual evidence: real Vue pages (NotificationsCenter / StudentsList)
 * with deterministic API mocks. Does not use production SMOKE credentials.
 *
 * Static HTML harness under e2e/fixtures is design exploration only.
 */
import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';
import { installApiMocks } from './fixtures/ui-foundation/apiMocks.js';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = process.env.UI_FOUNDATION_SHOT_DIR
  || path.resolve(__dirname, '../../docs/design/evidence/raw');

const viewports = [
  { name: '390', width: 390, height: 844 },
  { name: '412', width: 412, height: 915 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
  { name: '1440', width: 1440, height: 900 },
];

async function openPilot(page, { pageName, mode, viewport, authProfile = null }) {
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
  const mocks = await installApiMocks(page, mode, pageName, authProfile, authProfile?.onAuthRequest);
  if (authProfile?.initialActingAs) {
    await page.addInitScript((actingAs) => {
      localStorage.setItem('alltrue_acting_as', actingAs);
    }, authProfile.initialActingAs);
  }
  await page.goto(`/pilot-mount.html?page=${pageName}&mode=${mode}`);
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  return mocks;
}

test.describe('UI foundation — real Vue page evidence', () => {
  test.describe.configure({ mode: 'serial' });

  const inboxModes = ['normal', 'loading', 'empty', 'error', 'long', 'disabled'];
  const studentModes = ['normal', 'empty', 'dense', 'long', 'focus', 'mobile-scroll'];

  for (const vp of viewports) {
    for (const mode of inboxModes) {
      test(`inbox ${mode} @${vp.name}`, async ({ page }) => {
        fs.mkdirSync(outDir, { recursive: true });
        const mocks = await openPilot(page, { pageName: 'inbox', mode: mode === 'disabled' ? 'disabled' : mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.getByTestId('at-skeleton')).toBeVisible({ timeout: 10_000 });
          await page.locator('.notifications-page').screenshot({
            path: path.join(outDir, `vue-inbox-loading-${vp.name}.png`),
          });
          mocks.releaseHang();
          return;
        }

        if (mode === 'empty') {
          await expect(page.getByText('目前沒有待辦案件')).toBeVisible({ timeout: 10_000 });
        } else if (mode === 'error') {
          await expect(page.getByText('案件狀態暫時無法更新').first()).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('主任收件匣')).toBeVisible({ timeout: 10_000 });
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible({ timeout: 10_000 });
        }

        if (mode === 'disabled') {
          const prev = page.getByRole('button', { name: '上一頁' }).first();
          if (await prev.count()) {
            await expect(prev).toBeDisabled();
          } else {
            // Single-page mock still exposes primary CTA; assert disabled sync button on ops lane is N/A.
            // Ensure case CTA remains enabled while pager prev (if present) is disabled — already checked.
            await expect(page.getByRole('button', { name: '安排補課' }).first()).toBeEnabled();
          }
        }

        await page.locator('.notifications-page').screenshot({
          path: path.join(outDir, `vue-inbox-${mode}-${vp.name}.png`),
        });
      });
    }

    for (const mode of studentModes) {
      test(`students ${mode} @${vp.name}`, async ({ page }) => {
        fs.mkdirSync(outDir, { recursive: true });
        const apiMode = mode === 'focus' || mode === 'mobile-scroll' ? (mode === 'mobile-scroll' ? 'dense' : 'normal') : mode;
        await openPilot(page, { pageName: 'students', mode: apiMode, viewport: vp });

        await expect(page.getByText('學生管理')).toBeVisible({ timeout: 10_000 });

        if (mode === 'empty') {
          await expect(page.getByText('目前沒有進行中的學生/課程').or(page.getByText('目前無學生資料'))).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible({ timeout: 10_000 });
        }

        if (mode === 'focus') {
          const addBtn = page.getByRole('button', { name: '新增學生' }).first();
          await addBtn.focus();
          await expect(addBtn).toBeFocused();
        }

        if (mode === 'mobile-scroll' && vp.name === '390') {
          const wrap = page.locator('.table-scroll-wrap');
          await expect(wrap).toBeVisible();
          const scrollWidth = await wrap.evaluate((el) => el.scrollWidth);
          const clientWidth = await wrap.evaluate((el) => el.clientWidth);
          expect(scrollWidth).toBeGreaterThanOrEqual(clientWidth);
          await wrap.evaluate((el) => { el.scrollLeft = Math.min(120, el.scrollWidth); });
        }

        await page.locator('.students-page').screenshot({
          path: path.join(outDir, `vue-students-${mode}-${vp.name}.png`),
        });
      });
    }
  }

  test('inbox tabs expose a linked keyboard workspace', async ({ page }) => {
    await openPilot(page, {
      pageName: 'inbox',
      mode: 'normal',
      viewport: { width: 390, height: 844 },
    });

    const casesTab = page.getByRole('tab', { name: '待辦案件' });
    const opsTab = page.getByRole('tab', { name: '營運通知' });
    await expect(casesTab).toHaveAttribute('id', 'notifications-tab-cases');
    await expect(casesTab).toHaveAttribute('aria-controls', 'notifications-panel-cases');
    await expect(casesTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#notifications-panel-cases')).toHaveAttribute('role', 'tabpanel');
    await expect(page.locator('#notifications-panel-cases')).toHaveAttribute('aria-labelledby', 'notifications-tab-cases');

    await opsTab.focus();
    await opsTab.press('Enter');
    await expect(opsTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#notifications-panel-ops')).toHaveAttribute('role', 'tabpanel');
    await expect(page.locator('#notifications-panel-ops')).toHaveAttribute('aria-labelledby', 'notifications-tab-ops');
  });

  for (const vp of viewports) {
    test(`page header keeps actions reachable @${vp.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => {
        failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
      });

      await openPilot(page, {
        pageName: 'inbox',
        mode: 'long',
        viewport: vp,
      });

      const header = page.getByTestId('at-page-header');
      const action = header.getByRole('button', { name: '通知設定', exact: true });
      await expect(action).toBeVisible();
      await action.focus();
      await expect(action).toBeFocused();

      const metrics = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth);

      const actionBox = await action.boundingBox();
      if (vp.width <= 768) {
        expect(actionBox?.height || 0).toBeGreaterThanOrEqual(44);
        expect(actionBox?.width || 0).toBeGreaterThan(0);
      }

      expect(consoleErrors, `瀏覽器 console errors：\n${consoleErrors.join('\n')}`).toEqual([]);
      expect(failedRequests, `失敗的網路請求：\n${failedRequests.join('\n')}`).toEqual([]);

      fs.mkdirSync(outDir, { recursive: true });
      await page.locator('.notifications-page').screenshot({
        path: path.join(outDir, `vue-inbox-page-header-long-${vp.name}.png`),
      });
    });
  }

  for (const vp of viewports) {
    test(`inbox operations controls remain reachable @${vp.name}`, async ({ page }) => {
      fs.mkdirSync(outDir, { recursive: true });
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));

      await openPilot(page, {
        pageName: 'inbox',
        mode: 'long',
        viewport: vp,
      });
      await page.getByRole('tab', { name: '營運通知' }).click();

      await expect(page.getByTestId('at-filter-bar')).toBeVisible();
      await expect(page.getByTestId('at-toolbar')).toBeVisible();
      const controls = page.locator('.at-filter-bar select, .at-filter-bar label:has(input[type="checkbox"]), .at-toolbar button, .notification-action');
      await expect(controls.first()).toBeVisible();
      await expect(page.getByRole('button', { name: '前往帳務中心' }).first()).toBeVisible();

      const dimensions = await controls.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      if (vp.width <= 640) {
        expect(dimensions.filter(({ height }) => height > 0).every(({ height }) => height >= 44)).toBe(true);
      }

      const firstAction = page.getByRole('button', { name: '前往帳務中心' }).first();
      await firstAction.focus();
      await expect(firstAction).toBeFocused();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);

      await page.locator('.notifications-page').screenshot({
        path: path.join(outDir, `vue-inbox-operations-long-${vp.name}.png`),
      });
    });
  }

  test('inbox case pager reaches page 3 and item 51 after overlay dismissal', async ({ page }) => {
    await openPilot(page, {
      pageName: 'inbox',
      mode: 'disabled',
      viewport: { width: 390, height: 844 },
    });
    await dismissOverlays(page);

    const pager = page.getByTestId('case-pager');
    await expect(pager).toContainText('第 1 / 3 頁（共 51）');
    const next = pager.getByRole('button', { name: '下一頁', exact: true });
    await next.click();
    await expect(pager).toContainText('第 2 / 3 頁（共 51）');
    await next.click();
    await expect(pager).toContainText('第 3 / 3 頁（共 51）');
    await expect(page.getByText('測試學生第 51 筆', { exact: true })).toBeVisible();
    await expect(next).toBeDisabled();
  });

  test('inbox tuition report deep-links to tuition-collect instead of local mutation modal', async ({ page }) => {
    await openPilot(page, {
      pageName: 'inbox',
      mode: 'dialog',
      viewport: { width: 390, height: 844 },
    });

    await page.getByRole('tab', { name: '營運通知' }).click();
    const billingButton = page.getByRole('button', { name: '前往帳務中心' }).first();
    await expect(billingButton).toBeVisible();
    await expect(page.getByRole('button', { name: '標記已繳費' })).toHaveCount(0);
    await expect(page.getByRole('dialog', { name: '登記收款' })).toHaveCount(0);
  });

  test('student management labels its add-student workspace as a dialog', async ({ page }) => {
    await openPilot(page, {
      pageName: 'students',
      mode: 'normal',
      viewport: { width: 390, height: 844 },
    });

    await page.getByRole('button', { name: '新增學生', exact: true }).first().click();
    const dialog = page.getByRole('dialog', { name: '新增學生', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog.getByRole('heading', { name: '新增學生', exact: true })).toBeVisible();
  });

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`students course overview selects the next action @${vp.name}`, async ({ page }) => {
      const sessionRequests = [];
      page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.pathname.endsWith('/class-sessions')) sessionRequests.push(request);
      });
      fs.mkdirSync(outDir, { recursive: true });
      await openPilot(page, { pageName: 'students', mode: 'normal', viewport: vp });

      const tableWrap = page.locator('.table-scroll-wrap');
      const initialScrollLeft = await tableWrap.evaluate((el) => el.scrollLeft);
      const studentRow = page.locator('tr.student-row').first();
      await expect(studentRow).toHaveAttribute('tabindex', '0');
      await expect(studentRow).toHaveAttribute('aria-expanded', 'false');
      await expect(studentRow).toHaveAttribute('aria-controls', /student-course-detail-/);
      await studentRow.focus();
      await studentRow.press('Enter');
      await expect(studentRow).toHaveAttribute('aria-expanded', 'true');
      const workspace = page.getByTestId('student-course-workspace');
      await expect(workspace).toBeVisible({ timeout: 10_000 });
      await expect.poll(() => tableWrap.evaluate((el) => el.scrollLeft)).toBeLessThan(initialScrollLeft + 8);
      await expect(workspace.getByText('先看需要處理的課程')).toBeVisible();
      await expect(workspace.getByRole('heading', { name: '逐筆核對合約與上課日期' })).toBeVisible();
      await expect.poll(() => sessionRequests.length).toBe(1);
      expect(new URL(sessionRequests[0].url()).searchParams.get('student_class_ids')).toBe('5001,5002,5003');
      await expect(workspace.locator('.student-course-overview__metric')).toHaveCount(3);
      await expect(workspace.locator('.student-course-overview__metric:nth-child(3) strong')).toHaveText('1');

      const english = workspace.locator('.student-course-picker__item[data-course-id="5002"]');
      const math = workspace.locator('.student-course-picker__item[data-course-id="5001"]');
      await expect(english.locator('button')).toHaveAttribute('aria-pressed', 'true');
      await expect(math.locator('button')).toHaveAttribute('aria-pressed', 'false');
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"]')).toBeVisible();
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]')).toContainText('7 堂');
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]').getByRole('button', { name: '再顯示 4 堂' })).toBeVisible();
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"]')).toContainText('4 堂');
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"]').getByRole('button', { name: '再顯示 1 堂' })).toBeVisible();
      await workspace.getByRole('button', { name: '全部展開上課日期' }).click();
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]').getByRole('button', { name: '收合日期' })).toBeVisible();
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"]').getByRole('button', { name: '收合日期' })).toBeVisible();
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"] .student-course-card__next-step')).toContainText('先處理課程續報');
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"] button.student-course-card__primary')).toHaveText(/續課/);
      await expect(workspace.locator('article.student-course-card[data-course-id="5002"] button.student-course-card__primary')).toBeVisible();

      const historyToggle = page.locator('tr.course-detail-row').first().locator('.sl-history-toggle');
      await expect(historyToggle).toHaveAttribute('aria-expanded', 'false');
      await historyToggle.click();
      await expect(historyToggle).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('tr.course-detail-row').first().locator('.sl-history-body')).toBeVisible();
      await expect(page.locator('tr.course-detail-row').first().locator('.student-course-dates--history')).toContainText('2026-08-01（週六） 15:00–17:00');

      await math.locator('button').click();
      await expect(math.locator('button')).toHaveAttribute('aria-pressed', 'true');
      await expect(english.locator('button')).toHaveAttribute('aria-pressed', 'false');
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]')).toBeVisible();
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"] .student-course-card__next-step')).toContainText('課程資料已齊全');
      await expect(workspace.locator('article.student-course-card[data-course-id="5001"]').getByRole('button', { name: '編輯課程' })).toBeVisible();
      await expect.poll(() => tableWrap.evaluate((el) => el.scrollLeft)).toBeLessThan(initialScrollLeft + 8);

      await page.locator('.students-page').screenshot({
        path: path.join(outDir, `vue-students-course-overview-${vp.name}.png`),
      });
    });
  }

  test('student active and history dates distinguish load failure from real empty data', async ({ page, context }) => {
    const openStudentWorkspace = async (targetPage, mode) => {
      await openPilot(targetPage, { pageName: 'students', mode, viewport: { width: 1440, height: 900 } });
      await targetPage.locator('tr.student-row').first().press('Enter');
      const workspace = targetPage.getByTestId('student-course-workspace');
      await expect(workspace).toBeVisible();
      await targetPage.locator('.sl-history-toggle').click();
      await expect(targetPage.locator('.sl-history-body')).toBeVisible();
      return targetPage;
    };

    const failedPage = await openStudentWorkspace(page, 'error');
    await expect(failedPage.locator('.student-course-dates__state--error')).toHaveCount(3);
    await expect(failedPage.getByText('上課日期暫時無法載入。')).toHaveCount(3);
    await expect(failedPage.getByText('目前沒有可顯示的上課日期。')).toHaveCount(0);

    const emptyPage = await context.newPage();
    const emptyResultPage = await openStudentWorkspace(emptyPage, 'sessions-empty');
    await expect(emptyResultPage.locator('.student-course-dates__state--error')).toHaveCount(0);
    await expect(emptyResultPage.getByText('目前沒有可顯示的上課日期。')).toHaveCount(3);
  });

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`director workbench @${vp.name}`, async ({ page }) => {
      const secondaryRequests = [];
      page.on('request', (request) => {
        if (/\/v1\/adoption\/(task-tracker|activity-log|weekly-metrics)/.test(request.url())) secondaryRequests.push(request.url());
      });
      await openPilot(page, { pageName: 'director', mode: 'normal', viewport: vp });

      await expect(page.getByRole('main', { name: '主任總覽' })).toBeVisible({ timeout: 10_000 });
      await expect(page.getByText('今日摘要', { exact: true })).toBeVisible();
      const layout = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        primaryCtaCount: document.querySelectorAll('.director-task__action').length,
        primaryDecisionCount: document.querySelectorAll('.director-task').length,
        hiddenLegacyWorkbench: Boolean(document.querySelector('.workbench')),
      }));
      expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
      expect(layout.hiddenLegacyWorkbench).toBe(false);
      expect(layout.primaryDecisionCount).toBeLessThanOrEqual(7);
      await expect(page.getByRole('tab', { name: '今天', exact: true })).toHaveAttribute('aria-selected', 'true');
      await expect(page.getByRole('tab', { name: '今天', exact: true })).toHaveAttribute('aria-controls', 'director-workbench-panel-focus');
      await expect(page.locator('#director-workbench-panel-focus')).toHaveAttribute('role', 'tabpanel');
      await expect(page.locator('#director-workbench-panel-focus')).toHaveAttribute('aria-labelledby', 'director-workbench-tab-focus');
      if (layout.primaryCtaCount > 0) {
        await expect(page.locator('.director-task__action').first()).toBeVisible();
        if (vp.width <= 768) {
          const ctaBox = await page.locator('.director-task__action').first().boundingBox();
          expect(ctaBox?.height || 0, '手機主任待辦主要操作必須有至少 44px 觸控高度').toBeGreaterThanOrEqual(44);
        }
      }
      expect(secondaryRequests).toEqual([]);
      fs.mkdirSync(outDir, { recursive: true });
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-focus-${vp.name}.png`) });

      const fullView = page.getByRole('tab', { name: '完整營運', exact: true });
      await fullView.click();
      await expect(page.locator('.director-workbench-v2__full')).toBeVisible();
      await expect(fullView).toHaveAttribute('aria-controls', 'director-workbench-panel-full');
      await expect(page.locator('#director-workbench-panel-full')).toHaveAttribute('role', 'tabpanel');
      await expect(page.locator('#director-workbench-panel-full')).toHaveAttribute('aria-labelledby', 'director-workbench-tab-full');
      await expect.poll(() => secondaryRequests.length).toBeGreaterThan(0);
      await expect(page.getByText('近期紀錄與分析', { exact: true })).toBeVisible();
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-full-${vp.name}.png`) });
    });
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`course leave workflow ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'course', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.getByRole('status', { name: '課程資料載入中' })).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
          return;
        }

        if (mode === 'error') {
          await expect(page.getByRole('alert')).toContainText('家長請假待辦載入失敗');
        } else if (mode === 'empty') {
          await expect(page.getByText('目前沒有待處理的家長請假')).toBeVisible({ timeout: 10_000 });
        } else {
          await expect(page.getByText('家長請假待處理', { exact: true })).toBeVisible({ timeout: 10_000 });
          await expect(page.getByText('測試學生', { exact: false }).first()).toBeVisible();
          const action = page.getByRole('button', { name: '處理這筆請假' }).first();
          await expect(action).toBeVisible();
          await action.click();
          await expect.poll(() => page.evaluate(() => window.__pilotLastNavigation)).toEqual({
            target: 'director',
            section: 'exception-workflows',
            workflowId: 27,
          });
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.course-page').screenshot({
            path: path.join(outDir, `vue-course-leave-${mode}-${vp.name}.png`),
          });
        }
      });
    }
  }

  test('manual booking keeps the latest date after a stale 422 response', async ({ page }) => {
    await openPilot(page, { pageName: 'course', mode: 'booking-race', viewport: { width: 1440, height: 900 } });

    // 課程查找 C-PR2: the row is one primary + ⋯, so 新增下一堂 now lives in the ⋯ menu.
    const moreMenu = page.locator('.am__trigger').first();
    await expect(moreMenu).toBeVisible({ timeout: 10_000 });
    await moreMenu.click();
    await page.getByRole('menuitem', { name: '＋新增下一堂' }).click();

    const dateInput = page.locator('.manual-session-modal input[type="date"]');
    await expect(dateInput).toBeVisible();
    // Ensure the initial request is in flight before changing the date.
    await expect.poll(() => page.locator('.manual-session-state').count()).toBeGreaterThan(0);

    const latestDate = await dateInput.inputValue().then((value) => {
      const date = new Date(`${value}T12:00:00`);
      date.setDate(date.getDate() + 1);
      return date.toISOString().slice(0, 10);
    });
    await dateInput.fill(latestDate);
    await dateInput.press('Tab');

    await expect(page.getByText('可以預約', { exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('button', { name: '建立這一堂', exact: true })).toBeEnabled();
    // Let the deliberately delayed old 422 complete; it must not replace the
    // result for the date the director currently sees.
    await page.waitForTimeout(240);
    await expect(page.getByText('可以預約', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '建立這一堂', exact: true })).toBeEnabled();
  });

  test('course management keeps disclosure and tab focus relationships explicit', async ({ page }) => {
    await openPilot(page, { pageName: 'course', mode: 'normal', viewport: { width: 1440, height: 900 } });

    const groupToggle = page.locator('.student-group-toggle').first();
    await expect(groupToggle).toBeVisible({ timeout: 10_000 });
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(groupToggle).toHaveAttribute('aria-controls', /student-group-panel-courses/);
    await expect(page.getByRole('button', { name: '專注 測試學生甲', exact: true })).toBeVisible();

    await groupToggle.press('Space');
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[role="tabpanel"]')).toHaveCount(0);
    await groupToggle.press('Enter');
    await expect(groupToggle).toHaveAttribute('aria-expanded', 'true');

    const courseTab = page.getByRole('tab', { name: '課程資料', exact: true }).first();
    const billingTab = page.getByRole('tab', { name: '帳務資料', exact: true }).first();
    await expect(courseTab).toHaveAttribute('aria-selected', 'true');
    await expect(courseTab).toHaveAttribute('aria-controls', /student-group-panel-courses/);
    await expect(courseTab).toHaveAttribute('tabindex', '0');
    await expect(billingTab).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('[role="tabpanel"]')).toBeVisible();
    await courseTab.press('ArrowRight');
    await expect(billingTab).toHaveAttribute('aria-selected', 'true');
    await expect(billingTab).toBeFocused();
    await expect(courseTab).toHaveAttribute('tabindex', '-1');
    await expect(billingTab).toHaveAttribute('tabindex', '0');
    await expect(page.locator('[role="tabpanel"]')).toHaveAttribute('aria-labelledby', /student-group-tab-billing/);
    await billingTab.press('ArrowLeft');
    await expect(courseTab).toBeFocused();
    await expect(courseTab).toHaveAttribute('aria-selected', 'true');
  });

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`calendar view ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'calendar', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.locator('.calendar-loading-overlay')).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
        } else if (mode === 'error') {
          await expect(page.getByRole('alert')).toContainText('課表資料暫時無法載入', { timeout: 10_000 });
        } else {
          await expect(page.getByText('排課與調課', { exact: true })).toBeVisible({ timeout: 10_000 });
          await expect(page.getByRole('button', { name: '回到今天的課表' })).toBeVisible();
          if (mode === 'empty') {
            await expect(page.getByText('目前無老師資料', { exact: false })).toBeVisible();
          } else {
            await expect(page.getByText('測試老師', { exact: false }).first()).toBeVisible();
          }
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.smart-cal-top').screenshot({ path: path.join(outDir, `vue-calendar-${mode}-${vp.name}.png`) });
        }
      });
    }
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    for (const mode of ['normal', 'empty', 'loading', 'error', 'long']) {
      test(`schedule discrepancy ${mode} @${vp.name}`, async ({ page }) => {
        const mocks = await openPilot(page, { pageName: 'discrepancy', mode, viewport: vp });

        if (mode === 'loading') {
          await expect(page.locator('.sdp-state-loading')).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
        } else if (mode === 'error') {
          await expect(page.locator('.sdp-state-error')).toContainText('課表回報暫時無法載入', { timeout: 10_000 });
        } else {
          await expect(page.getByText('課表回報管理', { exact: true })).toBeVisible({ timeout: 10_000 });
          if (mode === 'empty') {
            await expect(page.getByText('目前沒有待處理的回報', { exact: true })).toBeVisible();
          } else {
            const action = vp.width <= 768
              ? page.locator('.sdp-mcard').first().getByRole('button', { name: '接手處理', exact: true })
              : page.getByRole('button', { name: '接手處理', exact: true }).first();
            await expect(action).toBeVisible();
            await action.click();
            const detail = vp.width <= 768
              ? page.locator('.sdp-mcard').first().locator('.sdp-resolve-form')
              : page.locator('.sdp-detail').first();
            await expect(detail).toBeVisible();
          }
        }

        const layout = await page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
        if (mode === 'normal' || mode === 'long') {
          fs.mkdirSync(outDir, { recursive: true });
          await page.locator('.sdp-page').screenshot({ path: path.join(outDir, `vue-discrepancy-${mode}-${vp.name}.png`) });
        }
      });
    }
  }

  for (const vp of [
    { name: '390', width: 390, height: 844 },
    { name: '1440', width: 1440, height: 900 },
  ]) {
    test(`director workbench with urgent tasks @${vp.name}`, async ({ page }) => {
      await openPilot(page, { pageName: 'director', mode: 'dashboard', viewport: vp });
      await expect(page.getByRole('main', { name: '主任總覽' })).toBeVisible({ timeout: 10_000 });
      await expect(page.getByRole('button', { name: /家長請假/ })).toBeVisible();
      const ctas = page.locator('.director-task__action');
      expect(await ctas.count()).toBeGreaterThan(0);
      await expect(ctas.first()).toBeVisible();
      fs.mkdirSync(outDir, { recursive: true });
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-urgent-focus-${vp.name}.png`) });
      const leaveTask = page.getByRole('button', { name: /家長請假/ });
      await leaveTask.click();
      await expect(page.getByText('家長請假', { exact: true })).toBeVisible();
      const riskDisclosure = page.locator('.director-risk-disclosure');
      await expect(riskDisclosure.getByText('查看為什麼這些工作排在前面', { exact: true })).toBeVisible();
      await riskDisclosure.locator('summary').click();
      await expect(riskDisclosure.locator('.director-top-risks').getByText('家長請假待主任處理', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: '尋找補課時段', exact: true })).toBeVisible();
      await page.locator('.director-workbench-v2').screenshot({ path: path.join(outDir, `vue-director-v2-urgent-${vp.name}.png`) });
    });
  }

  // director-workbench-v2's 完整營運 view renders #schedule-sec/#evals-sec/#payments-sec
  // (surface-panel cards) with zero prior test coverage of their empty-state and count
  // wiring. (Note: PR #1515's AtCard/AtEmpty work-grid markup at the same IDs, further
  // down this file, is dead — permanently wrapped in `<template v-if="false">` since a
  // later redesign introduced director-workbench-v2; it never renders.)
  test('director 完整營運 cards show empty state with zero counts', async ({ page }) => {
    await openPilot(page, { pageName: 'director', mode: 'empty', viewport: { width: 1440, height: 900 } });
    await expect(page.getByRole('main', { name: '主任總覽' })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('tab', { name: '完整營運', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__full')).toBeVisible();

    await expect(page.locator('#schedule-sec .surface-panel__count')).toHaveText('0 堂');
    await expect(page.locator('#schedule-sec')).toContainText('今天沒有課程。');

    await expect(page.locator('#evals-sec .surface-panel__count')).toHaveText('0 筆');
    await expect(page.locator('#evals-sec')).toContainText('目前沒有待審核評量。');

    await expect(page.locator('#payments-sec .surface-panel__count')).toHaveText('0');
    await expect(page.locator('#payments-sec')).toContainText('目前沒有需要跟進的繳費提醒。');
  });

  test('director 完整營運 cards show counts and rows with data', async ({ page }) => {
    await openPilot(page, { pageName: 'director', mode: 'dashboard', viewport: { width: 1440, height: 900 } });
    await expect(page.getByRole('main', { name: '主任總覽' })).toBeVisible({ timeout: 10_000 });
    await page.getByRole('tab', { name: '完整營運', exact: true }).click();
    await expect(page.locator('.director-workbench-v2__full')).toBeVisible();

    await expect(page.locator('#schedule-sec .surface-panel__count')).toHaveText('1 堂');
    await expect(page.locator('#schedule-sec .director-schedule-row')).toHaveCount(1);

    await expect(page.locator('#evals-sec .surface-panel__count')).toHaveText('1 筆');
    await expect(page.locator('#evals-sec .director-evaluation-row')).toHaveCount(1);

    await expect(page.locator('#payments-sec .surface-panel__count')).toHaveText('1');
    await expect(page.locator('#payments-sec .director-payment-row')).toHaveCount(1);
  });

  test('billing top-level tabs show one selected panel', async ({ page }) => {
    await openPilot(page, { pageName: 'tuition', mode: 'normal', viewport: { width: 1440, height: 900 } });

    // PRD v3 §0: tabs are 帳單 / 月結 / 收款紀錄 / 代收代付; 已結清課程 lives in the reminder bar.
    const students = page.locator('#tuition-accounting-tab-students');
    const payments = page.locator('#tuition-accounting-tab-payments');

    await expect(students).toHaveAttribute('aria-controls', 'tuition-accounting-panel-students');
    await expect(page.locator('#tuition-accounting-panel-students')).toHaveCount(1);
    await expect(page.locator('#tuition-accounting-panel-payments')).toHaveCount(0);

    await page.locator('[data-testid="tc-reminder-settled"]').click();
    await expect(page.locator('#tuition-accounting-panel-settled')).toBeVisible();
    await expect(page.locator('#tuition-accounting-panel-students')).toHaveCount(0);

    await payments.click();
    await expect(page.locator('#tuition-accounting-panel-payments')).toBeVisible();
    await expect(page.locator('#tuition-accounting-panel-settled')).toHaveCount(0);
  });

  for (const width of [1440, 390]) {
    test(`billing missing monthly invoices are discoverable at ${width}px`, async ({ page }) => {
      await openPilot(page, { pageName: 'tuition', mode: 'normal', viewport: { width, height: 900 } });
      const request = page.waitForRequest(req => req.url().includes('/student-classes?'));
      await page.locator('#tuition-accounting-tab-monthly').click();
      const query = await request;
      expect(query.method()).toBe('GET');
      expect(new URL(query.url()).searchParams.get('branch_id')).toBe('1');
      expect(new URL(query.url()).searchParams.get('schedule_mode')).toBe('date');
      const review = page.locator('#tuition-accounting-panel-monthly');
      await expect(review).toContainText('測試月結學生');
      await expect(review).toContainText('2026-09 · 已上 4 堂 · 試算 NT$ 6,000');
      await expect(review).toContainText('4 堂沒有帳單');
      await expect(review).toContainText('2 堂超出合約日期');
      await expect(review.getByRole('button', { name: '前往課程核對' })).toBeVisible();
      await expect(page.locator('#tuition-accounting-panel-receivables')).toHaveCount(0);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
      fs.mkdirSync(outDir, { recursive: true });
      await page.screenshot({ path: path.join(outDir, `monthly-billing-review-${width}.png`), fullPage: true });
    });
  }

  test('teacher list tabs keep status workspace and RFID readable', async ({ page }) => {
    await openPilot(page, { pageName: 'teachers', mode: 'normal', viewport: { width: 390, height: 844 } });

    const active = page.locator('#teachers-tab-active');
    const pending = page.locator('#teachers-tab-pending');
    const suspended = page.locator('#teachers-tab-suspended');

    await expect(active).toHaveAttribute('aria-controls', 'teachers-panel-active');
    await expect(page.locator('#teachers-panel-active')).toBeVisible();
    await expect(page.locator('#teachers-panel-active')).toContainText('測試老師甲');
    await expect(page.locator('.status-tag.active')).toContainText('在職');
    await expect(page.locator('.rfid-tag').first()).toHaveText('TEACHER-A-001');
    const hasHorizontalOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(hasHorizontalOverflow).toBe(false);

    await pending.click();
    await expect(page.locator('#teachers-panel-pending')).toBeVisible();
    await expect(page.locator('#teachers-panel-pending')).toContainText('測試老師乙');
    await expect(page.locator('#teachers-panel-active')).toHaveCount(0);
    await expect(page.locator('.status-tag.pending')).toContainText('待審核');

    await suspended.click();
    await expect(page.locator('#teachers-panel-suspended')).toBeVisible();
    await expect(page.locator('#teachers-panel-suspended')).toContainText('測試老師丙');
    await expect(page.locator('#teachers-panel-pending')).toHaveCount(0);
    await expect(page.locator('.status-tag.suspended')).toContainText('停用');
  });

  test('director attendance keeps the action queue ahead of secondary context', async ({ page }) => {
    await openPilot(page, { pageName: 'attendance', mode: 'empty', viewport: { width: 1440, height: 900 } });

    const studentTab = page.locator('#attendance-tab-student');
    const teacherTab = page.locator('#attendance-tab-teacher');
    await expect(studentTab).toHaveAttribute('aria-selected', 'true');
    await expect(studentTab).toHaveAttribute('aria-controls', 'attendance-student-panel');
    await expect(page.locator('#attendance-student-panel')).toHaveAttribute('aria-labelledby', 'attendance-tab-student');
    await expect(page.locator('#attendance-student-panel')).toHaveAttribute('tabindex', '0');
    await expect(page.getByText('今日待點名堂次', { exact: true })).toBeVisible();
    await expect(page.locator('#attendance-student-panel > .att-secondary-summary')).not.toHaveAttribute('open', '');

    await teacherTab.click();
    await expect(teacherTab).toHaveAttribute('aria-selected', 'true');
    await expect(teacherTab).toHaveAttribute('aria-controls', 'attendance-teacher-panel');
    await expect(page.locator('#attendance-teacher-panel')).toHaveAttribute('aria-labelledby', 'attendance-tab-teacher');
    await expect(page.locator('#attendance-teacher-panel')).toHaveAttribute('tabindex', '0');
    await expect(page.getByRole('heading', { name: '先處理課表異常', exact: true })).toBeVisible();
    await expect(page.getByText('課表異常待處理', { exact: true })).toBeVisible();
    await expect(page.locator('#attendance-teacher-panel > .att-secondary-summary')).toHaveCount(3);
    await expect(page.locator('#attendance-teacher-panel > .att-secondary-summary').first()).not.toHaveAttribute('open', '');
  });

  test('dual-capability staff keeps one identity while switching scoped work context', async ({ page }) => {
    const meRequests = [];
    await openPilot(page, {
      pageName: 'app',
      mode: 'normal',
      viewport: { width: 1440, height: 900 },
      authProfile: {
        userId: 9901,
        capabilities: ['director', 'teacher'],
        initialActingAs: 'director',
        onAuthRequest: (request) => meRequests.push(request),
      },
    });

    const modeSwitch = page.locator('[data-guide="app-staff-mode-switch"]');
    await expect(modeSwitch).toBeVisible({ timeout: 15_000 });
    await expect(modeSwitch.locator('button')).toHaveCount(2);
    await expect(modeSwitch.locator('button').filter({ hasText: '主任' })).toHaveClass(/active/);
    expect(meRequests[0]).toEqual({ actingAs: 'director', userId: 9901 });

    await modeSwitch.locator('button').filter({ hasText: '老師' }).click();
    await expect(page.locator('.account-role').first()).toContainText('老師');
    await expect.poll(() => meRequests.at(-1)?.actingAs).toBe('teacher');
    expect(meRequests.every((request) => request.userId === 9901)).toBe(true);
    await expect(modeSwitch.locator('button').filter({ hasText: '老師' })).toHaveClass(/active/);

    await modeSwitch.locator('button').filter({ hasText: '主任' }).click();
    await expect(page.locator('.account-role').first()).toContainText('主任');
    await expect.poll(() => meRequests.at(-1)?.actingAs).toBe('director');
    expect(meRequests.every((request) => request.userId === 9901)).toBe(true);
    await expect(modeSwitch.locator('button').filter({ hasText: '主任' })).toHaveClass(/active/);
  });
});

// Sequencing doc item 6: no horizontal overflow on the motion pages at 375 and 390.
test.describe('No horizontal overflow (director home, Login)', () => {
  for (const width of [375, 390]) {
    for (const [name, pageName, mode] of [['director-home', 'director', 'dashboard'], ['login', 'login', 'normal']]) {
      test(`${name} @${width}`, async ({ page }) => {
        await openPilot(page, { pageName, mode, viewport: { width, height: 812 } });
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
        const wide = await page.evaluate(() => [...document.querySelectorAll('body *')]
          .filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1)
          // ignore content clipped by an ancestor (decorative layers such as the Login aurora)
          .filter((e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'hidden' || o === 'clip' || o === 'auto' || o === 'scroll') return false; } return true; })
          .map((e) => `${e.tagName}.${String(e.className).slice(0, 40)}`));
        expect(wide).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      });
    }
  }
});

// Visual regression (#4952). Tagged @visual: skipped by playwright.ui-foundation.config.js,
// run only by playwright.visual.config.js inside the pinned Playwright Docker image (npm run test:visual).
test.describe('Visual regression @visual', () => {
  const pages = [
    ['director-home', 'director', 'dashboard'],
    ['teacher-home', 'teacher', 'normal'],
    ['calendar', 'calendar', 'normal'],
    ['attendance', 'attendance', 'normal'],
    ['learning-records', 'learning', 'normal'],
    ['students', 'students', 'normal'],
    ['course-management', 'course', 'normal'],
    ['billing-center', 'tuition', 'normal'],
    ['login', 'login', 'normal'],
  ];
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '375', width: 375, height: 812 }]) {
    for (const [name, pageName, mode] of pages) {
      test(`${name} @${vp.name}`, async ({ page }) => {
        await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
        await openPilot(page, { pageName, mode, viewport: vp });
        await dismissOverlays(page);
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveScreenshot(`${name}-${vp.name}.png`, {
          fullPage: true,
          mask: [page.locator('[class*="avatar"], time')],
        });
      });
    }
  }
  // #4918: 課程管理 drawer (overview, 月曆, 帳務) from fixture props, no API.
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '375', width: 375, height: 812 }]) {
    for (const [name, tab, view] of [['course-manager-overview', 'overview'], ['course-manager-month', 'sessions', 'calendar'], ['course-manager-list', 'sessions', 'list'], ['course-manager-billing', 'billing']]) {
      test(`${name} @${vp.name}`, async ({ page }) => {
        await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.goto(`/pilot-mount.html?page=course-manager&tab=${tab}&mode=needs`);
        await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
        if (view) await page.locator(`[data-testid="course-manager-view-${view}"]`).click();
        await page.waitForLoadState('networkidle');
        await expect(page).toHaveScreenshot(`${name}-${vp.name}.png`, { fullPage: true });
      });
    }
  }
  // #4986 step 1: 家長首頁 3 cards at 390px, mocked dashboard (fake data only).
  test('parent-home @390', async ({ page }) => {
    await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.setItem('parent_portal_token', 'e2e-parent-visual-token'));
    await page.route('**/api/v1/**', (route) => {
      const url = new URL(route.request().url());
      const json = url.pathname.includes('/parent/dashboard') ? {
        student: { id: 1, name: '測試學生甲', grade: '國二', campus_id: 1, campus_name: '測試分校', line_linked: false },
        enrollments: [],
        cross_campus_access: 'actions',
        learning_records: [{ id: 1, Subject: '數學', SessionDate: '2026-09-12', Comment: '因式分解進步很多，回家複習講義。', parent_feedback: null }],
        learning_records_meta: { total: 1, has_more: false },
        attendance_history: [],
        upcoming_sessions: [{ id: 11, SessionDate: '2026-09-18', StartTime: '15:00', EndTime: '16:00', Status: 'scheduled', Subject: '英文' }],
        classes: [],
        payment_alerts: [{ class_id: 5, subject: '英文', remaining_sessions: 2, paid: false }],
        invoices: [{ id: 9, Status: 'unpaid', TotalAmount: 7200, DueDate: '2026-09-20', payments: [] }],
        announcements: [],
        assessment_progress: { items: [] },
      } : { data: [] };
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(json) });
    });
    await page.goto('/pilot-mount.html?page=parent');
    await expect(page.locator('[data-guide="parent-home"] [data-slot="card"]')).toHaveCount(3);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveScreenshot('parent-home-390.png', { fullPage: true });
  });
});
