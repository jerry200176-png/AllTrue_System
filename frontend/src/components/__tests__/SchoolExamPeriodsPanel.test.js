import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) } },
}));
// Reference dates for the term the panel opens on (whatever today is), for a school the other tests do not use.
vi.mock('../../lib/schoolExamReferenceData.generated.js', async () => {
  const { currentTerm: term } = await import('../../lib/schoolExamTerm');
  return { default: { ...term(), source: '測試來源', rows: [
    ['臺北市', '乙參考國中', '國中', '第一次期中', 1, '2026-10-13', '2026-10-14'],
    ['臺北市', '乙參考國中', '七、八年級', '期末', 3, '2027-01-18', '2027-01-19'],
    ['臺北市', '乙參考國中', '九年級', '期末', 3, '2027-01-05', '2027-01-06'],
  ] } };
});
import Panel from '../SchoolExamPeriodsPanel.vue';
import { currentTerm } from '../../lib/schoolExamTerm';

afterEach(() => vi.unstubAllGlobals());

function stub() {
  const fetchMock = vi.fn(async (input, options = {}) => {
    const url = String(input);
    const body = url.includes('/schools')
      ? { data: [{ key: '甲測試國中', school_name: '甲測試國中', student_count: 3, variants: ['甲測試國中', '甲測試國民中學'] }] }
      : options.method === 'POST' ? { data: { id: 9 } } : { data: [] };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('各校段考日期 panel', () => {
  it('maps dates to ROC term', () => {
    expect(currentTerm(new Date(2026, 9, 1))).toEqual({ year: 115, semester: 1 });
    expect(currentTerm(new Date(2027, 2, 1))).toEqual({ year: 115, semester: 2 });
    expect(currentTerm(new Date(2027, 0, 5))).toEqual({ year: 115, semester: 1 });
  });

  it('director sees counts, variant hint and POSTs a new range', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    expect(w.text()).toContain('甲測試國中');
    expect(w.text()).toContain('3 人');
    expect(w.text()).toContain('可能同校');
    const inputs = w.findAll('input[type="date"]');
    await inputs[0].setValue('2026-10-20');
    await inputs[1].setValue('2026-10-22');
    await w.findAll('button').find((b) => b.text() === '儲存').trigger('click');
    await flushPromises();
    const post = f.mock.calls.find(([, o]) => o?.method === 'POST');
    expect(JSON.parse(post[1].body)).toMatchObject({ seq: 1, school_name: '甲測試國中', start_date: '2026-10-20', end_date: '2026-10-22' });
  });

  it('offers reference dates: a chip fills the draft, and the fill button saves only blank slots with one choice', async () => {
    const f = vi.fn(async (input, options = {}) => {
      const body = String(input).includes('/schools')
        ? { data: [{ key: '乙參考國中', school_name: '臺北市立乙參考國民中學', student_count: 2, variants: ['乙參考國中'] }] }
        : options.method === 'POST' ? { data: { id: 11 } } : { data: [] };
      return { ok: true, json: async () => body };
    });
    vi.stubGlobal('fetch', f);
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await flushPromises();
    expect(w.text()).toContain('測試來源');
    const chips = w.findAll('button').filter((b) => /\d+\/\d+–\d+\/\d+/.test(b.text()));
    expect(chips.map((b) => b.text().replace(/\s+/g, ' '))).toEqual(['國中 10/13–10/14', '七、八年級 1/18–1/19', '九年級 1/5–1/6']);
    // 期末 has two choices: a chip only fills the two date boxes, nothing is sent.
    await chips[2].trigger('click');
    const dates = w.findAll('input[type="date"]').map((i) => i.element.value);
    expect(dates.slice(4)).toEqual(['2027-01-05', '2027-01-06']);
    expect(f.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
    // The fill button takes the one blank slot that has a single choice (段考一), and leaves 期末 to the director.
    const fill = w.findAll('button').find((b) => b.text().includes('帶入參考日期'));
    expect(fill.text()).toContain('1 格空白');
    await fill.trigger('click');
    await flushPromises();
    const posts = f.mock.calls.filter(([, o]) => o?.method === 'POST').map(([, o]) => JSON.parse(o.body));
    expect(posts).toHaveLength(1);
    expect(posts[0]).toMatchObject({ seq: 1, school_name: '臺北市立乙參考國民中學', start_date: '2026-10-13', end_date: '2026-10-14' });
    expect(w.text()).toContain('已帶入 1 格');
    expect(w.findAll('button').some((b) => b.text().includes('帶入參考日期'))).toBe(false);
  });

  it('shows no reference controls to a teacher or for a school without reference dates', async () => {
    stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await flushPromises();
    expect(w.text()).not.toContain('測試來源');
    expect(w.findAll('button').some((b) => b.text().includes('帶入參考日期'))).toBe(false);
  });

  it('teacher gets read-only inputs and no save button', async () => {
    stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: false } });
    await flushPromises();
    expect(w.find('input[type="date"]').attributes('disabled')).toBeDefined();
    expect(w.findAll('button').some((b) => b.text() === '儲存')).toBe(false);
  });
});

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

