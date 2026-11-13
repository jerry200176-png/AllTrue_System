import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axe from 'axe-core';

vi.mock('../../lib/authedFetch', () => ({ authedFetch: vi.fn() }));
vi.mock('../../lib/sessionDates', () => ({ ymdInTaipei: () => '2026-11-13' }));
import { authedFetch } from '../../lib/authedFetch';
import Gantt from '../students/StudentLearningPlanGantt.vue';

const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const unit = (id, title, s, e, extra = {}) => ({ id, plan_id: 5, sort_order: id, title, planned_start: s, planned_end: e, dates_manual: false, completed_at: null, exam_sequence: null, ...extra });
const PLAN = {
  id: 5, student_class_id: 7, title: '七年級數學', status: 'active', plan_start: '2026-10-01', plan_end: '2026-12-10',
  percent_done: 25, units_behind: 1, next_exam: { sequence: 2, label: '第二次段考', start_date: '2026-12-01', days_left: 18 },
  units: [
    unit(1, '整數', '2026-10-01', '2026-10-14', { completed_at: '2026-10-12 10:00:00' }),
    unit(2, '分數', '2026-10-15', '2026-11-05'),
    unit(3, '代數', '2026-11-10', '2026-11-20', { dates_manual: true }),
    unit(4, '方程式', '2026-11-21', '2026-12-10'),
  ],
};
const COURSE = { id: 7, subject: 'Math', subject_name: '數學', teacher_name: '王老師' };
let plans, routes, calls;
const wrappers = [];
const mountGantt = (props = {}) => {
  const w = mount(Gantt, {
    props: { studentId: 1, branchId: 3, studentGrade: 'J1', ...props },
    global: { stubs: { DialogContent: { template: '<section><slot /></section>' } } },
  });
  wrappers.push(w);
  return w;
};
const button = (w, text) => w.findAll('button').find((b) => b.text() === text);
beforeEach(() => {
  plans = [PLAN]; calls = []; routes = {};
  authedFetch.mockReset();
  authedFetch.mockImplementation(async (url, init = {}) => {
    const method = init.method || 'GET';
    calls.push({ method, url, body: init.body ? JSON.parse(init.body) : null });
    const custom = Object.entries(routes).find(([k]) => `${method} ${url}`.includes(k));
    if (custom) return custom[1](url, init);
    if (url.includes('/student-classes')) return json({ data: [COURSE] });
    if (url.includes('/curriculum-templates')) return json({ data: [{ id: 9, title: '七上數學', units_count: 12 }] });
    if (method === 'GET' && url.includes('/learning-plans')) return json({ data: plans });
    return json({ data: PLAN });
  });
});
afterEach(() => { wrappers.splice(0).forEach((w) => w.unmount()); });

