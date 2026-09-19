// @ts-check
/**
 * in-app #317 / GitHub #3067 — 1:2/1:3 並排窄欄文字溢出回歸。
 * 合成資料 + pilot mount；不連 production、不上傳 reporter 原圖。
 */
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

function mondayYmd() {
  const d = new Date();
  const day = d.getDay();
  const monday = new Date(d);
  monday.setDate(d.getDate() - day + (day === 0 ? -6 : 1));
  return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
}

async function openDirectorMondayDayView(page) {
  await page.goto('/pilot-mount.html?page=calendar&role=director');
  await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1');
  await page.getByRole('button', { name: '日檢視', exact: true }).click();
  await page.getByRole('button', { name: /週一/ }).click();
  await page.waitForSelector('.teacher-grid .course-block');
}

async function installDirectorDayCalendarMocks(page, { tripleSlot = true } = {}) {
  const mondayStr = mondayYmd();
  const session = {
    access_token: 'e2e-director-token',
    token: 'e2e-director-token',
    user: { id: 8001, role: 'director', name: 'E2E Director', must_change_password: false },
  };

  await page.addInitScript((sess) => {
    localStorage.setItem('alltrue_session', JSON.stringify(sess));
    localStorage.setItem('app_branch', '1');
    localStorage.setItem('notifications_sound_enabled', '0');
  }, session);

  const tripleCourses = tripleSlot ? [
    { id: 501, student_id: 11, student_name: '巫同學', teacher_id: 9001, teacher_name: '陳老師', subject: 'math', class_type: 'one_on_three', day_of_week: 1, start_time: '14:00', end_time: '16:00', duration_hours: 2, status: 'active', room_id: 'A101', weeks: [1, 2, 3, 4, 5] },
    { id: 502, student_id: 12, student_name: '陳同學', teacher_id: 9001, teacher_name: '陳老師', subject: 'math', class_type: 'one_on_three', day_of_week: 1, start_time: '14:00', end_time: '16:00', duration_hours: 2, status: 'active', room_id: 'A101', weeks: [1, 2, 3, 4, 5] },
    { id: 503, student_id: 13, student_name: '廖同學', teacher_id: 9001, teacher_name: '陳老師', subject: 'math', class_type: 'one_on_three', day_of_week: 1, start_time: '14:00', end_time: '16:00', duration_hours: 2, status: 'active', room_id: 'A101', weeks: [1, 2, 3, 4, 5] },
  ] : [];

  const courses = [
    ...tripleCourses,
    { id: 504, student_id: 14, student_name: '李依珊', teacher_id: 9002, teacher_name: '林老師', subject: 'physics', class_type: 'one_on_three', day_of_week: 1, start_time: '14:00', end_time: '16:00', duration_hours: 2, status: 'active', room_id: 'B202', weeks: [1, 2, 3, 4, 5] },
    { id: 505, student_id: 15, student_name: '吳苡嫙', teacher_id: 9001, teacher_name: '陳老師', subject: 'math', class_type: 'one_on_one', day_of_week: 1, start_time: '16:00', end_time: '18:00', duration_hours: 2, status: 'active', room_id: 'A101', weeks: [1, 2, 3, 4, 5] },
  ];

  await page.route('**/api/v1/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname.includes('/student-classes')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: courses, meta: { total: courses.length, last_page: 1 } }) });
    }
    if (pathname.includes('/class-sessions')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ api_kind: 'projection', completeness: 'full', data: [], by_class: {} }) });
    }
    if (pathname.includes('/schedules')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    if (pathname.includes('/teachers')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 9001, username: '陳老師', name: '陳老師' }, { id: 9002, username: '林老師', name: '林老師' }] }) });
    }
    if (pathname.includes('/students')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: courses.map((c) => ({ id: c.student_id, name: c.student_name })) }) });
    }
    if (pathname.includes('/branches')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 1, name: '台北總部' }] }) });
    }
    if (pathname.includes('/rooms')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 'A101', name: 'A101', capacity: 3 }, { id: 'B202', name: 'B202', capacity: 3 }] }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, session_date: mondayStr }) });
  });
}

async function assertNoHorizontalClip(page, selector) {
  const overflow = await page.locator(selector).evaluateAll((nodes) => nodes.map((el) => ({
    clip: el.scrollWidth > el.clientWidth + 1,
    scrollWidth: el.scrollWidth,
    clientWidth: el.clientWidth,
    text: el.textContent?.trim() || '',
  })));
  for (const row of overflow) {
    expect(row.clip, `overflow on "${row.text}" (${row.scrollWidth}/${row.clientWidth})`).toBe(false);
  }
}

