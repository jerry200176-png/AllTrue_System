// @ts-check
import { test, expect } from '@playwright/test';
const course = { id: 10, student_id: 1, subject: 'math', subject_name: '數學', teacher_name: '原教師' };
const target = { id: 20, student_id: 1, subject: 'english', subject_name: '英文', teacher_name: '英文教師', StartDate: '2026-09-01', SessionCount: 1 };
const quote = { preview: true, execution_available: true, state_hash: 'a'.repeat(64), plan: { purchased_sessions: 8, used_sessions: 2, reserved_sessions: 1, available_sessions: 5, moved_sessions: 2, lesson_minutes: 120, unit_price: '1500', amount: 3000, destination_existing_outstanding: 12000 } };
const allocations = [{ id: 20, sessions: 1, used: 1, amount: 1500 }, { id: 21, sessions: 4, used: 3, amount: 6000 }];
const released = Array.from({ length: 5 }, (_, i) => ({ id: 100 + i, date: `2026-11-${20 + i}`, start_time: '10:00:00', end_time: '12:00:00' }));
const existingQuote = { preview: true, execution_available: true, state_hash: 'b'.repeat(64), released_lessons: released,
  plan: { allocations, released_session_ids: released.map(s => s.id), source_sessions: 3, source_used: 2, source_remaining: 1, source_amount: 4500 } };
