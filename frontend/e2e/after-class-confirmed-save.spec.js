import { test, expect } from '@playwright/test';
import { dismissOverlays } from './fixtures/dismissOverlays.js';

const baseURL = process.env.AFTER_CLASS_BASE_URL;
test.skip(!baseURL, 'Explicit isolated browser target required');
const text = '長中文課後紀錄：分數應用與解題說明，失敗後必須完整保留。'.repeat(8);

async function install(page, testInfo) {
  const state = { mode: 'error', saves: 0, saved: false, actor: 9001, sessionId: 9101, release: null, unexpected: [], errors: [] };
  const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Taipei' }).format(new Date());
  const record = { id: 9301, StudentID: 9401, student_id: 9401, TeacherID: 9001,
    effective_teacher_id: 9001, StudentClassID: 9201, ClassSessionID: 9101,
    student_name: '隔離測試學生長中文名稱', teacher_name: '隔離測試老師', Subject: '數學',
    SessionDate: day, StartTime: '00:00', EndTime: '00:30', Status: 'changes_requested',
    HomeworkStatus: 'completed', Performance: 'good', Progress: '', Comment: '', NextHomework: '' };
  await page.addInitScript(() => {
    if (!localStorage.getItem('alltrue_session')) localStorage.setItem('alltrue_session', JSON.stringify({
      access_token: 'isolated-after-class-only', user: { id: 9001, role: 'teacher', name: '隔離測試老師' },
    }));
    if (!localStorage.getItem('app_branch')) localStorage.setItem('app_branch', '1');
  });
  page.on('pageerror', error => state.errors.push(error.message));
  page.on('dialog', dialog => dialog.dismiss());
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== new URL(baseURL).origin) return route.abort();
    if (!url.pathname.startsWith('/api/')) return route.continue();
    const p = url.pathname;
    if (request.method() !== 'GET') {
      if (p === '/api/v1/learning-records/9301' && request.method() === 'POST') {
        state.saves++;
        if (state.mode === 'loading') await new Promise(resolve => { state.release = resolve; });
        if (state.mode === 'network') return route.abort('failed');
        if (state.mode === 'invalid') return route.fulfill({ status: 200, contentType: 'application/json', body: '{invalid' });
        if (state.mode === 'conflict') return route.fulfill({ status: 409, json: { message: '此堂紀錄已變更，請先確認。', existing_id: 9301 } });
        if (state.mode !== 'ok') return route.fulfill({ status: 503, json: { message: '隔離測試：暫時無法儲存' } });
        state.saved = true;
        Object.assign(record, request.postDataJSON(), { id: 9301, Status: 'pending' });
        return route.fulfill({ status: 200, json: record });
      }
      // Existing telemetry/ensure-past calls remain intercepted, never sent to production.
      if (/adoption|ensure-past|read|engagement/.test(p)) return route.fulfill({ json: { ok: true, data: [] } });
      state.unexpected.push(`${request.method()} ${p}`);
      return route.abort();
    }
    if (p === '/api/v1/me') return route.fulfill({ json: { id: state.actor, name: '隔離測試老師', role: 'teacher', campuses: [1, 2] } });
    if (/\/(branches|campuses)$/.test(p)) return route.fulfill({ json: [{ id: 1, name: '隔離分校甲' }, { id: 2, name: '隔離分校乙' }] });
    if (p.includes('/class-sessions')) return route.fulfill({ json: { api_kind: 'projection', completeness: 'full', by_class: {}, data: [{
      id: state.sessionId, student_id: 9401, student_class_id: 9201, teacher_id: state.actor, branch_id: 1,
      session_date: day, start_time: '00:00', end_time: '00:30', student_name: record.student_name,
      subject_name: '數學', status: 'attended', learning_record_id: 9301,
      learning_record_status: state.saved ? 'pending' : 'changes_requested',
    }] } });
    if (p.includes('/learning-records') && !/summary|feedback|count/.test(p)) return route.fulfill({ json: { data: [{...record, TeacherID:state.actor,effective_teacher_id:state.actor,ClassSessionID:state.sessionId}], total: 1, current_page: 1, last_page: 1 } });
    if (p.includes('/learning-pending-summary')) return route.fulfill({ json: { total: state.saved ? 0 : 1, changes_requested_learning_records: state.saved ? 0 : 1 } });
    if (p.includes('/teachers')) return route.fulfill({ json: { data: [{ id: 9001, name: '隔離測試老師' }] } });
    if (p.includes('/students')) return route.fulfill({ json: { data: [{ id: 9401, name: record.student_name }] } });
    return route.fulfill({ json: { data: [], total: 0 } });
  });
  await page.goto(`${baseURL}/?app_page=teacher-home`);
  await expect(page.getByRole('heading', { name: '教學工作台', exact: true })).toBeVisible();
  await dismissOverlays(page);
  state.screenshot = name => page.screenshot({ path: testInfo.outputPath(name), fullPage: true });
  return state;
}

