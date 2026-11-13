import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axe from 'axe-core';

vi.mock('../../../lib/authedFetch', () => ({ authedFetch: vi.fn() }));
import { authedFetch } from '../../../lib/authedFetch';
import LessonPlanUnits from '../LessonPlanUnits.vue';

const json = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
const unit = (id, title, extra = {}) => ({ id, title, planned_start: '2026-11-02', planned_end: '2026-11-08', behind: false, checked: false, ...extra });
const PAYLOAD = {
  plan: { id: 5, title: '七年級數學' }, can_tick: true, lesson_date: '2026-11-09', total: 12,
  units: [unit(1, '整數', { behind: true }), unit(2, '分數'), unit(3, '代數', { checked: true })],
};
let calls;
let payload;
let putResponse;
let allPayload;

const mountIt = async (props = {}) => {
  const w = mount(LessonPlanUnits, { props: { classSessionId: 31, ...props }, attachTo: document.body });
  await flushPromises();
  return w;
};

beforeEach(() => {
  calls = [];
  payload = PAYLOAD;
  putResponse = () => json({ data: { unit_ids: [2, 3] } });
  allPayload = null;
  authedFetch.mockReset();
  authedFetch.mockImplementation(async (url, init = {}) => {
    const method = init.method || 'GET';
    calls.push({ method, url, body: init.body ? JSON.parse(init.body) : null });
    if (method === 'PUT') return putResponse();
    return json({ data: allPayload && url.includes('&all=1') ? allPayload : payload });
  });
});