const response = (data, ok = true) => ({ ok, json: async () => data });

function controlledApi() {
  const school = { key: '合成國中', school_name: '合成國中', student_count: 3, variants: ['合成國中'] };
  let period = { id: 9, school_key: school.key, seq: 1, start_date: '2026-10-01', end_date: '2026-10-02' };
  const pending = [];
  const fetchMock = vi.fn(async (input, options = {}) => {
    if (!options.method) return response({ data: String(input).includes('/schools') ? [school] : [{ ...period }] });
    return pending.shift()?.promise ?? response({ data: { id: 25 } });
  });
  vi.stubGlobal('fetch', fetchMock);
  return {
    next: () => { const gate = deferred(); pending.push(gate); return gate; },
    select: () => { period = { ...period, id: 41, start_date: '2026-11-10', end_date: '2026-11-11' }; },
    mutations: () => fetchMock.mock.calls.filter(([, options]) => options?.method),
  };
}

const rowButton = (row, label) => row.findAll('button').find((button) => button.text() === label);
async function editRange(row) {
  const inputs = row.findAll('input[type="date"]');
  await inputs[0].setValue('2026-12-01');
  await inputs[1].setValue('2026-12-02');
}

async function switchSelection(wrapper, api, selection) {
  api.select();
  if (selection === 'campus') await wrapper.setProps({ branchId: 2 });
  else {
    const select = wrapper.find('#sep-term');
    const other = select.findAll('option').find((option) => option.attributes('value') !== select.element.value);
    await select.setValue(other.attributes('value'));
  }
  await flushPromises();
}

