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

// #527: outer contracts follow creation/ID newest-first; opening dates remain display metadata.
for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 900 }]) {
  test(`newest contracts across working/history segments @${viewport.width}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    // Every response is synthetic. Any unmocked external request is blocked.
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort('blockedbyclient'));
    await installApiMocks(page, 'normal', 'course');
    const contract = (id, reason = null) => ({
      id, student_id: 900493, student_name: '合成學生', teacher_name: `Chronology${id}`,
      subject: 'english', first_class_date: id === 50 ? '2025-01-01' : `2026-0${id / 10}-01`, status: reason ? 'inactive' : 'active',
      history_reason: reason, closed_reason: reason, is_active_course: !reason,
      ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8,
      sessions_used: reason ? 8 : 3, remaining_sessions: reason ? 0 : 5, payment_status: 'paid',
    });
    await page.route('**/api/v1/student-classes?**', (route) => route.fulfill({ json: {
      data: [contract(50), contract(40, 'completed'), contract(30), contract(20, 'settled'), contract(10)],
      total: 5, current_page: 1, last_page: 1,
    } }));
    await page.goto('/pilot-mount.html?page=course&mode=normal');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    const toggles = page.locator('.history-section__toggle');
    await expect(toggles).toHaveCount(2);
    const targets = await toggles.evaluateAll((buttons) => buttons.map((button) => button.getAttribute('aria-controls')));
    expect(new Set(targets).size).toBe(2);
    for (const toggle of await toggles.all()) await toggle.click();
    const rows = page.locator('.course-row, .history-course-card');
    await expect(rows).toHaveCount(5);
    expect(await rows.evaluateAll((nodes) => nodes.map((node) => Number(node.textContent.match(/Chronology(\d+)/)?.[1]))))
      .toEqual([50, 40, 30, 20, 10]);
    for (const [index, row] of (await rows.all()).entries()) {
      const date = ['2025-01-01', '2026-04-01', '2026-03-01', '2026-02-01', '2026-01-01'][index];
      await expect(row.getByText(`開課 ${date}`, { exact: true })).toBeVisible();
    }
    const positions = await rows.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().top));
    expect(positions).toEqual([...positions].sort((a, b) => a - b));
    for (const row of await rows.all()) {
      await row.scrollIntoViewIfNeeded();
      await expect(row).toBeInViewport();
    }
    for (const id of targets) await expect(page.locator(`[id="${id}"]`)).toHaveCount(1);
    const bounds = await page.locator('.group-table-wrap').first().boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width + 1);
    await rows.first().scrollIntoViewIfNeeded();
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `course-chronology-${viewport.width}.png`), fullPage: true });
  });
}

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

// #5141 visual follow-up: normal learning fixtures must render an empty queue,
// not the strict production reader's error state for an incomplete page DTO.
for (const width of [375, 1280]) {
  test(`learning visual fixture normal empty page @${width}`, async ({ page }) => {
    await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
    await openPilot(page, { pageName: 'learning', mode: 'normal', viewport: { width, height: 812 } });
    await expect(page.getByText('目前沒有待主任核准的評量', { exact: true })).toBeVisible();
    await expect(page.locator('.lr-error-state')).toHaveCount(0);
    await expect(page.getByRole('button', { name: '重新載入', exact: true })).toHaveCount(0);
  });

  test(`learning visual fixture existing error state @${width}`, async ({ page }) => {
    await openPilot(page, { pageName: 'learning', mode: 'error', viewport: { width, height: 812 } });
    await expect(page.locator('.lr-error-state')).toBeVisible();
    await expect(page.getByText('評量資料載入失敗', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '重新載入', exact: true })).toBeVisible();
    await expect(page.locator('.lr-empty-state')).toHaveCount(0);
  });
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
          await page.locator('.pending-leave-disclosure > summary').click();
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
          await expect(page.locator('.dsc-state-loading')).toBeVisible({ timeout: 10_000 });
          mocks.releaseHang();
        } else if (mode === 'error') {
          await expect(page.locator('.dsc-state-error')).toContainText('課表回報暫時無法載入', { timeout: 10_000 });
        } else {
          await expect(page.getByText('課表回報管理', { exact: true })).toBeVisible({ timeout: 10_000 });
          if (mode === 'empty') {
            await expect(page.getByText('目前沒有待處理的回報', { exact: true })).toBeVisible();
          } else {
            const action = vp.width <= 768
              ? page.locator('.dsc-mcard').first().getByRole('button', { name: '接手處理', exact: true })
              : page.getByRole('button', { name: '接手處理', exact: true }).first();
            await expect(action).toBeVisible();
            await action.click();
            const detail = vp.width <= 768
              ? page.locator('.dsc-mcard').first().locator('.dsc-resolve-form')
              : page.locator('.dsc-detail').first();
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
          await page.locator('.dsc-page').screenshot({ path: path.join(outDir, `vue-discrepancy-${mode}-${vp.name}.png`) });
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

// In-app #518 review: a confirm opened over the course drawer must stack above it and be clickable.
test('course drawer: a confirm dialog is topmost and clickable @drawer-confirm', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/pilot-mount.html?page=course-manager&mode=confirm');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
  await expect(page.locator('[data-testid="course-manager"]')).toBeVisible();
  const confirm = page.getByRole('dialog', { name: '暫停課程？' });
  await expect(confirm).toBeVisible();
  const cancel = page.locator('[data-testid="confirm-cancel"]');
  const topmost = await cancel.evaluate((el) => {
    const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!hit && (hit === el || el.contains(hit));
  });
  expect(topmost).toBe(true);
  await cancel.click({ timeout: 3000 });
});

// In-app #519: 新增課程 on 課程查找 opens the shared scheduler with the student still to be picked.
test('course page: 新增課程 opens the shared create-course form @create-course', async ({ page }) => {
  await openPilot(page, { pageName: 'course', mode: 'normal', viewport: { width: 1280, height: 900 } });
  await dismissOverlays(page);
  await page.locator('[data-testid="course-header-create"]').click();
  const form = page.locator('.universal-scheduler-modal').first();
  await expect(form).toBeVisible();
  await form.getByText('一般課程').first().click(); // same type step as from 學生管理
  await expect(form.locator('label:visible', { hasText: '學生 *' }).first()).toBeVisible();
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
    for (const [name, tab, view] of [['course-manager-overview', 'overview'], ['course-manager-month', 'sessions', 'calendar'], ['course-manager-list', 'sessions', 'list'], ['course-manager-settings', 'settings'], ['course-manager-billing', 'billing']]) {
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
  // #4989: 版本更新 at 1280 and 390. The page reads a module generated from docs/staff-updates, so the dev
  // server's answer for that module is replaced with fixed fake notes: the baseline does not move with every new note.
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`release-notes @${vp.name}`, async ({ page }) => {
      const fakeNote = (n, extra = {}) => ({
        id: `visual-${n}`, version: `2026.09.${String(15 - n).padStart(2, '0')}`, publishedAt: `2026-09-${String(15 - n).padStart(2, '0')}`, date: `2026-09-${String(15 - n).padStart(2, '0')}`,
        title: `測試公告標題 ${n}`, summary: `這是第 ${n} 則測試公告的白話摘要，說明這次改了什麼。`, importance: 'digest',
        sections: [{ title: '你現在可以', items: [`測試操作說明 ${n}-1`, `測試操作說明 ${n}-2`] }], ...extra,
      });
      const staffUpdates = [
        fakeNote(1, { importance: 'major', effectiveAt: '2026-09-16' }),
        fakeNote(2, { sections: [{ title: '怎麼操作', items: ['先開啟測試頁面。', '再按下測試按鈕。'] }, { title: '要注意', items: ['這是測試資料。'] }] }),
        fakeNote(3, { importance: 'action_required' }),
        fakeNote(4, { importance: 'major' }),
        fakeNote(5),
        fakeNote(6),
      ];
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.route('**/staffUpdates.generated.js*', (route) => route.fulfill({
        contentType: 'text/javascript', body: `export const staffUpdates = ${JSON.stringify(staffUpdates)};`,
      }));
      await page.goto('/pilot-mount.html?page=release-notes&role=director');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      await expect(page.getByTestId('release-note-featured')).toContainText('測試公告標題 1');
      await expect(page.getByTestId('release-note')).toHaveCount(3);
      // One 操作細節 is open in the baseline (set directly: no hover or focus ring in the picture).
      await page.locator('[data-testid="release-note"] details').first().evaluate((details) => { details.open = true; });
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`release-notes-${vp.name}.png`, { fullPage: true });
    });
  }

  // #4989: 分校健康 at 1280 and 390 with three fixed fake branches (one of each colour); generated_at is fixed.
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`branch-health @${vp.name}`, async ({ page }) => {
      const dim = (status, label, extra = {}) => ({ status, label, source: '測試來源', signals: [{ key: 'a', label: '測試訊號', value: '3 筆' }], period: 'rolling_28_days', next_step: '先看測試項目', ...extra });
      const dims = (over = {}) => ({ students: dim('green', '穩定'), teaching: dim('green', '穩定'), parents: dim('unavailable', '待接資料', { signals: [], period: null }), teachers: dim('green', '穩定'), operations: dim('green', '穩定'), ...over });
      const data = [
        { branch_id: 1, branch_name: '測試分校甲', status: 'red', headline: '教師訊號需要優先查看，其他已接入訊號穩定。', dimensions: dims({ teachers: dim('red', '緊急') }) },
        { branch_id: 2, branch_name: '測試分校乙', status: 'yellow', headline: '教學訊號需要注意。', dimensions: dims({ teaching: dim('yellow', '需注意') }) },
        { branch_id: 3, branch_name: '測試分校丙', status: 'green', headline: '目前沒有紅色或黃色訊號。', dimensions: dims() },
      ];
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.route('**/api/v1/admin/branch-health', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data, meta: { generated_at: '2026-09-15T10:00:00+08:00' } }) }));
      await page.goto('/pilot-mount.html?page=branch-health&role=super_admin');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      await expect(page.locator(vp.width <= 900 ? '.branch-health__mobile-card' : '.branch-health__table tbody tr')).toHaveCount(3);
      await expect(page.getByRole('heading', { name: '測試分校甲 詳情' })).toBeVisible();
      await page.mouse.move(0, 0);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`branch-health-${vp.name}.png`, { fullPage: true });
    });
  }

  // #4989: 科目管理 at 1280 and 390 with three fixed fake subjects (one shared, two campus-owned).
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`subject-settings @${vp.name}`, async ({ page }) => {
      const data = [{ id: 1, name: '測試共用科目', campus_id: null }, { id: 2, name: '測試分校科目甲', campus_id: 1 }, { id: 3, name: '測試分校科目乙', campus_id: 1 }];
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.route('**/api/v1/**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(new URL(route.request().url()).pathname === '/api/v1/subjects' ? data : { data: [] }) }));
      await page.goto('/pilot-mount.html?page=subject-settings');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      await expect(page.locator(vp.width <= 600 ? '.subject-mobile-card' : '.subject-table tbody tr')).toHaveCount(3);
      await page.mouse.move(0, 0);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`subject-settings-${vp.name}.png`, { fullPage: true });
    });
  }

  // #4989: 衝突審查 at 1280 and 390 with two fixed fake conflicts (two LINE accounts each), as a super admin.
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`binding-conflicts @${vp.name}`, async ({ page }) => {
      const side = (id, campus, masked, day) => ({ id, campus_id: campus, campus_name: campus === 1 ? '測試分校甲' : '測試分校乙', line_user_id_masked: masked, bound_at: `2026-10-0${day}T12:00:00+08:00` });
      const data = { conflicts: [
        { id: 7, student_id: 7, student_name: '測試學生甲', campus_count: 2, bindings: [side(11, 1, 'U1111111…aaaa', 1), side(12, 2, 'U2222222…bbbb', 2)] },
        { id: 8, student_id: 8, student_name: '測試學生乙', campus_count: 1, bindings: [side(21, 1, 'U3333333…cccc', 3), side(22, 1, 'U4444444…dddd', 4)] },
      ] };
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.route('**/api/v1/**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(new URL(route.request().url()).pathname === '/api/v1/bindings/conflicts' ? data : { data: [] }) }));
      await page.goto('/pilot-mount.html?page=binding-conflicts&role=super_admin');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      await expect(page.locator(vp.width <= 720 ? '.bcrp-mobile-card' : '.bcrp-table tbody tr')).toHaveCount(2);
      await page.mouse.move(0, 0);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`binding-conflicts-${vp.name}.png`, { fullPage: true });
    });
  }

  // #4989: 教室管理 at 1280 and 390 with three fixed fake rooms (two active, one inactive with a long memo).
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`classroom @${vp.name}`, async ({ page }) => {
      const data = [
        { id: 1, name: '測試教室甲', capacity: 12, memo: '靠窗，適合小組討論', is_active: true },
        { id: 2, name: '測試教室乙', capacity: 4, memo: '', is_active: true },
        { id: 3, name: '測試多功能教室', capacity: 24, memo: '設備收納於後方櫃體，請保持走道暢通', is_active: false },
      ];
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.route('**/api/v1/**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(new URL(route.request().url()).pathname === '/api/v1/rooms' ? data : { data: [] }) }));
      await page.goto('/pilot-mount.html?page=classroom');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      await expect(page.locator(vp.width <= 720 ? '.room-mobile-card' : '.room-table tbody tr')).toHaveCount(3);
      await page.mouse.move(0, 0);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`classroom-${vp.name}.png`, { fullPage: true });
    });
  }

  // #4989: 課表回報管理 at 1280 and 390 with three fixed fake reports; the first one is opened on the 處理說明 form.
  for (const vp of [{ name: '1280', width: 1280, height: 900 }, { name: '390', width: 390, height: 844 }]) {
    test(`schedule-discrepancy @${vp.name}`, async ({ page }) => {
      const report = (id, extra) => ({
        id, reporter_name: '測試老師', branch_name: '測試分校', student_name: `測試學生${id}`, session_date: '2026-09-15',
        time_range: '10:00–12:00', corrected_time_range: null, discrepancy_type_label: '時段不符', status: 'pending',
        notes: '', class_session_id: 5000 + id, created_at: '2026-09-15T09:30:00', acknowledged_at: null, resolved_at: null, ...extra,
      });
      const data = [
        report(1, { corrected_time_range: '11:00–13:00', notes: '請確認調課後的時段。' }),
        report(2, { discrepancy_type_label: '缺少堂次', time_range: '', status: 'pending', subject: '數學' }),
        report(3, { status: 'pending', notes: '這是一段較長的回報備註，用來確認小螢幕上會自然折行。' }),
      ];
      await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await installApiMocks(page, 'normal', 'discrepancy');
      await page.route(/schedule-discrepancies\?/, (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data }) }));
      await page.goto('/pilot-mount.html?page=discrepancy&mode=normal');
      await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
      const first = vp.width <= 768 ? page.locator('.dsc-mcard').first() : page.locator('.dsc-table tbody tr').first();
      await expect(first).toContainText('測試學生1');
      await first.getByRole('button', { name: '接手處理', exact: true }).click();
      await page.mouse.move(0, 0);
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(`schedule-discrepancy-${vp.name}.png`, { fullPage: true });
    });
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

  // 字級 大: same page with the large size saved (標準 is parent-home-390).
  // #4986 3 cards at 390px, mocked dashboard (fake data only).
  test('parent-home-large @390', async ({ page }) => {
    await page.clock.setFixedTime(new Date('2026-09-15T10:00:00+08:00'));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => { localStorage.setItem('parent_portal_token', 'e2e-parent-visual-token'); localStorage.setItem('alltrue_text_size', 'large'); });
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
    await expect(page).toHaveScreenshot('parent-home-large-390.png', { fullPage: true });
  });
});


// #527 redesign: real course page, existing mocks, no production traffic or baseline updates.
for (const width of [375, 1280]) {
  test(`course workbench preserves billing scroll and viewport bounds @${width}`, async ({ page }) => {
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort('blockedbyclient'));
    await openPilot(page, { pageName: 'course', mode: 'normal', viewport: { width, height: 900 } });
    await dismissOverlays(page);
    const bounds = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
    expect.soft(bounds.document).toBeLessThanOrEqual(width);
    expect.soft(bounds.body).toBeLessThanOrEqual(width);
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `course-workbench-default-${width}.png`), fullPage: true });
    const group = page.locator('.student-group-card').first();
    await group.getByRole('tab', { name: '帳務資料', exact: true }).click();
    const billing = group.locator('.student-billing-wrap');
    await expect(billing.locator('tbody .btn-invoices').first()).toBeAttached();
    expect(await billing.evaluate((el) => getComputedStyle(el).overflowX)).toBe('auto');
    if (width === 375) expect(await billing.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);
    await billing.evaluate((el) => { el.scrollLeft = el.scrollWidth; });
    const ledger = billing.locator('tbody .btn-invoices').first();
    await ledger.scrollIntoViewIfNeeded();
    await expect(ledger).toBeInViewport();
    await page.screenshot({ path: path.join(outDir, `course-workbench-billing-${width}.png`) });
    await ledger.click();
    await expect(page.getByText('學生帳務', { exact: true }).first()).toBeVisible();
  });
}

test('course workbench desktop tools stay clickable above the filter card', async ({ page }) => {
  await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
    ? route.fallback() : route.abort('blockedbyclient'));
  await openPilot(page, { pageName: 'course', mode: 'normal', viewport: { width: 1280, height: 900 } });
  await dismissOverlays(page);
  const tools = page.locator('.course-tools-menu');
  await tools.locator('summary').click();
  const collapse = tools.getByRole('button', { name: '全部收合', exact: true });
  const topmost = await collapse.evaluate((el) => {
    const rect = el.getBoundingClientRect();
    return el.contains(document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2));
  });
  expect(topmost).toBe(true);
  await collapse.click();
  await expect(page.locator('.student-group-toggle').first()).toHaveAttribute('aria-expanded', 'false');
  await tools.getByRole('button', { name: '全部展開', exact: true }).click();
  await expect(page.locator('.student-group-toggle').first()).toHaveAttribute('aria-expanded', 'true');
  fs.mkdirSync(outDir, { recursive: true });
  await page.screenshot({ path: path.join(outDir, 'course-workbench-tools-1280.png') });
});

for (const scenario of [
  { width: 390, height: 844, root: 16 },
  { width: 1280, height: 900, root: 16 },
  { width: 390, height: 844, root: 32 },
]) {
  test(`course contract workbench keeps facts, filters and actions reachable @${scenario.width}-root${scenario.root}`, async ({ page }) => {
    await page.setViewportSize({ width: scenario.width, height: scenario.height });
    await page.route('**/*', (route) => ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname)
      ? route.fallback() : route.abort('blockedbyclient'));
    await installApiMocks(page, 'normal', 'course');
    const contract = (id, extra = {}) => ({
      id, student_id: 900527, student_name: '合成學生', teacher_name: id === 50 ? '合成老師甲' : id === 40 ? '合成老師乙' : '合成老師丙', subject: '英文',
      first_class_date: id === 50 ? '2025-01-01' : '2026-09-01', status: 'active',
      ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8,
      sessions_used: 3, remaining_sessions: 5, payment_status: 'unpaid', class_type: 'one_on_one', ...extra,
    });
    const contracts = [contract(50), contract(40, { status: 'inactive', history_reason: 'completed', closed_reason: 'completed', payment_status: 'paid' }),
      contract(30, { status: 'inactive', history_reason: 'settled_pending', closed_reason: 'settled_pending', usage_balance_status: 'review_required', sessions_used: 8, remaining_sessions: 0 })];
    await page.route('**/api/v1/student-classes?**', (route) => {
      const status = new URL(route.request().url()).searchParams.get('status');
      const data = status ? contracts.filter((row) => row.status === status) : contracts;
      return route.fulfill({ json: { data, total: data.length, current_page: 1, last_page: 1 } });
    });
    await page.goto('/pilot-mount.html?page=course&mode=normal');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await dismissOverlays(page);
    await page.evaluate((rootSize) => { document.documentElement.style.fontSize = `${rootSize}px`; }, scenario.root);
    await expect(page.getByRole('heading', { level: 1, name: '課程查找', exact: true })).toBeVisible();
    const status = page.locator('#course-filter-status');
    expect(await status.evaluate((el) => el.closest('details') === null)).toBe(true);
    await status.selectOption('active');
    await expect(page.getByTestId('course-active-filters')).toContainText('進行中');
    await expect(page.locator('.course-row')).toHaveCount(1);
    await page.locator('.focus-btn').click();
    await expect(page.locator('.focus-mode-banner')).toContainText('篩選仍套用');
    await page.locator('.focus-mode-banner button').click();
    await expect(status).toHaveValue('active');
    await page.getByTestId('course-filter-clear').click();
    await expect(status).toHaveValue('');
    await expect(page.getByTestId('course-active-filters')).toHaveCount(0);
    await expect(page.locator('.course-row')).toHaveCount(2);
    await page.locator('.history-section__toggle').click();
    const cards = page.locator('.course-row, .history-course-card');
    expect(await cards.evaluateAll((rows) => rows.map((row) => Number(row.getAttribute('data-contract-id'))))).toEqual([50, 40, 30]);
    const primaryRow = page.locator('.course-row').filter({ has: page.locator('[data-course-id="50"]') });
    for (const field of await primaryRow.locator('[data-col], .cell-actions').all()) {
      await field.scrollIntoViewIfNeeded();
      await expect(field).toBeInViewport();
      const rect = await field.boundingBox();
      expect(rect.x).toBeGreaterThanOrEqual(0);
      expect(rect.x + rect.width).toBeLessThanOrEqual(scenario.width + 1);
    }
    const menu = primaryRow.getByRole('button', { name: /更多/ });
    await menu.scrollIntoViewIfNeeded();
    expect((await menu.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await menu.click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
    const quantityLabel = primaryRow.locator('[data-col="remaining"] .contract-field-label');
    const standardSize = await quantityLabel.evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    await page.getByRole('radio', { name: '大', exact: true }).click();
    expect(await quantityLabel.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThan(standardSize);
    await page.getByRole('radio', { name: '標準', exact: true }).click();
    const manager = primaryRow.getByTestId('course-manager-open');
    if (process.env.VITE_COURSE_MANAGER_V1 === 'true') {
      await expect(manager).toBeVisible();
      await manager.click();
      await expect(page.getByTestId('course-manager')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(page.getByTestId('course-manager')).toHaveCount(0);
      await expect(manager).toBeFocused();
    } else {
      await expect(manager).toHaveCount(0);
    }
    await expect(page.locator('.course-row').filter({ has: page.locator('[data-course-id="30"]') }).getByRole('button', { name: '堂數待對帳', exact: true })).toBeVisible();
    const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > innerWidth + 1);
    expect(overflow).toBe(false);
    fs.mkdirSync(outDir, { recursive: true });
    await primaryRow.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(outDir, `course-workbench-${scenario.width}-root${scenario.root}.png`), fullPage: scenario.root === 16 });
  });
}

// #4989 課表回報管理 inside the REAL app shell (mocked API): 16px gutter, help panel below the title, toast above the phone nav.
for (const vp of [{ width: 390, height: 844, titleLines: 2 }, { width: 1280, height: 900, titleLines: 1 }]) {
  test(`schedule discrepancy in the app shell @${vp.width}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const row = { id: 1, reporter_name: '測試老師', branch_name: '內湖分校', student_name: '測試學生', session_date: '2026-09-15', time_range: '10:00–12:00', corrected_time_range: '11:00–13:00', discrepancy_type_label: '時段不符', status: 'pending', notes: '請確認', class_session_id: 5, created_at: '2026-09-15T09:30:00' };
    await page.route('**/api/v1/**', async (route) => {
      const p = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') return route.fulfill({ json: { ok: true } });
      if (p.endsWith('/me')) return route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      if (p.endsWith('/branches') || p.endsWith('/campuses')) return route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      if (p.includes('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } });
      if (p.includes('/schedule-discrepancies/summary')) return route.fulfill({ json: { pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 } });
      if (p.includes('/schedule-discrepancies')) return route.fulfill({ json: { data: [row] } });
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=app');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    await page.getByText('老師回報課表不對').first().click();
    const root = page.getByTestId('schedule-discrepancy-page');
    await expect(root).toBeVisible();
    await expect(root.locator('.dsc-table tbody tr, .dsc-mcard').first()).toBeAttached();

    // Gutter: the first card sits shell 10px + page 6px (16px) inside the shell's content box on phones. Measure in the scroll
    // container's own coordinates: the shell's account menu overflows `.main-content` (overflow-x: hidden, scrollWidth > clientWidth),
    // so focus/scroll can leave it at scrollLeft 1 and shift every viewport-relative rect by 1px.
    if (vp.width === 390) {
      const gutter = await root.locator('.dsc-mcard').first().evaluate((el) => {
        const main = document.querySelector('.main-content');
        return Math.round(el.getBoundingClientRect().left - main.getBoundingClientRect().left + main.scrollLeft);
      });
      expect(gutter).toBe(16);
    } else {
      // Table headers use the caption token, not the global 12px th rule.
      const th = await root.locator('.dsc-table th').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
      expect(th).toBeGreaterThanOrEqual(15);
      // The 回報時間 cell stays on one line and the row buttons sit side by side, not stacked.
      const layout = await root.locator('.dsc-table tbody tr').first().evaluate((tr) => {
        const ts = tr.querySelector('.dsc-ts');
        const lh = parseFloat(getComputedStyle(ts).lineHeight);
        const tops = [...tr.querySelectorAll('.dsc-row-ops button')].map((b) => Math.round(b.getBoundingClientRect().top));
        return { lines: Math.round(ts.getBoundingClientRect().height / lh), tops };
      });
      expect(layout.lines).toBe(1);
      expect(new Set(layout.tops).size).toBe(1);
    }

    // Help open: the panel is a full-width block below the title and the title keeps its lines.
    await page.locator('[data-testid="sdp-help"] > summary').click();
    const m = await page.evaluate(() => {
      const h2 = document.querySelector('[data-testid="sdp-help"] h2');
      const panel = document.querySelector('[data-testid="sdp-help"] [role="note"]');
      const header = document.querySelector('[data-testid="schedule-discrepancy-page"] header');
      const lh = parseFloat(getComputedStyle(h2).lineHeight);
      return { lines: Math.round(h2.getBoundingClientRect().height / lh), panelTop: panel.getBoundingClientRect().top, titleBottom: h2.getBoundingClientRect().bottom, panelW: panel.getBoundingClientRect().width, headerW: header.getBoundingClientRect().width };
    });
    expect(m.lines).toBeLessThanOrEqual(vp.titleLines);
    expect(m.panelTop).toBeGreaterThanOrEqual(m.titleBottom);
    expect(m.panelW).toBeGreaterThan(m.headerW * 0.95);
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `discrepancy-shell-help-${vp.width}.png`) });
    await page.locator('[data-testid="sdp-help"] > summary').click();

    // Toast: acknowledging shows feedback that is visible and does not touch the phone bottom nav.
    await root.getByRole('button', { name: /^(標記已確認|已確認)$/ }).locator('visible=true').first().click();
    const toast = page.locator('.dsc-toast');
    await expect(toast).toBeVisible();
    await expect(toast).toContainText('已標記為處理中');
    await expect(toast).toHaveCSS('opacity', '1');
    const box = await toast.boundingBox();
    expect(box.y).toBeGreaterThanOrEqual(0);
    // Nothing (topbar, bottom nav) is stacked above the toast: the element at its centre and sides is the toast.
    const covered = await page.evaluate(({ x, y, width: w, height: h }) => [[x + w / 2, y + h / 2], [x + 16, y + h / 2], [x + w - 16, y + h / 2]]
      .some(([px, py]) => !document.elementFromPoint(px, py)?.closest('.dsc-toast')), box);
    expect(covered).toBe(false);
    const nav = page.locator('.mobile-bottom-nav');
    if (await nav.isVisible()) {
      const nb = await nav.boundingBox();
      const overlap = box.x < nb.x + nb.width && box.x + box.width > nb.x && box.y < nb.y + nb.height && box.y + box.height > nb.y;
      expect(overlap).toBe(false);
    }
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    expect(sw[0]).toBeLessThanOrEqual(sw[1]);
    await page.screenshot({ path: path.join(outDir, `discrepancy-shell-toast-${vp.width}.png`) });
  });
}

