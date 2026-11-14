import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axe from 'axe-core';

vi.mock('../../../lib/sessionDates', () => ({ ymdInTaipei: () => '2026-11-13' }));
vi.mock('../../../api', () => ({ getParentStudyTasks: vi.fn() }));
import { getParentStudyTasks } from '../../../api';
import ParentStudyWeek from '../ParentStudyWeek.vue';

const row = (id, due_date, extra = {}) => ({ id, title: `任務${id}`, kind: 'homework', due_date, minutes: null, done_at: null, ...extra });
const ROWS = [
  row(1, '2026-11-09', { title: '預習第三章', kind: 'preview', minutes: 30, done_at: '2026-11-09' }),
  row(2, '2026-11-13', { title: '寫習作', minutes: 45 }),
  row(3, '2026-11-13', { title: '訂正小考', kind: 'correction' }),
];

const wrappers = [];
const mountIt = async (props = {}) => {
  const w = mount(ParentStudyWeek, { props: { token: 'tok-a', ...props }, attachTo: document.body });
  wrappers.push(w);
  await flushPromises();
  return w;
};
const byId = (w, id) => w.find(`[data-testid="${id}"]`);
const button = (w, text) => w.findAll('button').find((b) => b.text() === text);

beforeEach(() => { getParentStudyTasks.mockReset(); getParentStudyTasks.mockResolvedValue({ data: ROWS }); });
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); document.body.innerHTML = ''; });

