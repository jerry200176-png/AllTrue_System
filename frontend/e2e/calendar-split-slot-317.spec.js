// @ts-check
/**
 * in-app #317 / GitHub #3067 — 1:2/1:3 並排窄欄文字可讀性。
 * 合成資料 + pilot mount；不連 production、不上傳 reporter 原圖。
 * before baseline 為同頁 DOM 模擬舊版垂直堆疊，非舊版 build。
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

async function installDirectorDayCalendarMocks(page, { tripleSlot = true, pairSlot = false } = {}) {
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

  const pairCourses = pairSlot ? [
    { id: 511, student_id: 21, student_name: '張同學', teacher_id: 9003, teacher_name: '黃老師', subject: 'english', class_type: 'one_on_two', day_of_week: 1, start_time: '10:00', end_time: '12:00', duration_hours: 2, status: 'active', room_id: 'C303', weeks: [1, 2, 3, 4, 5] },
    { id: 512, student_id: 22, student_name: '林同學', teacher_id: 9003, teacher_name: '黃老師', subject: 'english', class_type: 'one_on_two', day_of_week: 1, start_time: '10:00', end_time: '12:00', duration_hours: 2, status: 'active', room_id: 'C303', weeks: [1, 2, 3, 4, 5] },
  ] : [];

  const courses = [
    ...tripleCourses,
    ...pairCourses,
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
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 9001, username: '陳老師', name: '陳老師' },
            { id: 9002, username: '林老師', name: '林老師' },
            { id: 9003, username: '黃老師', name: '黃老師' },
          ],
        }),
      });
    }
    if (pathname.includes('/students')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: courses.map((c) => ({ id: c.student_id, name: c.student_name })) }) });
    }
    if (pathname.includes('/branches')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 1, name: '台北總部' }] }) });
    }
    if (pathname.includes('/rooms')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 'A101', name: 'A101', capacity: 3 },
            { id: 'B202', name: 'B202', capacity: 3 },
            { id: 'C303', name: 'C303', capacity: 2 },
          ],
        }),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, session_date: mondayStr }) });
  });
}

async function readTextMetrics(locator) {
  return locator.evaluate((el) => {
    const style = window.getComputedStyle(el);
    const text = (el.textContent || '').trim();
    const nowrap = style.whiteSpace === 'nowrap' || style.whiteSpace === 'pre';
    return {
      text,
      horizontalClip: nowrap && el.scrollWidth > el.clientWidth + 1,
      fontSize: parseFloat(style.fontSize || '0'),
      visible: el.offsetParent !== null || style.display !== 'none',
    };
  });
}

/** 原回報核心：班型/科目等 meta 子元素在 overflow:hidden 下仍須可辨識。 */
async function assertMetaChildReadable(locator, { expectText } = {}) {
  const row = await readTextMetrics(locator);
  expect(row.visible, `hidden meta "${row.text}"`).toBe(true);
  expect(row.text.length).toBeGreaterThan(0);
  expect(row.horizontalClip, `meta horizontal clip on "${row.text}"`).toBe(false);
  if (expectText) expect(row.text).toBe(expectText);
  expect(row.fontSize, `meta font too small on "${row.text}"`).toBeGreaterThanOrEqual(9);
}

/** 姓名：可 ellipsis，但短名在窄欄仍應完整可見（對齊附件 #264 情境）。 */
async function assertShortStudentNameReadable(locator, expectedName) {
  const row = await readTextMetrics(locator);
  expect(row.visible).toBe(true);
  expect(row.text).toBe(expectedName);
  expect(row.horizontalClip, `student name clip on "${row.text}"`).toBe(false);
  expect(row.fontSize).toBeGreaterThanOrEqual(9);
}

async function pinNarrowSplitColumn(page, blockLocator, widthPx = 46) {
  await blockLocator.evaluate((block, w) => {
    block.style.width = `${w}px`;
    block.style.minWidth = `${w}px`;
    block.style.maxWidth = `${w}px`;
  }, widthPx);
}

