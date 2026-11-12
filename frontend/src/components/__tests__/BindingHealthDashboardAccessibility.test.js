import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import BindingHealthDashboard from '../../pages/BindingHealthDashboard.vue';
import { Sparkline } from '../ui/sparkline';

// #4989: 綁定健康度 on components/ui, mounted with fetch mocked (fake data only). The page is read-only.
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
});

// Shape of the metrics endpoint read by the page.
const metrics = () => ({
  overall: { bound_count: 82, student_total: 100 },
  weekly_summary: { bound: 7, unbound: 2, net: 5 },
  by_campus: [
    { campus_id: 1, campus_name: '測試分校甲', rate: 0.86, bound_count: 43, student_total: 50 },
    { campus_id: 2, campus_name: '', rate: null, bound_count: 0, student_total: 0 },
    { campus_id: 3, campus_name: '測試分校丙', rate: 1.4, bound_count: 7, student_total: 5 },
  ],
  trend: {
    day: [{ date: '2026-09-01', bound: 72 }, { date: '2026-09-02', bound: 75 }, { date: '2026-09-03', bound: 82 }],
    week: [{ week: '2026-W35', bound: 70 }, { week: '2026-W36', bound: 76 }],
  },
  unbound_active: [
    { student_id: 11, student_name: '測試學生甲', campus_id: 1, campus_name: '測試分校甲' },
    { student_id: 12, student_name: '測試學生乙', campus_id: 2, campus_name: '' },
  ],
  anomalies: [
    { id: 101, type: '重複綁定', severity: 'warning', campus_id: 2, campus_name: '測試分校乙', student_name: '測試學生丙', occurred_at: '2026-09-09T09:30:00' },
    { id: 102, reason_code: 'token_expired', severity: 'danger', campus_id: 3, campus_name: '', student_name: '', occurred_at: '' },
    { id: 103, campus_id: 1, campus_name: '測試分校甲', occurred_at: 'not-a-date' },
  ],
});
const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const tick = async () => { await flushPromises(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); await flushPromises(); };
const squash = (s) => s.replace(/\s+/g, ' ').trim();
// The text of an element, one space between its text nodes (the compiler drops whitespace between tags).
const words = (w) => {
  const walker = document.createTreeWalker(w.element, NodeFilter.SHOW_TEXT);
  const out = [];
  while (walker.nextNode()) { const t = squash(walker.currentNode.textContent); if (t) out.push(t); }
  return out.join(' ');
};
const source = readFileSync(resolve(__dirname, '../../pages/BindingHealthDashboard.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
let fetchMock;
let body;
const calls = () => fetchMock.mock.calls.map(([url, init]) => [init?.method || 'GET', url]);
const mountPage = async (props = {}) => {
  wrapper = mount(BindingHealthDashboard, { props: { userRole: 'director', ...props }, attachTo: document.body });
  await tick();
  return wrapper;
};
const noAxeViolations = async (el) => expect(await axe.run(el, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);
const button = (w, name) => w.findAll('button').find((b) => (b.attributes('aria-label') || b.text()) === name);

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'unit-test-token' }));
  body = metrics();
  fetchMock = vi.fn(async () => json(body));
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); localStorage.clear(); sessionStorage.clear(); window.location.hash = ''; });