describe('LessonPlanUnits (#4970 step 5)', () => {
  it('shows nothing when the student has no active plan', async () => {
    payload = { plan: null, can_tick: false, units: [], total: 0 };
    const w = await mountIt();
    expect(w.find('[data-testid="lesson-plan-units"]').exists()).toBe(false);
  });

  it('shows nothing when this teacher may not tick (substitute)', async () => {
    payload = { ...PAYLOAD, can_tick: false };
    expect((await mountIt()).find('[data-testid="lesson-plan-units"]').exists()).toBe(false);
  });

  it('lists units with dates, a 落後 badge, units already ticked from this lesson pre-checked, and a show-all button', async () => {
    const w = await mountIt();
    const boxes = w.findAll('input[type=checkbox]');
    expect(boxes.map((b) => b.element.checked)).toEqual([false, false, true]);
    expect(w.text()).toContain('這堂上完的單元');
    expect(w.text()).toContain('預計 11/02–11/08');
    expect(w.findAll('[data-slot=badge]').filter((s) => s.text() === '落後')).toHaveLength(1);
    expect(w.text()).toContain('顯示全部（還有 9 個單元）');
    expect(calls[0].url).toBe('/api/v1/learning-plan-units/candidates?class_session_id=31');
    await w.find('button').trigger('click');
    await flushPromises();
    expect(calls[1].url).toContain('&all=1');
  });

  it('sends exactly the ticked ids after the evaluation was saved, and nothing when unchanged', async () => {
    const w = await mountIt();
    expect(await w.vm.save(77)).toEqual({ ok: true });
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);

    await w.findAll('input[type=checkbox]')[1].setValue(true);
    expect(await w.vm.save(77)).toEqual({ ok: true });
    const put = calls.find((c) => c.method === 'PUT');
    expect(put.url).toBe('/api/v1/learning-records/77/plan-units');
    expect([...put.body.unit_ids].sort()).toEqual([2, 3]);
    expect(w.vm.dirty).toBe(false);
  });

  it('a failed save reports the reason and keeps the ticks so the teacher can retry', async () => {
    putResponse = () => json({ message: '已核准的評量不能再更動單元，請聯絡主任' }, 409);
    const w = await mountIt();
    await w.findAll('input[type=checkbox]')[0].setValue(true);
    expect(await w.vm.save(77)).toEqual({ ok: false, message: '已核准的評量不能再更動單元，請聯絡主任' });
    expect(w.findAll('input[type=checkbox]')[0].element.checked).toBe(true);
    expect(w.vm.dirty).toBe(true);
  });

  it('locks the checkboxes while the save is in flight and unlocks them after', async () => {
    let release;
    putResponse = () => new Promise((resolve) => { release = () => resolve(json({ data: { unit_ids: [2, 3] } })); });
    const w = await mountIt();
    await w.findAll('input[type=checkbox]')[1].setValue(true);
    const pending = w.vm.save(77);
    await flushPromises();
    expect(w.findAll('input[type=checkbox]').every((c) => c.element.disabled)).toBe(true);
    release();
    expect(await pending).toEqual({ ok: true });
    await flushPromises();
    expect(w.findAll('input[type=checkbox]').some((c) => c.element.disabled)).toBe(false);
    expect(w.vm.dirty).toBe(false);
  });

  it('a failed 顯示全部 keeps the list and the ticks on screen', async () => {
    const w = await mountIt();
    await w.findAll('input[type=checkbox]')[1].setValue(true);
    authedFetch.mockImplementationOnce(async () => json({ message: 'x' }, 500));
    await w.findAll('button').find((b) => b.text().includes('顯示全部')).trigger('click');
    await flushPromises();
    expect(w.findAll('input[type=checkbox]')).toHaveLength(3);
    expect(w.findAll('input[type=checkbox]')[1].element.checked).toBe(true);
    expect(w.text()).toContain('完整單元清單暫時載入失敗');
  });

  it('is axe clean', async () => {
    const w = await mountIt();
    const { violations } = await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } });
    expect(violations).toEqual([]);
  });

  it('a different lesson never inherits the old lesson state or ticks, and a late old response is dropped', async () => {
    let release;
    const w = await mountIt();
    await w.findAll('input[type=checkbox]')[0].setValue(true);
    authedFetch.mockImplementationOnce(() => new Promise((r) => { release = () => r(json({ data: { ...PAYLOAD, units: [unit(9, '新堂單元')] } })); }));
    await w.setProps({ classSessionId: 32 });
    expect(w.findAll('input[type=checkbox]')).toHaveLength(0);
    expect(w.vm.dirty).toBe(false);
    expect(await w.vm.save(77)).toEqual({ ok: true });
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    release(); await flushPromises();
    expect(w.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false]);
  });

  it('restores the draft selection as a whole: an added tick comes back and a removed one stays removed', async () => {
    const w = await mountIt({ restoreIds: [2] });
    expect(w.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false, true, false]);
    expect(w.emitted('change')).toBeTruthy();
    expect(w.vm.draftIds()).toEqual([2]);
    await w.vm.save(77);
    expect(calls.find((c) => c.method === 'PUT').body.unit_ids).toEqual([2]);
  });

  it('restores an empty draft selection (every tick removed) and leaves the server state alone when the draft holds none', async () => {
    const emptied = await mountIt({ restoreIds: [] });
    expect(emptied.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false, false, false]);
    expect(emptied.vm.draftIds()).toEqual([]);
    const none = await mountIt();
    expect(none.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false, false, true]);
    expect(none.vm.draftIds()).toBeNull();
    expect(none.emitted('change')).toBeFalsy();
  });

  it('drops a draft tick the server no longer offers, so a stale unit is never sent', async () => {
    payload = { ...PAYLOAD, total: 3 };
    const w = await mountIt({ restoreIds: [2, 3, 99] });
    expect(calls.filter((c) => c.method === 'GET')).toHaveLength(1);
    expect([...w.vm.draftIds()].sort()).toEqual([2, 3]);
    await w.vm.save(77);
    expect(calls.find((c) => c.method === 'PUT').body.unit_ids.sort()).toEqual([2, 3]);
  });

  it('looks once in the full list for a draft tick that the short list does not show', async () => {
    allPayload = { ...PAYLOAD, units: [...PAYLOAD.units, unit(8, '幾何')] };
    const w = await mountIt({ restoreIds: [3, 8, 99] });
    const gets = calls.filter((c) => c.method === 'GET');
    expect(gets).toHaveLength(2);
    expect(gets[1].url).toContain('&all=1');
    expect([...w.vm.draftIds()].sort()).toEqual([3, 8]);
    await w.vm.save(77);
    expect(calls.find((c) => c.method === 'PUT').body.unit_ids.sort()).toEqual([3, 8]);
  });

  it('keeps the draft selection untouched while the full list is still needed, and when that load fails', async () => {
    let fail = true;
    authedFetch.mockImplementation(async (url) => {
      calls.push({ method: 'GET', url, body: null });
      if (url.includes('&all=1') && fail) return json({ message: 'boom' }, 500);
      return json({ data: PAYLOAD });
    });
    const w = await mountIt({ restoreIds: [3, 8] });
    expect(calls).toHaveLength(2);
    // Not applied: the ticks show the server state, but the draft must still be offered its own selection.
    expect(w.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false, false, true]);
    expect(w.vm.draftIds()).toEqual([3, 8]);
    expect(w.emitted('change')).toBeFalsy();
    // Ticks are locked with a retry hint; retrying restores the whole draft selection, hidden unit included.
    expect(w.findAll('input[type=checkbox]').every((b) => b.element.disabled)).toBe(true);
    expect(w.text()).toContain('草稿裡的勾選還沒還原');
    expect(await w.vm.save(77)).toEqual({ ok: true });
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
    fail = false;
    authedFetch.mockImplementation(async (url) => json({ data: { ...PAYLOAD, units: [...PAYLOAD.units, unit(8, '幾何')] } }));
    await w.find('button').trigger('click');
    await flushPromises();
    expect(w.findAll('input[type=checkbox]').map((b) => [b.element.checked, b.element.disabled])).toEqual([[false, false], [false, false], [true, false], [true, false]]);
    expect([...w.vm.draftIds()].sort()).toEqual([3, 8]);
  });

  it('drops a tick whose unit is gone when the full list arrives', async () => {
    const w = await mountIt();
    await w.findAll('input[type=checkbox]')[1].setValue(true);
    expect([...w.vm.draftIds()].sort()).toEqual([2, 3]);
    allPayload = { ...PAYLOAD, units: [unit(1, '整數'), unit(3, '代數', { checked: true }), unit(8, '幾何')] };
    await w.find('button').trigger('click');
    await flushPromises();
    // Units 1, 3, 8 are shown; only the server's own tick (3) is left, so nothing is pending.
    expect(w.findAll('input[type=checkbox]').map((b) => b.element.checked)).toEqual([false, true, false]);
    expect(w.vm.draftIds()).toBeNull();
    await w.vm.save(77);
    expect(calls.filter((c) => c.method === 'PUT')).toHaveLength(0);
  });
});