async function open(page, size, existing = false) {
  const records = [], groups = [], writes = [];
  await page.route('**/api/**', async route => {
    const req = route.request(), url = new URL(req.url());
    if (url.pathname === '/api/v1/student-classes' && req.method() === 'GET') return route.fulfill({ json: { data: [course, target, ...(existing ? [{ ...target, id: 21, subject: 'science', subject_name: '自然', StartDate: '2026-10-01', SessionCount: 4 }] : [])], total: existing ? 3 : 2, last_page: 1 } });
    if (url.pathname.endsWith('/preview') && req.method() === 'POST') return route.fulfill({ json: url.pathname.includes('/existing-credit-allocations') ? existingQuote : quote });
    if (url.pathname.endsWith('/existing-credit-allocations') && req.method() === 'GET') return route.fulfill({ json: { data: groups, current_page: 1, last_page: 1 } });
    if (url.pathname.endsWith('/existing-credit-allocations') && req.method() === 'POST') {
      writes.push(req.postDataJSON());
      const g = { id: 8, source_course_id: 10, sessions: 5, amount: 7500, targets: allocations, reason: req.postDataJSON().reason, reversed: false };
      groups.push(g); return route.fulfill({ status: 201, json: { allocation_group: g } });
    }
    if (url.pathname.endsWith('/existing-credit-allocations/8/reverse') && req.method() === 'POST') {
      groups[0].reversed = true; return route.fulfill({ json: { allocation_group: groups[0] } });
    }
    if (req.method() === 'GET' && url.pathname.endsWith('/subject-credit-transfer')) return route.fulfill({ json: { data: records, current_page: 1, last_page: 1 } });
    if (url.pathname.endsWith('/subject-credit-transfer') && req.method() === 'POST') {
      const r = { id: 7, source_course_id: 10, destination_template_course_id: 20, target_course_id: 30, moved_sessions: 2, amount: 3000, reason: req.postDataJSON().reason, reversed: false };
      records.push(r); return route.fulfill({ status: 201, json: { transfer: r } });
    }
    if (url.pathname.endsWith('/7/reverse') && req.method() === 'POST') {
      records[0].reversed = true; return route.fulfill({ json: { transfer: records[0] } });
    }
    throw new Error(`Unexpected synthetic request ${req.method()} ${url.pathname}`);
  });
  await page.goto(`/subject-credit-pilot-mount.html?size=${size}`);
  await page.getByRole('button', { name: '跨科轉用與紀錄', exact: true }).click();
  if (existing) {
    await page.locator('#credit-mode-existing').check();
    await page.locator('#credit-existing-20').check(); await page.locator('#credit-existing-21').check();
    await page.locator('#credit-reason').fill('已付學費抵付另兩科，不再額外收款');
    await page.getByRole('button', { name: '預覽抵付與排課影響', exact: true }).click();
    await expect(page.getByTestId('credit-preview')).toBeVisible();
    return writes;
  }
  await page.locator('#credit-target').selectOption('20');
  await page.locator('#credit-sessions').fill('2');
  await page.locator('#credit-reason').fill('改用另一科，原已付費堂數保留完整帳務與上課歷史');
  await page.getByRole('button', { name: '試算轉用', exact: true }).click();
  await expect(page.getByTestId('credit-preview')).toBeVisible();
}
for (const width of [375, 768, 1536]) for (const size of ['standard', 'large']) {
  test(`#563 named existing courses, explicit releases and whole inverse at ${width} ${size}`, async ({ page }, info) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.setViewportSize({ width, height: 900 }); const writes = await open(page, size, true);
    const dialog = page.getByRole('dialog'), preview = page.getByTestId('credit-preview');
    await expect(preview).toContainText('隔離測試學生'); await expect(preview).toContainText('英文 · 英文教師');
    await expect(preview).toContainText('自然'); await expect(preview).toContainText('7,500');
    await expect(preview).toContainText('2026-11-24 10:00–12:00');
    const confirm = page.getByRole('button', { name: '確認抵付既有課程', exact: true });
    await expect(confirm).toBeDisabled(); await page.locator('#credit-release-confirm').check(); await expect(confirm).toBeEnabled();
    const overflow = await dialog.evaluate(el => Math.max(el.scrollWidth - el.clientWidth, document.documentElement.scrollWidth - innerWidth));
    expect(overflow).toBeLessThanOrEqual(1);
    await page.screenshot({ path: info.outputPath('existing-credit-preview.png') });
    await confirm.click(); await expect(page.getByRole('status')).toContainText('不需再付這筆學費');
    expect(writes).toHaveLength(1); expect(writes[0]).toMatchObject({ target_course_ids: [20, 21], released_session_ids: released.map(s => s.id), state_hash: existingQuote.state_hash });
    await page.getByRole('button', { name: '還原整批抵付', exact: true }).click();
    await expect(page.locator('#credit-reverse-reason')).toBeFocused(); await page.locator('#credit-reverse-reason').fill('恢復原來源課程');
    await page.getByRole('button', { name: '確認還原', exact: true }).click(); await expect(page.getByRole('status')).toContainText('已確認還原');
    await expect(dialog).toContainText('已還原');
    await page.getByRole('button', { name: '關閉', exact: true }).click();
    await expect(page.getByRole('button', { name: '跨科轉用與紀錄', exact: true })).toBeFocused();
    expect(errors).toEqual([]);
  });
}
for (const width of [375, 768, 1536]) for (const size of ['standard', 'large']) {
  test(`#535 preview/confirmation/history/reverse at ${width} ${size}`, async ({ page }, info) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.setViewportSize({ width, height: 900 }); await open(page, size);
    await expect(page.getByTestId('credit-preview')).toContainText('保留排課 1 堂');
    await expect(page.getByTestId('credit-preview')).toContainText('隔離測試學生');
    await expect(page.getByTestId('credit-preview')).toContainText('12,000 元，這次不變更');
    const layout = await page.getByRole('dialog').evaluate(el => ({
      overflow: el.scrollWidth - el.clientWidth, pageOverflow: document.documentElement.scrollWidth - innerWidth,
      buttons: [...el.querySelectorAll('button')].filter(b => b.getBoundingClientRect().height > 0).map(b => b.getBoundingClientRect().height),
      fontSize: parseFloat(getComputedStyle(el.querySelector('input[type="number"]')).fontSize),
    }));
    expect(layout.overflow).toBeLessThanOrEqual(1); expect(layout.pageOverflow).toBeLessThanOrEqual(1);
    expect(layout.buttons.every(h => h >= 44)).toBe(true); expect(layout.fontSize).toBeGreaterThanOrEqual(16);
    const screenshot = info.outputPath('preview.png');
    await page.screenshot({ path: screenshot });
    await info.attach('preview', { path: screenshot, contentType: 'image/png' });
    await page.getByRole('button', { name: '確認跨科轉用', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('已確認轉用');
    await expect(page.getByRole('dialog')).toContainText('數學 · 原教師 → 英文 · 英文教師');
    await page.getByRole('button', { name: '還原這筆轉用', exact: true }).click();
    await expect(page.locator('#credit-reverse-reason')).toBeFocused();
    await page.locator('#credit-reverse-reason').fill('尚未排課，改回原科');
    await page.getByRole('button', { name: '確認還原', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('已確認還原');
    await expect(page.getByRole('dialog')).toContainText('已還原');
    await page.getByRole('button', { name: '關閉', exact: true }).click();
    await expect(page.getByRole('button', { name: '跨科轉用與紀錄', exact: true })).toBeFocused();
    expect(errors).toEqual([]);
  });
}