async function openForm(page) {
  await page.getByRole('button', { name: '修改評量', exact: true }).first().click();
  await expect(page.locator('.lr-modal--learning-form')).toBeVisible();
}

for (const width of [390, 1440]) {
  test(`實際 App：失敗重試、快速跨頁、重複提交與權威成功 ${width}`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    const state = await install(page, testInfo);
    await openForm(page);
    const input = page.getByPlaceholder('紀錄本次上課內容...');
    await input.fill(text);
    await page.getByRole('button', { name: '儲存變更', exact: true }).click();
    await expect(page.locator('.lr-form [role="alert"]')).toBeVisible();
    await expect(input).toHaveValue(text);
    expect(state.saved).toBe(false);
    await state.screenshot('failed-input-retained.png');

    // Browser back is real App navigation: unmount with a fresh pending draft timer.
    await input.fill(`${text}立即返回`);
    await page.goBack();
    await expect(page).toHaveURL(/app_page=teacher-home/);
    await dismissOverlays(page);
    await expect(page.getByRole('button', { name: '修改評量', exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: '修改評量', exact: true }).first().click();
    await expect(input).toHaveValue(`${text}立即返回`);
    await input.fill(text);

    for (const mode of ['invalid', 'conflict', 'network']) {
      state.mode = mode;
      await page.getByRole('button', { name: '儲存變更', exact: true }).click();
      await expect(page.locator('.lr-form [role="alert"]')).toBeVisible();
      await expect(input).toHaveValue(text);
      expect(state.saved).toBe(false);
    }
    state.mode = 'loading';
    const before = state.saves;
    await page.getByRole('button', { name: '儲存變更', exact: true }).dblclick();
    await expect(page.getByRole('button', { name: '儲存中…', exact: true })).toBeDisabled();
    await expect.poll(() => state.saves).toBe(before + 1);
    await expect(input).toHaveValue(text);
    state.mode = 'ok';
    state.release();
    await expect(page.locator('.lr-modal--learning-form')).toHaveCount(0);
    await page.goto(`${baseURL}/?app_page=teacher-home`);
    await dismissOverlays(page);
    await expect(page.getByRole('button', { name: '修改評量', exact: true })).toHaveCount(0);
    await expect(page.locator('html')).toHaveJSProperty('scrollWidth', width);
    await state.screenshot('confirmed-return-to-workbench.png');
    expect(state.unexpected).toEqual([]);
    expect(state.errors).toEqual([]);
  });

  test(`實際 App：帳號／分校／堂次隔離及過期回應 ${width}`, async ({ page }, testInfo) => {
    await page.setViewportSize({width,height:900});
    const state = await install(page,testInfo);
    await openForm(page);
    const input = page.getByPlaceholder('紀錄本次上課內容...');
    await input.fill(text);
    state.mode='loading';
    await page.getByRole('button',{name:'儲存變更',exact:true}).click();
    await expect.poll(()=>state.saves).toBe(1);
    await page.locator('.lr-form').getByRole('button',{name:'關閉',exact:true}).click();
    const switchBranch = async id => {
      if(width===390) await page.locator('#mobile-branch-select-teacher').selectOption(String(id));
      else await page.locator('.branch-switcher').getByRole('button',{name:id===1?'隔離分校甲':'隔離分校乙',exact:true}).click();
    };
    const openRow = async () => {
      await page.locator('#lr-teacher-tab-all').click();
      await page.locator('.lr-record-card, .lr-table-row').first().getByRole('button',{name:/修改|編輯|填寫/}).first().click();
      await expect(input).toBeVisible();
    };
    await switchBranch(2);
    await openRow();
    await expect(input).toHaveValue('');
    state.mode='error'; state.release();
    await expect(page.getByRole('button',{name:'儲存變更',exact:true})).toBeEnabled();
    await expect(page.locator('.lr-form [role="alert"]')).toHaveCount(0);
    await input.fill('分校乙的獨立內容');
    await page.locator('.lr-form').getByRole('button',{name:'關閉',exact:true}).click();
    await switchBranch(1); await openRow();
    await expect(input).toHaveValue(text);
    await page.locator('.lr-form').getByRole('button',{name:'關閉',exact:true}).click();
    state.sessionId=9102;
    await page.goto(`${baseURL}/?app_page=teacher-home`); await dismissOverlays(page); await openForm(page);
    await expect(input).toHaveValue('');
    state.actor=9002; state.sessionId=9101;
    await page.evaluate(()=>localStorage.setItem('alltrue_session',JSON.stringify({access_token:'isolated-second-account',user:{id:9002,role:'teacher',name:'隔離帳號乙'}})));
    await page.goto(`${baseURL}/?app_page=teacher-home`); await dismissOverlays(page); await openForm(page);
    await expect(input).toHaveValue('');
    expect(state.unexpected).toEqual([]); expect(state.errors).toEqual([]);
  });
}
