import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axe from 'axe-core';

vi.mock('../../../lib/authedFetch', () => ({ authedFetch: vi.fn() }));
vi.mock('../../../lib/sessionDates', () => ({ ymdInTaipei: () => '2026-11-13' }));
import { authedFetch } from '../../../lib/authedFetch';
import StudentStudyWeek from '../StudentStudyWeek.vue';
import { Dialog } from '../../ui/dialog';

const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const task = (id, due_date, extra = {}) => ({ id, student_id: 1, plan_unit_id: null, title: `任務${id}`, kind: 'homework', due_date, minutes: null, note: null, done_at: null, ...extra });
const PLAN = {
  id: 5, title: '七年級數學', status: 'active', percent_done: 25, units_behind: 1,
  next_exam: { sequence: 2, label: '第二次段考', start_date: '2026-12-01', days_left: 18 },
  units: [
    { id: 11, sort_order: 1, title: '整數', planned_start: '2026-11-02', planned_end: '2026-11-08' },
    { id: 12, sort_order: 2, title: '分數', planned_start: '2026-11-09', planned_end: '2026-11-20' },
  ],
};
const TASKS = [task(1, '2026-11-09', { title: '預習第三章', kind: 'preview', minutes: 30 }), task(2, '2026-11-11', { title: '寫習作', plan_unit_id: 12 }), task(3, '2026-11-13', { title: '訂正小考', kind: 'correction', done_at: '2026-11-12 18:00:00' })];

let calls;
let routes;
let tasks;
const wrappers = [];
const stubs = { DialogContent: { template: '<section><slot /></section>' } };
const mountIt = async (props = {}) => {
  const w = mount(StudentStudyWeek, { props: { studentId: 1, ...props }, global: { stubs }, attachTo: document.body });
  wrappers.push(w);
  await flushPromises();
  return w;
};
const byId = (w, id) => w.find(`[data-testid="${id}"]`);
const button = (w, text) => w.findAll('button').find((b) => b.text() === text);

beforeEach(() => {
  calls = []; routes = {}; tasks = TASKS;
  authedFetch.mockReset();
  authedFetch.mockImplementation(async (url, init = {}) => {
    const method = init.method || 'GET';
    calls.push({ method, url, body: init.body ? JSON.parse(init.body) : null });
    const custom = Object.entries(routes).find(([k]) => `${method} ${url}`.includes(k));
    if (custom) return custom[1](url, init);
    if (url.includes('/learning-plans')) return json({ data: [PLAN] });
    if (method === 'GET') return json({ data: tasks });
    return json({ data: tasks[0] });
  });
});
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); document.body.innerHTML = ''; });