// #4989 分校健康 inside the REAL app shell (mocked API): 16px phone gutter, title lines, 44px+ controls, th token size.
for (const vp of [{ width: 390, height: 844, titleLines: 2 }, { width: 1280, height: 900, titleLines: 1 }]) {
  test(`branch health in the app shell @${vp.width}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const dim = (status, label) => ({ status, label, source: '測試來源', signals: [{ key: 'a', label: '測試訊號', value: '3 筆' }], period: 'current', next_step: '先看測試項目' });
    const dimensions = { students: dim('green', '穩定'), teaching: dim('yellow', '需注意'), parents: dim('unavailable', '待接資料'), teachers: dim('red', '緊急'), operations: dim('green', '穩定') };
    const data = [{ branch_id: 1, branch_name: '內湖分校', status: 'red', headline: '教師訊號需要優先查看。', dimensions }];
    await page.route('**/api/v1/**', async (route) => {
      const p = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') return route.fulfill({ json: { ok: true } });
      if (p.endsWith('/me')) return route.fulfill({ json: { id: 9001, name: 'E2E Admin', role: 'super_admin', campuses: [1], must_change_password: false, capabilities: [] } });
      if (p.endsWith('/branches') || p.endsWith('/campuses')) return route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      if (p.includes('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } });
      if (p.includes('/admin/branch-health')) return route.fulfill({ json: { data, meta: { generated_at: '2026-09-15T10:00:00+08:00' } } });
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=app&role=super_admin');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    // Reach the page like a person: phone 更多 sheet, desktop 系統管理 group.
    const entry = page.getByText('分校健康', { exact: true }).locator('visible=true');
    if (vp.width === 390) {
      await page.locator('.mobile-bottom-nav').getByText('更多').click();
      await page.getByPlaceholder(/搜尋學生、老師、課程或功能/).fill('分校健康');
    } else {
      await page.getByText('系統管理', { exact: true }).click();
    }
    await entry.first().click();
    const root = page.getByTestId('branch-health-board');
    await expect(root).toBeVisible();
    await expect(root.locator('.branch-health__table tbody tr, .branch-health__mobile-card').locator('visible=true').first()).toBeVisible();

    // The shell has no main landmark of its own: the page provides exactly one.
    await expect(page.locator('main, [role="main"]')).toHaveCount(1);
    const m = await page.evaluate(() => {
      const root = document.querySelector('[data-testid="branch-health-board"]');
      const h2 = root.querySelector('h2');
      const lh = parseFloat(getComputedStyle(h2).lineHeight);
      const small = [...root.querySelectorAll('button')].filter((b) => b.getBoundingClientRect().width > 0 && b.getBoundingClientRect().height < 44).length;
      const th = root.querySelector('thead th');
      const first = root.querySelector('.branch-health__mobile-card, [data-slot="card"]');
      return {
        lines: Math.round(h2.getBoundingClientRect().height / lh), small, h1s: document.querySelectorAll('h1').length,
        thSize: th && th.getBoundingClientRect().width ? parseFloat(getComputedStyle(th).fontSize) : null,
        left: Math.round(first.getBoundingClientRect().left - root.parentElement.getBoundingClientRect().left),
        parentLeft: Math.round(root.parentElement.getBoundingClientRect().left),
        sw: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
      };
    });
    expect(m.lines).toBeLessThanOrEqual(vp.titleLines);
    expect(m.small).toBe(0);
    expect(m.sw[0]).toBeLessThanOrEqual(m.sw[1]);
    if (vp.width === 390) {
      // Phone gutter: the first card's left edge sits 16px from the viewport (shell 10px + page 6px).
      const left = await root.locator('.branch-health__mobile-card').first().evaluate((el) => Math.round(el.getBoundingClientRect().left));
      expect(left).toBe(16);
    } else {
      expect(m.thSize).toBeGreaterThanOrEqual(15);
    }
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `branch-health-shell-${vp.width}.png`) });
  });
}

// #4989 科目管理 inside the REAL app shell (mocked API): 16px phone gutter, title lines, 44px+ controls, th token size,
// and both overlays (form dialog, delete confirm) inside the viewport, on top, and clear of the phone bottom nav.
for (const vp of [{ width: 375, height: 812 }, { width: 390, height: 844 }, { width: 1280, height: 900 }]) {
  test(`subject settings in the app shell @${vp.width}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const subjects = [{ id: 1, name: '測試共用科目', campus_id: null }, { id: 2, name: '測試分校科目', campus_id: 1 }];
    await page.route('**/api/v1/**', async (route) => {
      const p = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') return route.fulfill({ json: { ok: true } });
      if (p.endsWith('/me')) return route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      if (p.endsWith('/branches') || p.endsWith('/campuses')) return route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      if (p.includes('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } });
      if (p === '/api/v1/subjects') return route.fulfill({ json: subjects });
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=app&app_page=subject-settings');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    const root = page.getByTestId('subject-settings-page');
    await expect(root).toBeVisible();
    // The welcome overlay covers the page for a moment; a click retried under it would scroll the shell sideways.
    await expect(page.getByText('歡迎回來')).toBeHidden();
    const phone = vp.width <= 600;
    const rows = root.locator(phone ? '.subject-mobile-card' : '.subject-table tbody tr');
    await expect(rows).toHaveCount(2);

    // The page never had a main landmark; the migration adds none (the shell decides that).
    await expect(root.locator('main, [role="main"]')).toHaveCount(0);
    const m = await page.evaluate(() => {
      const root = document.querySelector('[data-testid="subject-settings-page"]');
      const h2 = root.querySelector('h2');
      const lh = parseFloat(getComputedStyle(h2).lineHeight);
      const small = [...root.querySelectorAll('button')].filter((b) => b.getBoundingClientRect().width > 0 && b.getBoundingClientRect().height < 44).length;
      const th = root.querySelector('thead th');
      return {
        lines: Math.round(h2.getBoundingClientRect().height / lh), small, h1s: root.querySelectorAll('h1').length, h2s: root.querySelectorAll('h2').length,
        thSize: th && th.getBoundingClientRect().width ? parseFloat(getComputedStyle(th).fontSize) : null,
        thTransform: th ? getComputedStyle(th).textTransform : null,
        sw: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
      };
    });
    expect(m.lines).toBe(1);
    expect(m.small).toBe(0);
    expect([m.h1s, m.h2s]).toEqual([0, 1]);
    expect(m.sw[0]).toBeLessThanOrEqual(m.sw[1]);
    if (phone) {
      // Phone gutter: shell 10px + page 6px = 16px, in the scroll container's own coordinates (#5189).
      const gutter = await rows.first().evaluate((el) => {
        const main = document.querySelector('.main-content');
        return Math.round(el.getBoundingClientRect().left - main.getBoundingClientRect().left + main.scrollLeft);
      });
      expect(gutter).toBe(16);
    } else {
      expect(m.thSize).toBeGreaterThanOrEqual(15);
      expect(m.thTransform).toBe('none');
    }
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `subject-settings-shell-${vp.width}.png`) });

    const assertReachable = async (dialog) => {
      const nav = page.locator('.mobile-bottom-nav');
      const nb = (await nav.isVisible()) ? await nav.boundingBox() : null;
      for (const button of await dialog.getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(vp.height);
        // Nothing (bottom nav, topbar) is stacked above the button's centre.
        const onTop = await button.evaluate((el) => {
          const r = el.getBoundingClientRect();
          return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
        });
        expect(onTop).toBe(true);
        if (nb) {
          const overlap = box.x < nb.x + nb.width && box.x + box.width > nb.x && box.y < nb.y + nb.height && box.y + box.height > nb.y;
          expect(overlap).toBe(false);
        }
      }
    };
    await root.getByRole('button', { name: '新增科目', exact: true }).click();
    const form = page.getByRole('dialog', { name: '新增科目', exact: true });
    await expect(form).toBeVisible();
    await expect(form.getByLabel('科目名稱')).toBeFocused();
    await assertReachable(form);
    await page.screenshot({ path: path.join(outDir, `subject-settings-shell-form-${vp.width}.png`) });
    await page.keyboard.press('Escape');
    await expect(form).toBeHidden();

    await rows.nth(1).getByRole('button', { name: '刪除', exact: true }).click();
    const confirm = page.getByRole('alertdialog', { name: '確認刪除', exact: true });
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('確定要刪除科目「測試分校科目」嗎？若有課程使用此科目則無法刪除。');
    await assertReachable(confirm);
    await page.screenshot({ path: path.join(outDir, `subject-settings-shell-confirm-${vp.width}.png`) });
    await confirm.getByRole('button', { name: '取消', exact: true }).click();
    await expect(confirm).toBeHidden();
    // Opening and closing the overlays leaves the shell where it was.
    expect(await page.evaluate(() => document.querySelector('.main-content').scrollLeft)).toBe(0);
  });
}