describe('student learning plan Gantt (#4970 step 4b)', () => {
  it('empty state -> apply a template filtered by subject and grade -> chart appears', async () => {
    plans = [];
    const w = mountGantt(); await flushPromises();
    expect(w.text()).toContain('這門課還沒有學習計畫');
    await button(w, '套用教材範本').trigger('click'); await flushPromises();
    expect(calls.find((c) => c.url.includes('/curriculum-templates')).url).toMatch(/campus_id=3.*status=active.*subject=Math.*grade=J1/);
    expect(w.text()).toContain('七上數學（12 個單元）');
    plans = [PLAN];
    await button(w, '套用').trigger('click'); await flushPromises();
    const post = calls.find((c) => c.method === 'POST');
    expect(post.url).toBe('/api/v1/students/1/learning-plans');
    expect(post.body).toEqual({ student_class_id: 7, template_id: 9, plan_start: '2026-11-13' });
    expect(w.findAll('[data-testid="gantt-bar"]')).toHaveLength(4);
    expect(w.text()).not.toContain('這門課還沒有學習計畫');
  });

  it('shows done, behind, in-progress and upcoming as text + icon, marks manual units, today and the next exam', async () => {
    const w = mountGantt(); await flushPromises();
    expect(w.findAll('[data-testid="gantt-bar"]').map((b) => b.attributes('data-state'))).toEqual(['done', 'behind', 'in_progress', 'upcoming']);
    const rows = w.findAll('[data-testid="gantt-row"]').map((r) => r.text());
    expect(rows[0]).toContain('已完成'); expect(rows[1]).toContain('落後'); expect(rows[2]).toContain('手動');
    expect(w.text()).toContain('第 2 單元 分數：10/15–11/05，落後');
    expect(w.get('[data-testid="plan-summary"]').text()).toContain('完成 25%');
    expect(w.get('[data-testid="plan-summary"]').text()).toContain('落後 1 個單元');
    expect(w.get('[data-testid="plan-summary"]').text()).toContain('距離第二次段考還有 18 天');
    expect(w.find('[data-testid="today-line"]').exists()).toBe(true);
    expect(w.findAll('[data-testid="exam-marker"]')).toHaveLength(1);
    expect(w.findAll('[data-testid="unit-item"]')).toHaveLength(4);
  });

  it('says which lesson finished a unit only when it was ticked from a lesson evaluation', async () => {
    plans = [{ ...PLAN, units: PLAN.units.map((u) => (u.id === 1 ? { ...u, completed_on: '2026-11-09', completed_session_id: 31 } : u.id === 2 ? { ...u, completed_at: '2026-11-10 08:00:00', completed_on: '2026-11-10' } : u)) }];
    const w = mountGantt(); await flushPromises();
    const items = w.findAll('[data-testid="unit-item"]').map((i) => i.text());
    expect(items[0]).toContain('於 11/09 的課完成');
    expect(items[1]).not.toContain('的課完成');
  });

  it('draws overlapping manual and automatic units on their own rows', async () => {
    plans = [{ ...PLAN, units: [unit(1, 'A', '2026-11-01', '2026-11-20'), unit(2, 'B', '2026-11-05', '2026-11-25', { dates_manual: true })] }];
    const w = mountGantt(); await flushPromises();
    expect(w.findAll('[data-testid="gantt-row"]')).toHaveLength(2);
    expect(w.findAll('[data-testid="gantt-bar"]')).toHaveLength(2);
  });

  it('ticks a unit done and reloads the plan', async () => {
    const w = mountGantt(); await flushPromises();
    await w.findAll('[data-testid="unit-item"] input[type="checkbox"]')[1].setValue(true); await flushPromises();
    const patch = calls.find((c) => c.method === 'PATCH');
    expect(patch.url).toBe('/api/v1/learning-plans/5/units/2');
    expect(patch.body).toEqual({ completed: true });
    expect(calls.filter((c) => c.method === 'GET' && c.url.includes('/learning-plans')).length).toBe(2);
  });

  it('hides write controls after the first 403 and stays readable', async () => {
    routes['PATCH '] = () => json({ message: 'Forbidden' }, 403);
    const w = mountGantt(); await flushPromises();
    await w.findAll('[data-testid="unit-item"] input[type="checkbox"]')[1].setValue(true); await flushPromises();
    expect(w.text()).toContain('你不是這門課的授課老師');
    expect(w.findAll('input[type="checkbox"]')).toHaveLength(0);
    expect(button(w, '調整計畫期間')).toBeUndefined();
    expect(w.findAll('[data-testid="gantt-bar"]')).toHaveLength(4);
  });

  it('shows the server 422 message as is when changing the plan range', async () => {
    const msg = '有 1 個手動排好日期的單元會落在新的計畫期間外，請先調整這些單元的日期';
    routes['PATCH '] = () => json({ message: msg }, 422);
    const w = mountGantt(); await flushPromises();
    await button(w, '調整計畫期間').trigger('click');
    await w.findAll('input[type="date"]')[1].setValue('2026-11-01');
    // DialogContent is stubbed, so every dialog body is mounted: the range dialog is the last one.
    await w.findAll('button').filter((b) => b.text() === '儲存').at(-1).trigger('click'); await flushPromises();
    expect(w.get('[role="alert"]').text()).toBe(msg);
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ plan_start: '2026-10-01', plan_end: '2026-11-01' });
  });

  it('edits unit dates and restores automatic scheduling', async () => {
    const w = mountGantt(); await flushPromises();
    await w.findAll('[data-testid="unit-item"] button').find((b) => b.text() === '調整日期').trigger('click');
    expect(button(w, '恢復自動排程')).toBeUndefined(); // unit 1 is automatic
    await w.findAll('[data-testid="unit-item"]')[2].findAll('button').find((b) => b.text() === '調整日期').trigger('click');
    await button(w, '恢復自動排程').trigger('click'); await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ dates_manual: false });
  });

  it('archives only after confirmation', async () => {
    const w = mountGantt(); await flushPromises();
    await button(w, '封存計畫').trigger('click'); await flushPromises();
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);
    document.querySelector('[data-testid="confirm-dialog-confirm"]').click(); await flushPromises();
    expect(calls.find((c) => c.method === 'PATCH').body).toEqual({ status: 'archived' });
  });

  it('discards a stale response when the student changes', async () => {
    let release;
    routes['GET /api/v1/students/1/learning-plans'] = () => new Promise((r) => { release = () => r(json({ data: [PLAN] })); });
    const w = mountGantt(); await flushPromises();
    routes['GET /api/v1/students/2/learning-plans'] = () => json({ data: [] });
    await w.setProps({ studentId: 2 }); await flushPromises();
    expect(w.text()).toContain('這門課還沒有學習計畫');
    release(); await flushPromises();
    expect(w.text()).toContain('這門課還沒有學習計畫');
    expect(w.findAll('[data-testid="gantt-bar"]')).toHaveLength(0);
  });

  it('has no axe violations (colour contrast is checked in the browser)', async () => {
    const w = mountGantt(); await flushPromises();
    document.body.appendChild(w.element);
    const res = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(res.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.html.slice(0, 80)).join(' | ')}`)).toEqual([]);
    w.element.remove();
  });
});