test.describe('Calendar 1:2/1:3 split-slot layout (#317)', () => {
  test('day view: triple 1:3 blocks are side-by-side with non-clipping meta', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const splitBlocks = page.locator('.teacher-col').first().locator('.course-block--split');
    await expect(splitBlocks).toHaveCount(3);

    const boxes = await splitBlocks.evaluateAll((els) => els.map((el) => el.getBoundingClientRect()));
    expect(boxes[1].left).toBeGreaterThan(boxes[0].left);
    expect(boxes[2].left).toBeGreaterThan(boxes[1].left);

    await expect(splitBlocks.first().locator('.cb-meta-row')).toBeVisible();
    await expect(splitBlocks.first().locator('.cb-type')).toHaveText('1:3');
    await expect(splitBlocks.first().locator('.cb-detail')).not.toBeEmpty();

    await assertNoHorizontalClip(page, '.teacher-col:first-child .course-block--split .cb-student');
    await assertNoHorizontalClip(page, '.teacher-col:first-child .course-block--split .cb-meta-row');

    const evidenceDir = path.resolve(process.cwd(), '../.agent-session/evidence/inapp-317');
    fs.mkdirSync(evidenceDir, { recursive: true });
    await page.locator('.teacher-col').first().locator('.slot').filter({ has: page.locator('.course-block--split') }).first().screenshot({
      path: path.join(evidenceDir, 'split-slot-317-after.png'),
    });
  });

  test('regression: single one_on_one block keeps full class type label', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const solo = page.locator('.course-block').filter({ hasText: '吳苡嫙' });
    await expect(solo).toBeVisible();
    await expect(solo).not.toHaveClass(/course-block--split/);
    await expect(solo.locator('.cb-type')).toHaveText('一對一');
    await solo.click();
    await expect(page.locator('.session-edit-modal')).toBeVisible();
    await page.locator('.session-edit-modal button.ghost', { hasText: '關閉' }).click();
  });

  test('before baseline: stacked meta lines clip in narrow split columns', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const baseline = await page.locator('.teacher-col').first().locator('.course-block--split').first().evaluate((block) => {
      // 模擬 reporter 原圖中 ~46px 寬的三等分窄欄（日檢視多學生同時段）
      block.style.width = '46px';
      block.style.minWidth = '46px';
      block.style.maxWidth = '46px';
      block.querySelectorAll('.cb-meta-row').forEach((n) => n.remove());
      block.querySelectorAll('.cb-type').forEach((n) => n.remove());
      block.querySelectorAll('.cb-detail').forEach((n) => n.remove());
      block.querySelectorAll('.cb-student').forEach((n) => {
        n.classList.remove('cbc-split-slot', 'cbc-split-triple');
        n.style.fontSize = '14px';
      });
      const detail = document.createElement('div');
      detail.className = 'cb-detail';
      detail.textContent = '數學';
      const type = document.createElement('div');
      type.className = 'cb-type';
      type.textContent = '一對三';
      block.appendChild(detail);
      block.appendChild(type);
      const student = block.querySelector('.cb-student');
      const typeEl = block.querySelector('.cb-type');
      return {
        studentClip: student ? student.scrollWidth > student.clientWidth + 1 : false,
        typeClip: typeEl ? typeEl.scrollWidth > typeEl.clientWidth + 1 : false,
        lineCount: block.querySelectorAll('.cb-student, .cb-detail, .cb-type').length,
      };
    });
    expect(baseline.lineCount).toBeGreaterThanOrEqual(3);
    expect(baseline.studentClip || baseline.typeClip).toBe(true);

    const afterFix = await page.locator('.teacher-col').first().locator('.course-block--split').nth(1).evaluate((block) => {
      block.style.width = '46px';
      block.style.minWidth = '46px';
      block.style.maxWidth = '46px';
      const student = block.querySelector('.cb-student');
      const meta = block.querySelector('.cb-meta-row');
      return {
        studentClip: student ? student.scrollWidth > student.clientWidth + 1 : false,
        metaClip: meta ? meta.scrollWidth > meta.clientWidth + 1 : false,
        hasMetaRow: !!meta,
      };
    });
    expect(afterFix.hasMetaRow).toBe(true);
    expect(afterFix.studentClip).toBe(false);
    expect(afterFix.metaClip).toBe(false);
  });
});