describe('StudentStudyWeek (issue 5269 step 2)', () => {
  it('shows a loading state, then asks for Monday to Sunday and puts each task on its day', async () => {
    let release;
    routes['GET /api/v1/students/1/study-tasks'] = () => new Promise((r) => { release = () => r(json({ data: tasks })); });
    const w = await mountIt();
    expect(w.find('[data-slot="skeleton"]').exists()).toBe(true);
    release(); await flushPromises();
    expect(calls.find((c) => c.url.includes('study-tasks')).url).toContain('from=2026-11-09&to=2026-11-15');
    expect(byId(w, 'week-label').text()).toBe('11/09 – 11/15');
    expect(byId(w, 'day-2026-11-09').text()).toContain('預習第三章');
    expect(byId(w, 'day-2026-11-09').text()).toContain('預習');
    expect(byId(w, 'day-2026-11-09').text()).toContain('30 分鐘');
    expect(byId(w, 'day-2026-11-11').text()).toContain('寫習作');
    expect(byId(w, 'day-2026-11-11').text()).toContain('分數'); // the plan unit it belongs to
    expect(byId(w, 'day-2026-11-13').text()).toContain('今天');
    expect(byId(w, 'done-3').element.checked).toBe(true);
    expect(byId(w, 'done-1').element.checked).toBe(false);
    expect(w.text()).toContain('1/3');
  });

  it('shows the plan progress, next exam countdown and this week\'s units above the strip', async () => {
    const w = await mountIt();
    const plans = byId(w, 'week-plans').text();
    expect(plans).toContain('完成 25%');
    expect(plans).toContain('距離第二次段考還有 18 天');
    expect(byId(w, 'week-units').text()).toContain('分數（11/09–11/20）');
    expect(byId(w, 'week-units').text()).not.toContain('整數');
  });

  it('still works when the plan cannot be loaded', async () => {
    routes['GET /api/v1/students/1/learning-plans'] = () => json({ message: 'x' }, 500);
    const w = await mountIt();
    expect(w.text()).toContain('學習計畫暫時載入不了');
    expect(byId(w, 'day-2026-11-09').text()).toContain('預習第三章');
  });

  it('empty week: one sentence and one add action that opens the dialog on today', async () => {
    tasks = [];
    const w = await mountIt();
    expect(byId(w, 'week-empty').text()).toContain('這週還沒有安排');
    expect(byId(w, 'week-grid').exists()).toBe(false);
    await byId(w, 'empty-add').trigger('click');
    expect(byId(w, 'f-date').element.value).toBe('2026-11-13');
  });

  it('load error shows a retry that loads again', async () => {
    let fail = true;
    routes['GET /api/v1/students/1/study-tasks'] = () => (fail ? json({ message: 'boom' }, 500) : json({ data: tasks }));
    const w = await mountIt();
    expect(byId(w, 'load-error').text()).toContain('載入失敗');
    expect(byId(w, 'week-empty').exists()).toBe(false);
    fail = false;
    await button(w, '再試一次').trigger('click'); await flushPromises();
    expect(byId(w, 'load-error').exists()).toBe(false);
    expect(w.text()).toContain('預習第三章');
  });

  it('moves between weeks and back to this week', async () => {
    const w = await mountIt();
    expect(byId(w, 'week-today').element.disabled).toBe(true);
    await byId(w, 'week-next').trigger('click'); await flushPromises();
    expect(calls.filter((c) => c.url.includes('study-tasks')).at(-1).url).toContain('from=2026-11-16&to=2026-11-22');
    expect(byId(w, 'week-label').text()).toBe('11/16 – 11/22');
    expect(byId(w, 'week-today').element.disabled).toBe(false);
    await byId(w, 'week-prev').trigger('click'); await byId(w, 'week-prev').trigger('click'); await flushPromises();
    expect(byId(w, 'week-label').text()).toBe('11/02 – 11/08');
    await byId(w, 'week-today').trigger('click'); await flushPromises();
    expect(byId(w, 'week-label').text()).toBe('11/09 – 11/15');
  });

  it('drops the answer of a week the user already left', async () => {
    const answers = [];
    routes['GET /api/v1/students/1/study-tasks'] = (url) => new Promise((r) => { answers.push({ url, r }); });
    const w = await mountIt();
    await byId(w, 'week-next').trigger('click'); await flushPromises();
    expect(answers).toHaveLength(2);
    answers[1].r(json({ data: [task(9, '2026-11-17', { title: '下週的任務' })] })); await flushPromises();
    answers[0].r(json({ data: [task(8, '2026-11-10', { title: '上週的任務' })] })); await flushPromises(); // late answer for the old week
    expect(w.text()).toContain('下週的任務');
    expect(w.text()).not.toContain('上週的任務');
  });

  it('adds a task on the tapped day, sends only what the API accepts, then reloads the week', async () => {
    const w = await mountIt();
    await byId(w, 'add-2026-11-12').trigger('click');
    expect(byId(w, 'f-date').element.value).toBe('2026-11-12');
    await byId(w, 'f-title').setValue('  背單字  ');
    await byId(w, 'f-kind').setValue('review');
    await byId(w, 'f-minutes').setValue('25');
    await byId(w, 'f-note').setValue('第一到三課');
    await byId(w, 'f-unit').setValue('12');
    tasks = [...TASKS, task(7, '2026-11-12', { title: '背單字' })];
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post.url).toBe('/api/v1/students/1/study-tasks');
    expect(post.body).toEqual({ title: '背單字', kind: 'review', due_date: '2026-11-12', minutes: 25, note: '第一到三課', plan_unit_id: 12 });
    expect(byId(w, 'f-title').exists()).toBe(false);
    expect(byId(w, 'day-2026-11-12').text()).toContain('背單字');
  });

  it('a slow save cannot be dismissed by Esc or the close button, so its result never lands on another form', async () => {
    let release;
    routes['POST /api/v1/students/1/study-tasks'] = () => new Promise((r) => { release = () => r(json({ data: task(7, '2026-11-12', { title: '背單字' }) })); });
    const w = await mountIt();
    await byId(w, 'add-2026-11-12').trigger('click');
    await byId(w, 'f-title').setValue('背單字');
    await byId(w, 'f-save').trigger('click');
    await w.findComponent({ name: 'Dialog' }).vm.$emit('update:open', false);
    await w.vm.$nextTick();
    expect(byId(w, 'f-title').exists()).toBe(true); // still saving: the dialog stays
    release(); await flushPromises();
    expect(byId(w, 'f-title').exists()).toBe(false);
  });

  it('validates the form without calling the API', async () => {
    const w = await mountIt();
    await byId(w, 'add-2026-11-12').trigger('click');
    await byId(w, 'f-save').trigger('click');
    expect(byId(w, 'form-error').text()).toContain('任務名稱');
    await byId(w, 'f-title').setValue('x'); await byId(w, 'f-minutes').setValue('0');
    await byId(w, 'f-save').trigger('click');
    expect(byId(w, 'form-error').text()).toContain('1 到 1440');
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('keeps what the user typed when saving fails', async () => {
    routes['POST /api/v1/students/1/study-tasks'] = () => json({ message: '伺服器忙碌' }, 500);
    const w = await mountIt();
    await byId(w, 'add-2026-11-12').trigger('click');
    await byId(w, 'f-title').setValue('背單字'); await byId(w, 'f-note').setValue('備註內容');
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    expect(byId(w, 'form-error').text()).toContain('伺服器忙碌');
    expect(byId(w, 'f-title').element.value).toBe('背單字');
    expect(byId(w, 'f-note').element.value).toBe('備註內容');
    expect(byId(w, 'f-save').element.disabled).toBe(false);
  });

  it('403 on write: says so clearly and removes the write controls', async () => {
    routes['POST /api/v1/students/1/study-tasks'] = () => json({ message: 'Forbidden' }, 403);
    const w = await mountIt();
    await byId(w, 'add-2026-11-12').trigger('click');
    await byId(w, 'f-title').setValue('背單字');
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    expect(byId(w, 'form-error').text()).toContain('只能查看讀書計畫');
    expect(byId(w, 'f-title').element.value).toBe('背單字');
    expect(byId(w, 'f-save').element.disabled).toBe(true);
    await button(w, '取消').trigger('click');
    expect(byId(w, 'read-only-note').exists()).toBe(true);
    expect(byId(w, 'add-2026-11-12').exists()).toBe(false);
    expect(byId(w, 'title-1').element.tagName).toBe('DIV'); // a name, not a button
    expect(byId(w, 'done-1').element.disabled).toBe(true);
  });

  it('edits a task and moves it to another day with a date change', async () => {
    const w = await mountIt();
    await byId(w, 'title-2').trigger('click');
    expect(byId(w, 'f-title').element.value).toBe('寫習作');
    expect(byId(w, 'f-unit').element.value).toBe('12');
    await byId(w, 'f-date').setValue('2026-11-14');
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    const patch = calls.find((c) => c.method === 'PATCH');
    expect(patch.url).toBe('/api/v1/study-tasks/2');
    expect(patch.body).toMatchObject({ title: '寫習作', due_date: '2026-11-14', plan_unit_id: 12 });
  });

  it('says when a task was moved out of the shown week', async () => {
    const w = await mountIt();
    await byId(w, 'title-2').trigger('click');
    await byId(w, 'f-date').setValue('2026-11-20');
    tasks = TASKS.filter((t) => t.id !== 2);
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    expect(byId(w, 'notice').text()).toContain('已移到 週五 11/20');
    expect(w.text()).not.toContain('寫習作');
  });

  it('ticks at once, then keeps the server\'s task; the checkbox is disabled while the request runs', async () => {
    let finish;
    routes['PATCH /api/v1/study-tasks/1'] = () => new Promise((r) => { finish = () => r(json({ data: task(1, '2026-11-09', { title: '預習第三章', done_at: '2026-11-13 09:00:00' }) })); });
    const w = await mountIt();
    await byId(w, 'done-1').setValue(true);
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ done: true });
    expect(byId(w, 'done-1').element.checked).toBe(true);
    expect(byId(w, 'done-1').element.disabled).toBe(true);
    expect(byId(w, 'title-1').element.disabled).toBe(true);
    expect(w.text()).toContain('2/3');
    finish(); await flushPromises();
    expect(byId(w, 'done-1').element.disabled).toBe(false);
    expect(byId(w, 'done-1').element.checked).toBe(true);
  });

  it('rolls a failed tick back and says so', async () => {
    routes['PATCH /api/v1/study-tasks/1'] = () => json({ message: '網路不穩' }, 500);
    const w = await mountIt();
    await byId(w, 'done-1').setValue(true); await flushPromises();
    expect(byId(w, 'done-1').element.checked).toBe(false);
    expect(byId(w, 'action-error').text()).toContain('預習第三章');
    expect(byId(w, 'action-error').text()).toContain('已還原');
    expect(w.text()).toContain('1/3');
  });

  it('unticks a done task', async () => {
    const w = await mountIt();
    await byId(w, 'done-3').setValue(false);
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ done: false });
  });

  it('403 on a tick rolls back and switches to read-only', async () => {
    routes['PATCH /api/v1/study-tasks/1'] = () => json({ message: 'Forbidden' }, 403);
    const w = await mountIt();
    await byId(w, 'done-1').setValue(true); await flushPromises();
    expect(byId(w, 'done-1').element.checked).toBe(false);
    expect(byId(w, 'action-error').text()).toContain('只能查看讀書計畫');
    expect(byId(w, 'read-only-note').exists()).toBe(true);
  });

  it('deletes only after the confirm dialog, and not when it is cancelled', async () => {
    const w = await mountIt();
    await byId(w, 'title-2').trigger('click');
    await byId(w, 'f-delete').trigger('click'); await flushPromises();
    expect(document.querySelector('[data-testid="confirm-dialog-message"]').textContent).toContain('寫習作');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    document.querySelector('[data-testid="confirm-dialog-cancel"]').click(); await flushPromises();
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    await byId(w, 'f-delete').trigger('click'); await flushPromises();
    tasks = TASKS.filter((t) => t.id !== 2);
    document.querySelector('[data-testid="confirm-dialog-confirm"]').click(); await flushPromises();
    expect(calls.find((c) => c.method === 'DELETE').url).toBe('/api/v1/study-tasks/2');
    expect(w.text()).not.toContain('寫習作');
    expect(byId(w, 'notice').text()).toBe('已刪除');
  });

  it('a load error shows no empty days', async () => {
    routes['GET /api/v1/students/1/study-tasks'] = () => json({ message: 'boom' }, 500);
    const w = await mountIt();
    expect(byId(w, 'week-grid').exists()).toBe(false);
    expect(w.text()).not.toContain('沒有安排');
  });

  it('404 on a tick, an edit and a delete says the task is gone and reloads the week', async () => {
    const gone = '這個任務已不存在，已重新整理';
    routes['PATCH /api/v1/study-tasks/1'] = () => json({ message: 'No query results for model' }, 404);
    routes['DELETE /api/v1/study-tasks/2'] = () => json({ message: 'No query results for model' }, 404);
    const w = await mountIt();
    let reads = calls.filter((c) => c.method === 'GET' && c.url.includes('study-tasks')).length;
    await byId(w, 'done-1').setValue(true); await flushPromises();
    expect(byId(w, 'action-error').text()).toBe(gone);
    expect(byId(w, 'done-1').element.checked).toBe(false);
    expect(calls.filter((c) => c.method === 'GET' && c.url.includes('study-tasks')).length).toBe(reads + 1);
    reads += 1;
    await byId(w, 'title-1').trigger('click');
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    expect(byId(w, 'action-error').text()).toBe(gone);
    expect(byId(w, 'f-title').exists()).toBe(false);
    expect(calls.filter((c) => c.method === 'GET' && c.url.includes('study-tasks')).length).toBe(reads + 1);
    await byId(w, 'title-2').trigger('click');
    await byId(w, 'f-delete').trigger('click'); await flushPromises();
    document.querySelector('[data-testid="confirm-dialog-confirm"]').click(); await flushPromises();
    expect(byId(w, 'action-error').text()).toBe(gone);
    expect(w.text()).not.toContain('No query results');
  });

  it('an older list answer does not undo a tick confirmed after that list request started', async () => {
    let releaseList;
    const w = await mountIt();
    // A save on task 2 starts a list refresh that we hold back.
    routes['PATCH /api/v1/study-tasks/2'] = () => json({ data: tasks[1] });
    routes['GET /api/v1/students/1/study-tasks'] = () => new Promise((r) => { releaseList = () => r(json({ data: TASKS })); }); // the OLD snapshot: task 1 not done
    await byId(w, 'title-2').trigger('click');
    await byId(w, 'f-save').trigger('click'); await flushPromises();
    expect(releaseList).toBeTypeOf('function');
    // While it is pending, a tick on task 1 is confirmed by the server.
    routes['PATCH /api/v1/study-tasks/1'] = () => json({ data: task(1, '2026-11-09', { title: '預習第三章', kind: 'preview', minutes: 30, done_at: '2026-11-13 09:00:00' }) });
    await byId(w, 'done-1').setValue(true); await flushPromises();
    expect(byId(w, 'done-1').element.checked).toBe(true);
    releaseList(); await flushPromises();
    expect(byId(w, 'done-1').element.checked).toBe(true);
    calls.length = 0;
    await byId(w, 'done-1').setValue(false);
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ done: false });
  });

  describe('speed tools (issue 5269 step 3)', () => {
    const MATES = [{ id: 7, name: '同學甲' }, { id: 8, name: '同學乙' }];
    beforeEach(() => { routes['GET /api/v1/students/1/study-classmates'] = () => json({ data: MATES, max_targets: 30 }); });
    const openPicker = async (w) => { await byId(w, 'title-1').trigger('click'); await byId(w, 'f-apply').trigger('click'); await flushPromises(); };

    it('copies last week for the shown week, says how many, and reloads the list', async () => {
      routes['POST /api/v1/students/1/study-tasks/copy-week'] = () => json({ data: [task(9, '2026-11-09'), task(10, '2026-11-10')], skipped: 1 });
      const w = await mountIt();
      calls.length = 0;
      await byId(w, 'copy-week').trigger('click'); await flushPromises();
      expect(calls[0]).toMatchObject({ method: 'POST', url: '/api/v1/students/1/study-tasks/copy-week', body: { week_start: '2026-11-09' } });
      expect(byId(w, 'notice').text()).toBe('已複製上週的 2 個任務，另有 1 個這週已經有了');
      expect(calls.some((c) => c.method === 'GET' && c.url.includes('study-tasks'))).toBe(true);
      expect(byId(w, 'copy-week').attributes('disabled')).toBeUndefined();
    });

    it('sends the shown week after paging, ignores a second click while running, and explains an all-duplicate answer', async () => {
      let release;
      routes['POST /api/v1/students/1/study-tasks/copy-week'] = () => new Promise((r) => { release = () => r(json({ data: [], skipped: 3 })); });
      const w = await mountIt();
      await byId(w, 'week-next').trigger('click'); await flushPromises();
      calls.length = 0;
      await byId(w, 'copy-week').trigger('click');
      expect(byId(w, 'copy-week').attributes('disabled')).toBeDefined();
      await byId(w, 'copy-week').trigger('click');
      expect(calls.filter((c) => c.method === 'POST')).toHaveLength(1);
      expect(calls[0].body).toEqual({ week_start: '2026-11-16' });
      release(); await flushPromises();
      expect(byId(w, 'notice').text()).toBe('上週的任務這週都已經有了，沒有新增');
    });

    it('shows an error and keeps the week when copying fails; a refusal turns the page read-only', async () => {
      routes['POST /api/v1/students/1/study-tasks/copy-week'] = () => json({ message: '伺服器忙線' }, 500);
      const w = await mountIt();
      await byId(w, 'copy-week').trigger('click'); await flushPromises();
      expect(byId(w, 'action-error').text()).toContain('沒有複製成功');
      expect(byId(w, 'day-2026-11-09').text()).toContain('預習第三章');
      routes['POST /api/v1/students/1/study-tasks/copy-week'] = () => json({}, 403);
      await byId(w, 'copy-week').trigger('click'); await flushPromises();
      expect(byId(w, 'read-only-note').exists()).toBe(true);
      expect(byId(w, 'copy-week').exists()).toBe(false);
    });

    it('applies one task to the ticked classmates and reports created, skipped and refused counts', async () => {
      routes['POST /api/v1/study-tasks/1/apply-to-students'] = () => json({ data: { results: [], created: 1, skipped: 1, refused: 0 } });
      const w = await mountIt();
      await openPicker(w);
      expect(calls.some((c) => c.url === '/api/v1/students/1/study-classmates')).toBe(true);
      expect(byId(w, 'apply-go').attributes('disabled')).toBeDefined(); // nothing ticked yet
      await byId(w, 'mate-7').setValue(true); await byId(w, 'mate-8').setValue(true);
      expect(byId(w, 'apply-go').text()).toBe('套用到 2 位');
      calls.length = 0;
      await byId(w, 'apply-go').trigger('click'); await flushPromises();
      expect(calls[0]).toMatchObject({ method: 'POST', url: '/api/v1/study-tasks/1/apply-to-students', body: { student_ids: [7, 8] } });
      expect(byId(w, 'notice').text()).toBe('已套用到 1 位，1 位本來就有這個任務');
      expect(w.findAllComponents(Dialog).map((d) => d.props('open'))).toEqual([false, false]); // the picker closed
    });

    it('keeps the ticked students and shows the error when applying fails, so one more click retries', async () => {
      routes['POST /api/v1/study-tasks/1/apply-to-students'] = () => json({ message: '連線失敗' }, 500);
      const w = await mountIt();
      await openPicker(w);
      await byId(w, 'mate-8').setValue(true);
      await byId(w, 'apply-go').trigger('click'); await flushPromises();
      expect(byId(w, 'apply-error').text()).toContain('沒有套用成功');
      expect(byId(w, 'mate-8').element.checked).toBe(true);
      expect(byId(w, 'mate-7').element.checked).toBe(false);
      routes['POST /api/v1/study-tasks/1/apply-to-students'] = () => json({ data: { results: [], created: 1, skipped: 0, refused: 0 } });
      await byId(w, 'apply-go').trigger('click'); await flushPromises();
      expect(byId(w, 'notice').text()).toBe('已套用到 1 位');
    });

    it('select-all ticks every classmate, and an empty class explains itself', async () => {
      const w = await mountIt();
      await openPicker(w);
      await byId(w, 'apply-all').setValue(true);
      expect(byId(w, 'apply-go').text()).toBe('套用到 2 位');
      await byId(w, 'apply-all').setValue(false);
      expect(byId(w, 'apply-go').text()).toBe('套用到 0 位');
      w.unmount();
      routes['GET /api/v1/students/1/study-classmates'] = () => json({ data: [], max_targets: 30 });
      const w2 = await mountIt();
      await openPicker(w2);
      expect(byId(w2, 'apply-empty').text()).toContain('沒有其他學生');
    });
  });

  it('is axe clean', async () => {
    const w = await mountIt();
    await byId(w, 'title-1').trigger('click');
    const { violations } = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.html).join(' | ')}`)).toEqual([]);
  });
});