describe('段考日期 request ownership (#4983)', () => {
  it('keeps save and clear mutually exclusive before the next render and can create again after clearing', async () => {
    const api = controlledApi();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    const row = w.findAll('.sep-row')[0];
    await editRange(row);
    const gate = api.next();
    rowButton(row, '儲存').element.click();
    rowButton(row, '清除').element.click();
    rowButton(row, '儲存').element.click();
    await flushPromises();
    expect(api.mutations().map(([, options]) => options.method)).toEqual(['PATCH']);
    expect(row.findAll('button').every((button) => button.element.disabled)).toBe(true);
    expect(row.findAll('input').every((input) => input.element.disabled)).toBe(true);
    expect(w.find('#sep-term').element.disabled).toBe(false);
    expect(w.findAll('.sep-row')[1].find('input').element.disabled).toBe(false);
    gate.resolve(response({ data: { id: 9 } }));
    await flushPromises();
    await rowButton(row, '清除').trigger('click');
    await flushPromises();
    expect(row.findAll('input').map((input) => input.element.value)).toEqual(['', '']);
    await editRange(row);
    await rowButton(row, '儲存').trigger('click');
    await flushPromises();
    expect(api.mutations().map(([url, options]) => [url, options.method])).toEqual([
      ['/api/v1/exam-periods/9', 'PATCH'], ['/api/v1/exam-periods/9', 'DELETE'], ['/api/v1/exam-periods', 'POST'],
    ]);
    w.unmount();
  });

  it('keeps a pending clear exclusive to its slot while another slot can save', async () => {
    const api = controlledApi();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    const [first, second] = w.findAll('.sep-row');
    await editRange(first);
    const gate = api.next();
    rowButton(first, '清除').element.click();
    rowButton(first, '儲存').element.click();
    rowButton(first, '清除').element.click();
    await flushPromises();
    expect(api.mutations().map(([, options]) => options.method)).toEqual(['DELETE']);
    expect(first.findAll('input').every((input) => input.element.disabled)).toBe(true);
    await editRange(second);
    await rowButton(second, '儲存').trigger('click');
    await flushPromises();
    expect(api.mutations().map(([, options]) => options.method)).toEqual(['DELETE', 'POST']);
    expect(rowButton(second, '清除')).toBeDefined();
    gate.resolve(response({}));
    await flushPromises();
    expect(first.findAll('input').map((input) => input.element.value)).toEqual(['', '']);
    expect(rowButton(second, '清除')).toBeDefined();
    w.unmount();
  });

  it.each(['campus', 'term'].flatMap((selection) => ['save', 'clear'].flatMap((action) => ['success', 'failure'].map((outcome) => [selection, action, outcome]))))(
    'ignores old %s selection %s %s after the new selection loads', async (selection, action, outcome) => {
      const api = controlledApi();
      const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
      await flushPromises();
      const row = w.findAll('.sep-row')[0];
      if (action === 'save') await editRange(row);
      const gate = api.next();
      await rowButton(row, action === 'save' ? '儲存' : '清除').trigger('click');
      await flushPromises();
      await switchSelection(w, api, selection);
      const current = w.findAll('.sep-row')[0];
      expect(rowButton(current, '儲存').element.disabled).toBe(true);
      gate.resolve(outcome === 'success' ? response({ data: { id: 9 } }) : response({ message: '合成舊選取請求失敗' }, false));
      await flushPromises();
      expect(current.findAll('input').map((input) => input.element.value)).toEqual(['2026-11-10', '2026-11-11']);
      expect(rowButton(current, '儲存').element.disabled).toBe(true);
      expect(w.find('[role="alert"]').exists()).toBe(false);
      await rowButton(current, '清除').trigger('click');
      await flushPromises();
      expect(api.mutations().at(-1)[0]).toBe('/api/v1/exam-periods/41');
      w.unmount();
    },
  );

  it('does not let the previous campus completion unlock a newer request for the same school and slot', async () => {
    const api = controlledApi();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    let row = w.findAll('.sep-row')[0];
    await editRange(row);
    const old = api.next();
    await rowButton(row, '儲存').trigger('click');
    await flushPromises();
    await switchSelection(w, api, 'campus');
    row = w.findAll('.sep-row')[0];
    await editRange(row);
    const current = api.next();
    await rowButton(row, '儲存').trigger('click');
    await flushPromises();
    old.resolve(response({ data: { id: 9 } }));
    await flushPromises();
    expect(row.findAll('button').every((button) => button.element.disabled)).toBe(true);
    rowButton(row, '儲存').element.click();
    await flushPromises();
    expect(api.mutations().map(([url]) => url)).toEqual(['/api/v1/exam-periods/9', '/api/v1/exam-periods/41']);
    current.resolve(response({ data: { id: 41 } }));
    await flushPromises();
    expect(rowButton(row, '清除').element.disabled).toBe(false);
    expect(rowButton(row, '儲存').element.disabled).toBe(true);
    w.unmount();
  });

  it.each(['campus', 'term'])('keeps the slot locked when the user returns to a pending %s selection', async (selection) => {
    const api = controlledApi();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    const originalTerm = w.find('#sep-term').element.value;
    const row = w.findAll('.sep-row')[0];
    await editRange(row);
    const gate = api.next();
    await rowButton(row, '儲存').trigger('click');
    await flushPromises();
    await switchSelection(w, api, selection);
    if (selection === 'campus') await w.setProps({ branchId: 1 });
    else await w.find('#sep-term').setValue(originalTerm);
    await flushPromises();
    const current = w.findAll('.sep-row')[0];
    expect(current.findAll('button').every((button) => button.element.disabled)).toBe(true);
    expect(current.findAll('input').every((input) => input.element.disabled)).toBe(true);
    gate.resolve(response({ data: { id: 9 } }));
    await flushPromises();
    expect(current.findAll('input').map((input) => input.element.value)).toEqual(['2026-11-10', '2026-11-11']);
    expect(rowButton(current, '清除').element.disabled).toBe(false);
    expect(rowButton(current, '儲存').element.disabled).toBe(true);
    w.unmount();
  });

  it.each(['save', 'clear'])('shows a current %s failure, retains the draft and allows retry', async (action) => {
    const api = controlledApi();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    const row = w.findAll('.sep-row')[0];
    await editRange(row);
    const gate = api.next();
    await rowButton(row, action === 'save' ? '儲存' : '清除').trigger('click');
    await flushPromises();
    gate.resolve(response({ message: '合成伺服器拒絕' }, false));
    await flushPromises();
    expect(w.get('[role="alert"]').text()).toBe('合成伺服器拒絕');
    expect(row.findAll('input').map((input) => input.element.value)).toEqual(['2026-12-01', '2026-12-02']);
    expect(row.findAll('button').some((button) => button.element.disabled)).toBe(false);
    await rowButton(row, action === 'save' ? '儲存' : '清除').trigger('click');
    await flushPromises();
    expect(api.mutations()).toHaveLength(2);
    expect(w.find('[role="alert"]').exists()).toBe(false);
    w.unmount();
  });
});