describe('BindingHealthDashboard on the template', () => {
  it('reads the metrics of the shell branch once; the title is the only h2 and no main is added', async () => {
    const w = await mountPage({ branchId: 1 });
    expect(calls()).toEqual([['GET', '/api/v1/bindings/metrics?campus_id=1']]);
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer unit-test-token');
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['綁定健康度']);
    expect(w.find('h1').exists()).toBe(false);
    expect(w.text()).toContain('全校與各分校 LINE 綁定概況、趨勢與異常事件。');
    expect(squash(w.find('[data-testid="binding-health-updated"]').text())).toMatch(/^更新於 \d{2}\/\d{2} \d{2}:\d{2}$/);
    // The page never had a main landmark; it must not add one inside a shell that may own it.
    expect(w.element.tagName).toBe('DIV');
    expect(w.findAll('main, [role="main"]')).toHaveLength(0);
    expect(w.findAll('h3').map((h) => h.text())).toEqual(['全校綁定率', '各分校綁定率', '綁定趨勢', '未綁定活躍學生（近 30 天有課）', '異常事件（近 7 天）']);
    // Every section is named by its own heading.
    for (const section of w.findAll('section[aria-labelledby]')) {
      expect(w.find(`#${section.attributes('aria-labelledby')}`).exists()).toBe(true);
    }
  });

  it('keeps the scoping: no branch reads all campuses, a branch change and 重新整理 re-read, and nothing is ever written', async () => {
    const w = await mountPage();
    expect(calls()).toEqual([['GET', '/api/v1/bindings/metrics']]);
    await w.setProps({ branchId: 2 });
    await tick();
    await button(w, '重新整理').trigger('click');
    await tick();
    await w.setProps({ branchId: '' });
    await tick();
    expect(calls()).toEqual([
      ['GET', '/api/v1/bindings/metrics'],
      ['GET', '/api/v1/bindings/metrics?campus_id=2'],
      ['GET', '/api/v1/bindings/metrics?campus_id=2'],
      ['GET', '/api/v1/bindings/metrics'],
    ]);
    // Toggling the chart and opening a student never call the API.
    await button(w, '週').trigger('click');
    await button(w, '查看：測試學生甲').trigger('click');
    await tick();
    expect(calls()).toHaveLength(4);
  });

  it('shows the four weekly numbers with their captions, and dashes when a number is missing', async () => {
    const w = await mountPage();
    const cards = () => w.findAll('.bhd-stats .bhd-metric').map(words);
    expect(w.find('.bhd-stats').attributes('aria-label')).toBe('本週綁定摘要');
    expect(cards()).toEqual(['本週新增綁定 7 週綁定數', '本週解除綁定 2 週解除數', '本週淨增 5', '全校綁定率 82.0% 已綁定/活躍學生']);
    body = { ...metrics(), overall: { bound_count: 3, student_total: 0 }, weekly_summary: { bound: 0 } };
    await button(w, '重新整理').trigger('click');
    await tick();
    expect(cards()).toEqual(['本週新增綁定 0 週綁定數', '本週解除綁定 — 週解除數', '本週淨增 —', '全校綁定率 — 已綁定/活躍學生']);
  });

  it('names the ring and every campus bar; a missing or out-of-range rate stays inside 0–100', async () => {
    const w = await mountPage();
    const ring = w.find('.bhd-ring-wrap svg[role="img"]');
    expect(ring.attributes('aria-label')).toBe('全校綁定率 82.0%');
    expect(words(w.find('.bhd-ring-wrap'))).toBe('82.0% 已綁定 82');
    const rows = w.findAll('.bhd-bars > li');
    expect(rows.map(words)).toEqual(['測試分校甲 86%（43/50）', '#2 —（0/0）', '測試分校丙 140%（7/5）']);
    const bars = w.findAll('.bhd-bars [role="progressbar"]');
    expect(bars.map((b) => [b.attributes('aria-label'), b.attributes('aria-valuenow'), b.attributes('aria-valuemax')])).toEqual([
      ['測試分校甲綁定率 86%', '86', '100'],
      ['#2綁定率 —', '0', '100'],
      ['測試分校丙綁定率 140%', '100', '100'],
    ]);
  });

  it('the trend switches between 日 and 週 with pressed buttons, and the chart keeps its name', async () => {
    const w = await mountPage();
    const group = w.find('[role="group"][aria-label="趨勢單位"]');
    const pressed = () => group.findAll('button').map((b) => [b.text(), b.attributes('aria-pressed')]);
    expect(pressed()).toEqual([['日', 'true'], ['週', 'false']]);
    const chart = () => w.findComponent(Sparkline);
    expect(chart().props('values')).toEqual([72, 75, 82]);
    expect(chart().props('label')).toBe('綁定趨勢折線圖');
    expect(w.find('svg.bhd-line').attributes('role')).toBe('img');
    await button(w, '週').trigger('click');
    expect(pressed()).toEqual([['日', 'false'], ['週', 'true']]);
    expect(chart().props('values')).toEqual([70, 76]);

    // `trend.points` wins over the per-unit lists (as before); one point is shown as text, none as the empty state.
    body = { ...metrics(), trend: { points: [{ period: '2026-09', bound: 9 }] } };
    await button(w, '重新整理').trigger('click');
    await tick();
    expect(w.find('svg.bhd-line').exists()).toBe(false);
    expect(words(w.find('[data-testid="binding-health-trend-single"]'))).toBe('2026-09 9');
    body = { ...metrics(), trend: {} };
    await button(w, '重新整理').trigger('click');
    await tick();
    expect(w.find('[data-testid="binding-health-trend-single"]').exists()).toBe(false);
    expect(w.text()).toContain('目前沒有趨勢資料');
    expect(w.text()).toContain('選擇其他期間或稍後重新整理，查看最新綁定變化。');
  });

  it('every 查看 has its own name and opens the student list focused on that student (no request)', async () => {
    const w = await mountPage();
    const rows = w.findAll('.bhd-list > li');
    expect(rows.map(words)).toEqual(['測試學生甲 測試分校甲 查看', '測試學生乙 #2 查看']);
    expect(rows.map((li) => li.find('button').attributes('aria-label'))).toEqual(['查看：測試學生甲', '查看：測試學生乙']);
    await rows[1].find('button').trigger('click');
    expect(window.location.hash).toBe('#/students');
    expect(JSON.parse(sessionStorage.getItem('alltrue_binding_student_focus'))).toEqual({ studentId: 12, studentName: '測試學生乙' });
    expect(calls()).toHaveLength(1);
  });

  it('lists anomalies with the same fallbacks: type, then reason code, then 異常; campus id and a dash for no time', async () => {
    const w = await mountPage();
    const items = w.findAll('.bhd-timeline > li').map(words);
    expect(items).toEqual(['重複綁定 09/09 09:30 測試分校乙 · 測試學生丙', 'token_expired — #3', '異常 not-a-date 測試分校甲']);
    // The severity dot is decoration only.
    expect(w.findAll('.bhd-timeline .bhd-tl-dot').every((d) => d.attributes('aria-hidden') === 'true')).toBe(true);

    body = { ...metrics(), unbound_active: [], anomalies: [] };
    await button(w, '重新整理').trigger('click');
    await tick();
    expect(w.find('.bhd-list').exists()).toBe(false);
    expect(w.find('.bhd-timeline').exists()).toBe(false);
    expect(w.text()).toContain('沒有未綁定的活躍學生');
    expect(w.text()).toContain('近 7 天無異常事件');
  });

  it('loading is announced; a failed load is an alert with the server reason as text and 重試 re-reads', async () => {
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = () => r(json(metrics())); }));
    const w = await mountPage({ branchId: 1 });
    const loading = w.find('[data-testid="binding-health-loading"]');
    expect(loading.attributes('role')).toBe('status');
    expect(loading.text()).toBe('載入中');
    expect(w.find('[data-testid="binding-health-content"]').attributes('aria-busy')).toBe('true');
    expect(w.find('.bhd-stats').exists()).toBe(false);
    release();
    await tick();
    expect(w.find('[data-testid="binding-health-loading"]').exists()).toBe(false);
    expect(w.find('[data-testid="binding-health-content"]').attributes('aria-busy')).toBe('false');

    fetchMock.mockImplementationOnce(async () => json({ message: '<b>測試：暫時無法載入</b>' }, 503));
    await button(w, '重新整理').trigger('click');
    await tick();
    const alert = w.find('[role="alert"]');
    expect(alert.text()).toContain('無法載入綁定健康度');
    // The reason is text, never markup.
    expect(alert.text()).toContain('<b>測試：暫時無法載入</b>');
    expect(alert.find('b').exists()).toBe(false);
    expect(w.find('.bhd-stats').exists()).toBe(false);
    await noAxeViolations(w.element);

    for (const status of [404, 500]) {
      fetchMock.mockImplementationOnce(async () => json({ message: 'x' }, status));
      await button(w, '重試').trigger('click');
      await tick();
      expect(w.find('[role="alert"]').text()).toContain(`後端健康度 API 尚未上線（HTTP ${status}）。此頁依賴 W31 P3-1 端點，合併後即可使用。`);
    }
    fetchMock.mockImplementationOnce(async () => { throw new Error(''); });
    await button(w, '重試').trigger('click');
    await tick();
    expect(w.find('[role="alert"]').text()).toContain('無法載入健康度資料');

    const before = calls().length;
    button(w, '重試').element.focus();
    await button(w, '重試').trigger('click');
    // 重試 leaves the screen with the alert; focus goes to 重新整理 instead of being dropped.
    expect(document.activeElement).toBe(button(w, '重新整理').element);
    await tick();
    expect(document.activeElement).toBe(button(w, '重新整理').element);
    expect(calls().slice(before)).toEqual([['GET', '/api/v1/bindings/metrics?campus_id=1']]);
    expect(w.find('[role="alert"]').exists()).toBe(false);
    expect(w.find('.bhd-stats').exists()).toBe(true);
  });

  it('an empty answer shows the empty state with the same two sentences', async () => {
    body = {};
    const w = await mountPage();
    const empty = w.find('[data-testid="binding-health-empty"]');
    expect(empty.find('h3').text()).toBe('目前沒有可顯示的綁定健康度資料');
    expect(empty.text()).toContain('資料準備好後會顯示在這裡。請使用右上角重新整理。');
    expect(w.find('.bhd-stats').exists()).toBe(false);
    await noAxeViolations(w.element);
  });

  it('has no axe violations with data, and every button has its own accessible name', async () => {
    const w = await mountPage();
    await noAxeViolations(w.element);
    const names = w.findAll('button').map((b) => b.attributes('aria-label') || b.text());
    expect(names).toEqual(['重新整理', '日', '週', '查看：測試學生甲', '查看：測試學生乙']);
    expect(new Set(names).size).toBe(names.length);
  });

  it('has no legacy parts, scoped style or inline style left and is off the legacy list', () => {
    expect(source).not.toMatch(/design-system\/At|<style|material-symbols|style="|v-html/);
    expect(legacyList).not.toContain('src/pages/BindingHealthDashboard.vue');
  });
});