test.describe('Calendar 1:2/1:3 split-slot layout (#317)', () => {
  test('day view: triple 1:3 blocks side-by-side; child text readable', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const splitBlocks = page.locator('.teacher-col').first().locator('.course-block--split');
    await expect(splitBlocks).toHaveCount(3);

    const boxes = await splitBlocks.evaluateAll((els) => els.map((el) => el.getBoundingClientRect()));
    expect(boxes[1].left).toBeGreaterThan(boxes[0].left);
    expect(boxes[2].left).toBeGreaterThan(boxes[1].left);

    const first = splitBlocks.first();
    await pinNarrowSplitColumn(page, first, 46);
    await assertShortStudentNameReadable(first.locator('.cb-student'), '巫同學');
    await assertMetaChildReadable(first.locator('.cb-detail.cb-meta-item'));
    await assertMetaChildReadable(first.locator('.cb-type.cb-meta-item'), { expectText: '1:3' });

    const evidenceDir = path.resolve(process.cwd(), '../.agent-session/evidence/inapp-317');
    fs.mkdirSync(evidenceDir, { recursive: true });
    await page.locator('.teacher-col').first().locator('.slot').filter({ has: page.locator('.course-block--split') }).first().screenshot({
      path: path.join(evidenceDir, 'split-slot-317-after.png'),
    });
  });

  test('day view: pair 1:2 split blocks keep short label readable at narrow width', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: false, pairSlot: true });
    await openDirectorMondayDayView(page);

    const pairCol = page.locator('.teacher-col').filter({ has: page.locator('.course-block', { hasText: '張同學' }) }).first();
    const splitBlocks = pairCol.locator('.course-block--split');
    await expect(splitBlocks).toHaveCount(2);

    const firstPair = splitBlocks.first();
    await pinNarrowSplitColumn(page, firstPair, 46);
    await assertShortStudentNameReadable(firstPair.locator('.cb-student'), '張同學');
    await assertMetaChildReadable(firstPair.locator('.cb-type.cb-meta-item'), { expectText: '1:2' });
    await assertMetaChildReadable(firstPair.locator('.cb-detail.cb-meta-item'));
  });

  test('regression: single one_on_one block keeps full class type label', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const solo = page.locator('.course-block').filter({ hasText: '吳苡嫙' });
    await expect(solo).toBeVisible();
    await expect(solo).not.toHaveClass(/course-block--split/);
    const soloBlock = page.locator('.course-block').filter({ hasText: '吳苡嫙' });
    await assertShortStudentNameReadable(soloBlock.locator('.cb-student'), '吳苡嫙');
    await assertMetaChildReadable(soloBlock.locator('.cb-type'), { expectText: '一對一' });
    await solo.click();
    await expect(page.locator('.session-edit-modal')).toBeVisible();
    await page.locator('.session-edit-modal button.ghost', { hasText: '關閉' }).click();
  });

  test('synthetic before baseline: stacked meta clips; fix keeps type label readable', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installDirectorDayCalendarMocks(page, { tripleSlot: true });
    await openDirectorMondayDayView(page);

    const block = page.locator('.teacher-col').first().locator('.course-block--split').first();
    await pinNarrowSplitColumn(page, block, 46);

    const syntheticBefore = await block.evaluate((el) => {
      el.querySelectorAll('.cb-meta-row').forEach((n) => n.remove());
      el.querySelectorAll('.cb-type').forEach((n) => n.remove());
      el.querySelectorAll('.cb-detail').forEach((n) => n.remove());
      el.querySelectorAll('.cb-student').forEach((n) => {
        n.classList.remove('cbc-split-slot', 'cbc-split-triple');
        n.style.fontSize = '14px';
      });
      const detail = document.createElement('div');
      detail.className = 'cb-detail';
      detail.textContent = '數學';
      const type = document.createElement('div');
      type.className = 'cb-type';
      type.textContent = '一對三';
      el.appendChild(detail);
      el.appendChild(type);
      const student = el.querySelector('.cb-student');
      const typeEl = el.querySelector('.cb-type');
      return {
        synthetic: true,
        studentClip: student ? student.scrollWidth > student.clientWidth + 1 : false,
        typeClip: typeEl ? typeEl.scrollWidth > typeEl.clientWidth + 1 : false,
        lineCount: el.querySelectorAll('.cb-student, .cb-detail, .cb-type').length,
      };
    });
    expect(syntheticBefore.synthetic).toBe(true);
    expect(syntheticBefore.lineCount).toBeGreaterThanOrEqual(3);
    expect(syntheticBefore.studentClip || syntheticBefore.typeClip).toBe(true);

    await page.reload();
    await openDirectorMondayDayView(page);
    const fixed = page.locator('.teacher-col').first().locator('.course-block--split').nth(1);
    await pinNarrowSplitColumn(page, fixed, 46);
    await assertShortStudentNameReadable(fixed.locator('.cb-student'), '陳同學');
    await assertMetaChildReadable(fixed.locator('.cb-type.cb-meta-item'), { expectText: '1:3' });
  });
});
