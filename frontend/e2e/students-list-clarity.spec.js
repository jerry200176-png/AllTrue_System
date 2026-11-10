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

// #529 / #5149: real StudentsList + its real API compatibility wrapper.
// This existing foundation collection is excluded from credentialed smoke.
test.describe('529 course deletion result feedback', () => {
  test.use({ viewport: { width: 1440, height: 754 }, serviceWorkers: 'block' });

  async function openCourse(page, info, mode = '422', legacy = false) {
    const course = { id: 990001, student_id: 2001, subject: 'Math', subject_name: '數學',
      class_type: 'one_on_one', payment_type: 'session', payment_status: 'unpaid',
      sessions_purchased: 8, remaining_sessions: 8, status: 'active', stop: 0,
      branch_id: 1, teacher_id: 3000, teacher_name: '合成老師', days_of_week: [1],
      start_time: '14:00', end_time: '16:00', duration_hours: 2 };
    const state = { deletes: 0, refusedReads: 0, removed: false };
    const origin = new URL(info.project.use.baseURL).origin;
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) return route.abort();
      if (!url.pathname.startsWith('/api/')) return route.continue();
      const json = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      if (request.method() === 'DELETE' && url.pathname === '/api/v1/student-classes/990001') {
        state.deletes += 1;
        if (mode === 'network' || mode === 'network-committed') {
          if (mode === 'network-committed') state.removed = true; // Response is lost after a possible commit.
          return route.abort('failed');
        }
        if (mode === 'success' || mode === 'refresh-failure') {
          state.removed = true;
          return json({ message: 'Course deleted successfully' });
        }
        if (mode === 'malformed') return route.fulfill({ status: 422, contentType: 'text/html', body: '<html>synthetic upstream error</html>' });
        const status = Number(mode);
        return json({ message: status >= 500 ? 'SQLSTATE synthetic internal details' : '這門課已有上課紀錄，請使用結案。', code: 'course_has_history' }, status);
      }
      if (request.method() === 'POST' && url.pathname.endsWith('/session-dates')) return json({ data: [] });
      if (request.method() !== 'GET') throw new Error('Unexpected synthetic mutation.');
      if (url.pathname === '/api/v1/students') return json({ data: [students[1]], total: 1, last_page: 1 });
      if (url.pathname === '/api/v1/student-classes') {
        if ((legacy && url.searchParams.has('per_page')) || (state.removed && mode === 'refresh-failure')) {
          state.refusedReads += 1;
          return json({ message: 'Synthetic list unavailable' }, 503);
        }
        return json({ data: state.removed ? [] : [course], total: state.removed ? 0 : 1, last_page: 1 });
      }
      return json({ data: [], total: 0, last_page: 1 });
    });
    await page.goto('/pilot-mount.html?page=students');
    await page.locator('.student-name-cell').click();
    const card = page.locator('.student-course-card');
    await expect(card).toHaveCount(1);
    await card.locator('summary').click();
    await expect(card.getByRole('button', { name: '刪除', exact: true })).toBeVisible();
    return { card, state };
  }

  async function confirmDelete(page, card, accept = true) {
    page.once('dialog', dialog => accept ? dialog.accept() : dialog.dismiss());
    await card.getByRole('button', { name: '刪除', exact: true }).click();
  }

  for (const mode of ['403', '404', '409', '422', '500', 'malformed', 'network', 'network-committed']) {
    test(`529 shows a safe visible refusal and stops after one DELETE (${mode})`, async ({ page }, info) => {
      const { card, state } = await openCourse(page, info, mode);
      await confirmDelete(page, card);
      try {
        const toast = page.locator('.twu--error');
        await expect(toast).toBeVisible({ timeout: 2500 });
        if (['403', '404', '409', '422'].includes(mode)) await expect(toast).toContainText('請使用結案');
        else await expect(toast).toContainText(mode.startsWith('network') ? '尚未確認' : '無法刪除');
        await expect(toast).not.toContainText(/SQLSTATE|<html>|synthetic upstream/);
        expect(state.deletes).toBe(1);
        await expect(card).toHaveCount(1);
        expect(state.removed).toBe(mode === 'network-committed');
      } finally { console.log(JSON.stringify({ case: `529-${mode}`, delete_attempts: state.deletes })); }
    });
  }

  test('529 displays the actual compatibility-wrapper error without a second attempt', async ({ page }, info) => {
    const { card, state } = await openCourse(page, info, '422', true);
    expect(state.refusedReads).toBeGreaterThan(0); // Forces the existing legacy loader and real wrapper.
    await confirmDelete(page, card);
    await expect(page.locator('.twu--error')).toContainText('請使用結案', { timeout: 2500 });
    expect(state.deletes).toBe(1);
    await expect(card).toHaveCount(1);
  });

  test('529 cancellation sends no DELETE', async ({ page }, info) => {
    const { card, state } = await openCourse(page, info);
    await confirmDelete(page, card, false);
    await expect(card).toHaveCount(1);
    expect(state.deletes).toBe(0);
    await expect(page.locator('.twu')).toHaveCount(0);
  });

  for (const mode of ['success', 'refresh-failure']) {
    test(`529 confirms the successful deletion without replay (${mode})`, async ({ page }, info) => {
      const { card, state } = await openCourse(page, info, mode);
      await confirmDelete(page, card);
      await expect(page.locator('.twu--success')).toContainText('課程已刪除', { timeout: 2500 });
      expect(state.deletes).toBe(1);
      expect(state.removed).toBe(true);
      if (mode === 'refresh-failure') expect(state.refusedReads).toBeGreaterThan(0);
      else await expect(card).toHaveCount(0);
    });
  }
});

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