// #4989 教室管理 inside the REAL app shell (mocked API): 16px phone gutter, title lines, 44px+ controls, th token size,
// both guide anchors, and both overlays (form dialog, delete confirm) inside the viewport, on top, and clear of the phone bottom nav.
for (const vp of [{ width: 375, height: 812 }, { width: 390, height: 844 }, { width: 1280, height: 900 }]) {
  test(`classroom management in the app shell @${vp.width}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const rooms = [{ id: 1, name: '測試教室甲', capacity: 12, memo: '靠窗', is_active: true }, { id: 2, name: '測試教室乙', capacity: 24, memo: '', is_active: false }];
    const writes = [];
    await page.route('**/api/v1/**', async (route) => {
      const p = new URL(route.request().url()).pathname;
      if (route.request().method() !== 'GET') {
        writes.push(`${route.request().method()} ${p}`);
        return route.fulfill({ json: { ok: true } });
      }
      if (p.endsWith('/me')) return route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      if (p.endsWith('/branches') || p.endsWith('/campuses')) return route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      if (p.includes('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } });
      if (p === '/api/v1/rooms') return route.fulfill({ json: rooms });
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=app&app_page=classroom');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    const root = page.getByTestId('classroom-page');
    await expect(root).toBeVisible();
    // The welcome overlay covers the page for a moment; a click retried under it would scroll the shell sideways.
    await expect(page.getByText('歡迎回來')).toBeHidden();
    const phone = vp.width <= 720;
    const rows = root.locator(phone ? '.room-mobile-card' : '.room-table tbody tr');
    await expect(rows).toHaveCount(2);

    // The page never had a main landmark; the migration adds none (the shell decides that).
    await expect(root.locator('main, [role="main"]')).toHaveCount(0);
    await expect(root.locator('[data-guide="classroom-header"]')).toBeVisible();
    await expect(root.locator('[data-guide="classroom-table"]')).toHaveCount(1);
    const m = await page.evaluate(() => {
      const root = document.querySelector('[data-testid="classroom-page"]');
      const h2 = root.querySelector('h2');
      const lh = parseFloat(getComputedStyle(h2).lineHeight);
      const small = [...root.querySelectorAll('button')].filter((b) => b.getBoundingClientRect().width > 0 && b.getBoundingClientRect().height < 44).length;
      const th = root.querySelector('thead th');
      return {
        lines: Math.round(h2.getBoundingClientRect().height / lh), small, h1s: root.querySelectorAll('h1').length, h2s: root.querySelectorAll('h2').length,
        thSize: th && th.getBoundingClientRect().width ? parseFloat(getComputedStyle(th).fontSize) : null,
        thTransform: th ? getComputedStyle(th).textTransform : null,
        sw: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
      };
    });
    expect(m.lines).toBe(1);
    expect(m.small).toBe(0);
    expect([m.h1s, m.h2s]).toEqual([0, 1]);
    expect(m.sw[0]).toBeLessThanOrEqual(m.sw[1]);
    if (phone) {
      // Phone gutter: shell 10px + page 6px = 16px, in the scroll container's own coordinates (#5189).
      const gutter = await rows.first().evaluate((el) => {
        const main = document.querySelector('.main-content');
        return Math.round(el.getBoundingClientRect().left - main.getBoundingClientRect().left + main.scrollLeft);
      });
      expect(gutter).toBe(16);
    } else {
      expect(m.thSize).toBeGreaterThanOrEqual(15);
      expect(m.thTransform).toBe('none');
      // The table fits its card: no sideways scroll inside it at 1280.
      const fit = await root.locator('[data-guide="classroom-table"]').evaluate((el) => [el.scrollWidth, el.clientWidth]);
      expect(fit[0]).toBeLessThanOrEqual(fit[1]);
    }
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `classroom-shell-${vp.width}.png`) });

    const assertReachable = async (dialog) => {
      const nav = page.locator('.mobile-bottom-nav');
      const nb = (await nav.isVisible()) ? await nav.boundingBox() : null;
      for (const button of await dialog.getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(vp.height);
        // Nothing (bottom nav, topbar) is stacked above the button's centre.
        const onTop = await button.evaluate((el) => {
          const r = el.getBoundingClientRect();
          return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
        });
        expect(onTop).toBe(true);
        if (nb) {
          const overlap = box.x < nb.x + nb.width && box.x + box.width > nb.x && box.y < nb.y + nb.height && box.y + box.height > nb.y;
          expect(overlap).toBe(false);
        }
      }
    };
    await root.getByRole('button', { name: '新增教室', exact: true }).click();
    const form = page.getByRole('dialog', { name: '新增教室', exact: true });
    await expect(form).toBeVisible();
    await expect(form.getByLabel('教室名稱')).toBeFocused();
    await assertReachable(form);
    // A name of spaces only is refused next to the field, with both errors still clear of the bottom nav.
    await form.getByLabel('教室名稱').fill('   ');
    await form.getByRole('button', { name: '儲存', exact: true }).click();
    await expect(form.getByRole('alert')).toHaveText('請輸入教室名稱');
    await expect(form.getByLabel('教室名稱')).toBeFocused();
    await assertReachable(form);
    expect(writes).toEqual([]);
    await page.screenshot({ path: path.join(outDir, `classroom-shell-form-${vp.width}.png`) });
    await page.keyboard.press('Escape');
    await expect(form).toBeHidden();

    // The shell's floating feedback button can sit over the last card; the page leaves room to scroll the row clear of it.
    const del = rows.nth(1).getByRole('button', { name: '刪除教室：測試教室乙', exact: true });
    await del.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await del.click();
    const confirm = page.getByRole('alertdialog', { name: '確認刪除', exact: true });
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('確定要刪除教室「測試教室乙」嗎？此操作無法復原。');
    await assertReachable(confirm);
    await page.screenshot({ path: path.join(outDir, `classroom-shell-confirm-${vp.width}.png`) });
    await confirm.getByRole('button', { name: '取消', exact: true }).click();
    await expect(confirm).toBeHidden();
    expect(writes).toEqual([]);
    // Opening and closing the overlays leaves the shell where it was.
    expect(await page.evaluate(() => document.querySelector('.main-content').scrollLeft)).toBe(0);
  });
}

// #4989 衝突審查 inside the REAL app shell (mocked API, fake data): 16px phone gutter, title lines, 44px+ controls,
// th token size, and the confirm (also with a refusal reason in it) inside the viewport, on top, and clear of the phone bottom nav.
for (const vp of [{ width: 375, height: 812 }, { width: 390, height: 844 }, { width: 1280, height: 900 }]) {
  test(`binding conflict review in the app shell @${vp.width}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    const side = (id, masked) => ({ id, campus_id: 1, campus_name: '內湖分校', line_user_id_masked: masked, bound_at: '2026-10-01T12:00:00+08:00' });
    const conflicts = [
      { id: 7, student_id: 7, student_name: '測試學生甲', campus_count: 1, bindings: [side(11, 'U1111111…aaaa'), side(12, 'U2222222…bbbb')] },
      { id: 8, student_id: 8, student_name: '測試學生乙', campus_count: 1, bindings: [side(21, 'U3333333…cccc'), side(22, 'U4444444…dddd')] },
    ];
    const writes = [];
    const reads = [];
    await page.route('**/api/v1/**', async (route) => {
      const url = new URL(route.request().url());
      const p = url.pathname;
      if (route.request().method() !== 'GET') {
        writes.push(`${route.request().method()} ${p} ${route.request().postData()}`);
        return route.fulfill({ status: 422, json: { message: '測試：此衝突已由他人處理' } });
      }
      if (p.endsWith('/me')) return route.fulfill({ json: { id: 9001, name: 'E2E Director', role: 'director', campuses: [1], must_change_password: false, capabilities: [] } });
      if (p.endsWith('/branches') || p.endsWith('/campuses')) return route.fulfill({ json: [{ id: 1, name: '內湖分校', code: 'neihu' }] });
      if (p.includes('/notifications')) return route.fulfill({ json: { data: [], unread_count: 0 } });
      if (p === '/api/v1/bindings/conflicts') { reads.push(url.search); return route.fulfill({ json: { conflicts } }); }
      return route.fulfill({ json: { data: [] } });
    });
    await page.goto('/pilot-mount.html?page=app&app_page=binding-conflicts');
    await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
    const root = page.getByTestId('binding-conflicts-page');
    await expect(root).toBeVisible();
    // The welcome overlay covers the page for a moment; a click retried under it would scroll the shell sideways.
    await expect(page.getByText('歡迎回來')).toBeHidden();
    const phone = vp.width <= 720;
    const rows = root.locator(phone ? '.bcrp-mobile-card' : '.bcrp-table tbody tr');
    await expect(rows).toHaveCount(2);
    // A director reads only the own campus, and the filter is locked on it.
    expect(reads.length).toBeGreaterThanOrEqual(1);
    expect([...new Set(reads)]).toEqual(['?campus_id=1']);
    await expect(root.getByLabel('分校')).toBeDisabled();

    // The page never had a main landmark; the migration adds none (the shell decides that).
    await expect(root.locator('main, [role="main"]')).toHaveCount(0);
    const m = await page.evaluate(() => {
      const root = document.querySelector('[data-testid="binding-conflicts-page"]');
      const h2 = root.querySelector('h2');
      const lh = parseFloat(getComputedStyle(h2).lineHeight);
      const small = [...root.querySelectorAll('button, select')].filter((b) => b.getBoundingClientRect().width > 0 && b.getBoundingClientRect().height < 44).length;
      const th = root.querySelector('thead th');
      return {
        lines: Math.round(h2.getBoundingClientRect().height / lh), small, h1s: root.querySelectorAll('h1').length, h2s: root.querySelectorAll('h2').length,
        thSize: th && th.getBoundingClientRect().width ? parseFloat(getComputedStyle(th).fontSize) : null,
        thTransform: th ? getComputedStyle(th).textTransform : null,
        sw: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
      };
    });
    expect(m.lines).toBe(1);
    expect(m.small).toBe(0);
    expect([m.h1s, m.h2s]).toEqual([0, 1]);
    expect(m.sw[0]).toBeLessThanOrEqual(m.sw[1]);
    if (phone) {
      // Phone gutter: shell 10px + page 6px = 16px, in the scroll container's own coordinates (#5189).
      const gutter = await rows.first().evaluate((el) => {
        const main = document.querySelector('.main-content');
        return Math.round(el.getBoundingClientRect().left - main.getBoundingClientRect().left + main.scrollLeft);
      });
      expect(gutter).toBe(16);
    } else {
      expect(m.thSize).toBeGreaterThanOrEqual(15);
      expect(m.thTransform).toBe('none');
      // The table fits its card: no sideways scroll inside it at 1280.
      const fit = await root.locator('.bcrp-table').evaluate((el) => [el.scrollWidth, el.clientWidth]);
      expect(fit[0]).toBeLessThanOrEqual(fit[1]);
    }
    fs.mkdirSync(outDir, { recursive: true });
    await page.screenshot({ path: path.join(outDir, `binding-conflicts-shell-${vp.width}.png`) });

    const assertReachable = async (dialog) => {
      const nav = page.locator('.mobile-bottom-nav');
      const nb = (await nav.isVisible()) ? await nav.boundingBox() : null;
      for (const button of await dialog.getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(vp.height);
        // Nothing (bottom nav, topbar) is stacked above the button's centre.
        const onTop = await button.evaluate((el) => {
          const r = el.getBoundingClientRect();
          return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
        });
        expect(onTop).toBe(true);
        if (nb) {
          const overlap = box.x < nb.x + nb.width && box.x + box.width > nb.x && box.y < nb.y + nb.height && box.y + box.height > nb.y;
          expect(overlap).toBe(false);
        }
      }
    };
    // The shell's floating feedback button can sit over the last card; the page leaves room to scroll the row clear of it.
    const remove = rows.nth(1).getByRole('button', { name: '解除此綁定：測試學生乙 U4444444…dddd', exact: true });
    await remove.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await remove.click();
    const confirm = page.getByRole('alertdialog', { name: '解除此綁定', exact: true });
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('學生 測試學生乙 綁定了 2 個 LINE 帳號。確認解除 U4444444…dddd 的綁定嗎？');
    await expect(confirm.getByRole('button', { name: '取消', exact: true })).toBeFocused();
    await assertReachable(confirm);
    await page.screenshot({ path: path.join(outDir, `binding-conflicts-shell-confirm-${vp.width}.png`) });
    // A refused request: one POST with the director's action, the reason shows in the dialog, buttons still reachable.
    await confirm.getByRole('button', { name: '確認解除', exact: true }).click();
    await expect(confirm).toContainText('測試：此衝突已由他人處理');
    expect(writes).toEqual(['POST /api/v1/bindings/conflicts/8/resolve {"action":"delete","binding_id":22}']);
    await assertReachable(confirm);
    await page.screenshot({ path: path.join(outDir, `binding-conflicts-shell-confirm-error-${vp.width}.png`) });
    await confirm.getByRole('button', { name: '取消', exact: true }).click();
    await expect(confirm).toBeHidden();
    expect(writes).toHaveLength(1);
    // Opening and closing the overlay leaves the shell where it was.
    expect(await page.evaluate(() => document.querySelector('.main-content').scrollLeft)).toBe(0);
  });
}