describe('ParentStudyWeek (issue 5269 step 4)', () => {
  it('shows a loading state, then asks for this week with the parent token', async () => {
    let release;
    getParentStudyTasks.mockImplementation(() => new Promise((r) => { release = () => r({ data: ROWS }); }));
    const w = await mountIt();
    expect(byId(w, 'study-loading').exists()).toBe(true);
    expect(w.find('[data-slot="skeleton"]').exists()).toBe(true);
    expect(w.attributes('aria-busy')).toBe('true');
    release(); await flushPromises();
    expect(getParentStudyTasks).toHaveBeenCalledWith('tok-a', '2026-11-09');
    expect(byId(w, 'study-loading').exists()).toBe(false);
    expect(byId(w, 'week-label').text()).toBe('11/09 – 11/15');
  });

  it('lists only the days that have tasks, with kind, minutes and plain done state', async () => {
    const w = await mountIt();
    const days = w.findAll('[data-testid="study-days"] > li');
    expect(days.map((d) => d.find('h4').text())).toEqual(['週一 11/09', '週五 11/13']);
    expect(byId(w, 'day-2026-11-13').attributes('aria-current')).toBe('date');
    expect(byId(w, 'day-2026-11-13').text()).toContain('今天');
    const first = byId(w, 'day-2026-11-09');
    expect(first.text()).toContain('預習第三章');
    expect(first.text()).toContain('預習');
    expect(first.text()).toContain('約 30 分鐘');
    expect(first.text()).toContain('已完成（11/09）');
    expect(byId(w, 'day-2026-11-13').findAll('[data-testid="task-open"]').map((e) => e.text())).toEqual(['還沒完成', '還沒完成']);
    expect(byId(w, 'day-2026-11-13').text()).not.toContain('約 分鐘');
  });

  it('shows the week progress in words from the same list', async () => {
    const w = await mountIt();
    expect(byId(w, 'study-progress').text()).toContain('已完成 1 項，共 3 項');
    getParentStudyTasks.mockResolvedValue({ data: [row(1, '2026-11-09', { done_at: '2026-11-09' })] });
    const all = await mountIt();
    expect(byId(all, 'study-progress').text()).toContain('這週的 1 項任務都完成了');
  });

  it('has no write control: only week navigation buttons exist, and no input', async () => {
    const w = await mountIt();
    expect(w.findAll('button').map((b) => b.text())).toEqual(['上一週', '本週', '下一週']);
    expect(w.findAll('input, textarea, select, [role="checkbox"]')).toHaveLength(0);
  });

  it('empty week: one friendly sentence, no progress, no error', async () => {
    getParentStudyTasks.mockResolvedValue({ data: [] });
    const w = await mountIt();
    expect(byId(w, 'study-empty').text()).toBe('這週老師還沒有安排讀書任務');
    expect(byId(w, 'study-progress').exists()).toBe(false);
    expect(byId(w, 'study-error').exists()).toBe(false);
  });

  it('error: says so in plain words, hides the list, and 再試一次 loads again', async () => {
    getParentStudyTasks.mockRejectedValueOnce(Object.assign(new Error('boom'), { status: 500 }));
    const w = await mountIt();
    expect(byId(w, 'study-error').text()).toContain('讀書任務暫時載入不了');
    expect(byId(w, 'study-error').text()).not.toContain('boom');
    expect(byId(w, 'study-days').exists()).toBe(false);
    await byId(w, 'study-retry').trigger('click'); await flushPromises();
    expect(byId(w, 'study-error').exists()).toBe(false);
    expect(byId(w, 'study-days').exists()).toBe(true);
  });

  it('an expired login gets its own sentence', async () => {
    getParentStudyTasks.mockRejectedValueOnce(Object.assign(new Error('x'), { status: 401 }));
    const w = await mountIt();
    expect(byId(w, 'study-error').text()).toContain('登入已過期');
  });

  it('moves between weeks, and 本週 is disabled only on this week', async () => {
    const w = await mountIt();
    expect(button(w, '本週').attributes('disabled')).toBeDefined();
    await button(w, '下一週').trigger('click'); await flushPromises();
    expect(getParentStudyTasks).toHaveBeenLastCalledWith('tok-a', '2026-11-16');
    expect(byId(w, 'week-label').text()).toBe('11/16 – 11/22');
    expect(button(w, '本週').attributes('disabled')).toBeUndefined();
    await button(w, '上一週').trigger('click'); await flushPromises();
    await button(w, '上一週').trigger('click'); await flushPromises();
    expect(getParentStudyTasks).toHaveBeenLastCalledWith('tok-a', '2026-11-02');
    await button(w, '本週').trigger('click'); await flushPromises();
    expect(getParentStudyTasks).toHaveBeenLastCalledWith('tok-a', '2026-11-09');
  });

  it('drops an answer for an older week when the parent moved on', async () => {
    const resolvers = [];
    getParentStudyTasks.mockImplementation(() => new Promise((r) => resolvers.push(r)));
    const w = mount(ParentStudyWeek, { props: { token: 'tok-a' }, attachTo: document.body });
    wrappers.push(w);
    await flushPromises();
    await button(w, '下一週').trigger('click'); await flushPromises();
    resolvers[1]({ data: [row(9, '2026-11-17', { title: '下週的' })] });
    await flushPromises();
    resolvers[0]({ data: [row(8, '2026-11-10', { title: '舊的一週' })] });
    await flushPromises();
    expect(w.text()).toContain('下週的');
    expect(w.text()).not.toContain('舊的一週');
  });

  it('reloads for the new child when the token changes', async () => {
    const w = await mountIt();
    getParentStudyTasks.mockResolvedValue({ data: [row(7, '2026-11-10', { title: '手足的任務' })] });
    await w.setProps({ token: 'tok-b' }); await flushPromises();
    expect(getParentStudyTasks).toHaveBeenLastCalledWith('tok-b', '2026-11-09');
    expect(w.text()).toContain('手足的任務');
    expect(w.text()).not.toContain('預習第三章');
  });

  it('has no axe violations in the list, empty and error states', async () => {
    const ok = await mountIt();
    expect((await axe.run(ok.element)).violations.map((v) => v.id)).toEqual([]);
    getParentStudyTasks.mockResolvedValue({ data: [] });
    const empty = await mountIt();
    expect((await axe.run(empty.element)).violations.map((v) => v.id)).toEqual([]);
    getParentStudyTasks.mockRejectedValue(Object.assign(new Error('x'), { status: 500 }));
    const bad = await mountIt();
    expect((await axe.run(bad.element)).violations.map((v) => v.id)).toEqual([]);
  });
});