// #4970 step3a: the real student entry and profile, with large text and keyboard navigation.
test.describe('4970 student learning profile', () => {
  test.use({ serviceWorkers: 'block' });
  for (const width of [375, 1280]) {
    test(`shows actual history, score and school dates with keyboard @${width}`, async ({ page }, info) => {
      const writes = [];
      const errors = [];
      let schoolUnavailable = false;
      let primaryReads = 0, schoolReads = 0;
      const origin = new URL(info.project.use.baseURL).origin;
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(() => localStorage.setItem('alltrue_text_size', 'large'));
      await page.clock.setFixedTime(new Date('2026-11-10T03:00:00Z'));
      await page.setViewportSize({ width, height: 900 });
      await page.route('**/*', async route => {
        const r = route.request(), url = new URL(r.url());
        if (url.origin !== origin) return route.abort();
        if (!url.pathname.startsWith('/api/')) return route.continue();
        if (r.method() !== 'GET') { writes.push(r.method()); return route.abort(); }
        const json = body => route.fulfill({ json: body });
        if (url.pathname === '/api/v1/students') return json({ data: [students[0]], total: 1, last_page: 1 });
        if (url.pathname.endsWith('/learning-timeline')) {
          primaryReads += 1;
          if (primaryReads === 1) return route.fulfill({ status: 503, json: {} });
          return json({ items: [
          { type: 'evaluation', id: 9, date: '2026-11-10', subject: '數學', content: '（系統自動核准）', approval_status: 'approved', has_substantive_progress: false },
          { type: 'evaluation', id: 1, date: '2026-11-09', subject: '數學', progress: '完成第二單元', content: '分數應用題與訂正', attendance: '到班', approval_status: 'approved', has_substantive_progress: true },
          { type: 'exam_score', id: 2, date: '2026-11-01', subject: '數學', title: '第一段考', score: 0, max_score: 100, is_absent: false },
          { type: 'exam_score', id: 3, date: '2026-10-01', subject: '英文', title: '英文段考', score: null, max_score: 100, is_absent: true },
          ], coverage: { limit_per_source: 300, capped_sources: [] } });
        }
        if (url.pathname.endsWith('/exam-periods')) {
          schoolReads += 1;
          return schoolUnavailable ? route.fulfill({ status: 503, json: {} }) : json({ data: [{ id: 1, label: '第二段考', start_date: '2026-11-20', end_date: '2026-11-21' }] });
        }
        return json({ data: [], total: 0, last_page: 1 });
      });
      await page.goto('/pilot-mount.html?page=students');
      const entry = page.getByRole('button', { name: '學習檔案', exact: true });
      await entry.focus(); await page.keyboard.press('Enter');
      const profile = page.getByRole('dialog', { name: /學習檔案/ });
      await expect(profile).toContainText('載入失敗，請稍後再試');
      const retry = profile.getByRole('button', { name: '重新載入', exact: true });
      await expect(profile.getByRole('button', { name: '關閉學習檔案' })).toBeFocused();
      await page.keyboard.press('Tab'); await expect(retry).toBeFocused();
      // Finish finite entrance animations before measuring the real stable touch target.
      await page.screenshot({ path: info.outputPath(`profile-retry-${width}.png`), fullPage: true, animations: 'disabled' });
      expect(await retry.evaluate(el => getComputedStyle(el).fontSize)).toBe('19px');
      expect((await retry.boundingBox()).height).toBeGreaterThanOrEqual(44);
      expect(primaryReads).toBe(1); expect(schoolReads).toBe(0);
      await page.keyboard.press('Enter');
      await expect(profile).toBeVisible(); await expect(profile).toContainText('完成第二單元');
      await expect(profile).not.toContainText('（系統自動核准）');
      await expect(profile).toContainText('第二段考'); await expect(profile).toContainText('2026-11-20');
      expect(primaryReads).toBe(2); expect(schoolReads).toBe(1);
      await expect(page.locator('html')).toHaveAttribute('data-text-size', 'large');
      await page.screenshot({ path: info.outputPath(`profile-overview-${width}.png`), fullPage: true, animations: 'disabled' });
      const overview = profile.getByRole('tab', { name: '總覽', exact: true });
      expect(await overview.evaluate(el => getComputedStyle(el).fontSize)).toBe('19px');
      expect((await profile.boundingBox()).width).toBeLessThanOrEqual(width === 375 ? 359 : 768);
      await expect(profile).toBeFocused(); // Removed retry button returns focus to the dialog scope.
      await page.keyboard.press('Tab');
      await expect(profile.getByRole('button', { name: '關閉學習檔案' })).toBeFocused();
      await overview.focus(); await expect(overview).toBeFocused(); await page.keyboard.press('ArrowRight');
      await expect(profile.getByRole('tab', { name: '上課紀錄', exact: true })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(profile.getByRole('tab', { name: '上課紀錄', exact: true })).toHaveAttribute('aria-selected', 'true');
      await expect(profile).toContainText('分數應用題與訂正');
      await expect(profile).toContainText('（系統自動核准）');
      await page.keyboard.press('ArrowRight');
      await expect(profile.getByRole('tab', { name: '成績', exact: true })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(profile.getByRole('tab', { name: '成績', exact: true })).toHaveAttribute('aria-selected', 'true');
      await expect(profile).toContainText('0/100'); await expect(profile).toContainText('缺考');
      await page.screenshot({ path: info.outputPath(`profile-scores-${width}.png`), fullPage: true, animations: 'disabled' });
      await profile.getByLabel('科目', { exact: true }).selectOption('數學');
      await expect(profile).not.toContainText('缺考');
      expect(await profile.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
      await page.screenshot({ path: info.outputPath(`profile-${width}.png`), fullPage: true, animations: 'disabled' });
      await page.keyboard.press('Escape'); await expect(profile).toHaveCount(0); await expect(entry).toBeFocused();
      schoolUnavailable = true; await page.keyboard.press('Enter');
      await expect(profile).toContainText('校考日期暫時無法載入'); await expect(profile).toContainText('完成第二單元');
      await profile.getByRole('tab', { name: '上課紀錄', exact: true }).focus(); await page.keyboard.press('Enter');
      await expect(profile).toContainText('分數應用題與訂正');
      await page.keyboard.press('Escape'); await expect(profile).toHaveCount(0); await expect(entry).toBeFocused();
      expect(primaryReads).toBe(3); expect(schoolReads).toBe(2);
      expect(writes).toEqual([]); expect(errors).toEqual([]);
      console.log(JSON.stringify({ student_profile: '4970', width, large_text: true, keyboard: true, primary_reads: primaryReads, school_reads: schoolReads, writes: writes.length }));
    });
  }
});
