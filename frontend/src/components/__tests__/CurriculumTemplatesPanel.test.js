import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: async () => ({ data: { session: { access_token: 't' } } }) } },
}));
import Panel from '../CurriculumTemplatesPanel.vue';

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});
const dialogBtn = () => document.querySelector('[data-testid="confirm-dialog-confirm"]');

const TEMPLATE = { id: 5, title: '七上數學範本', subject: 'Math', grade: 'J1', publisher: '測試出版社', status: 'active', units_count: 2 };
const DETAIL = {
  ...TEMPLATE,
  units: [{ id: 1, title: '第一章', note: null, exam_sequence: 1 }, { id: 2, title: '第二章', note: null, exam_sequence: null }],
};

function stub() {
  const fetchMock = vi.fn(async (input, options = {}) => {
    const url = String(input);
    const method = options.method || 'GET';
    let body = { data: [TEMPLATE] };
    if (method === 'GET' && /curriculum-templates\/5$/.test(url)) body = { data: DETAIL };
    else if (method === 'POST') body = { data: { id: 9 } };
    else if (method === 'PUT') body = { data: DETAIL };
    return { ok: true, json: async () => body };
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const btn = (w, text) => w.findAll('button').find((b) => b.text() === text);
const bodyOf = (f, method) => JSON.parse(f.mock.calls.find(([, o]) => o?.method === method)[1].body);

describe('教材範本庫 panel', () => {
  it('lists templates with unit count and filters by subject', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    expect(w.text()).toContain('七上數學範本');
    expect(w.text()).toContain('數學');
    expect(w.text()).toContain('2 個單元');
    await w.find('select[aria-label="科目"]').setValue('English');
    await flushPromises();
    expect(String(f.mock.calls.at(-1)[0])).toContain('subject=English');
    expect(String(f.mock.calls.at(-1)[0])).toContain('campus_id=1');
  });

  it('creates a template, then saves units in the order shown', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await btn(w, '新增範本').trigger('click');
    await w.find('input[placeholder^="例如"]').setValue('八上國文範本');
    await btn(w, '加單元').trigger('click');
    await btn(w, '加單元').trigger('click');
    const titles = w.findAll('input[placeholder="單元名稱"]');
    await titles[0].setValue('甲課');
    await titles[1].setValue('乙課');
    await w.find('select[aria-label="單元 2 對應段考"]').setValue(2);
    await btn(w, '儲存').trigger('click');
    await flushPromises();
    expect(bodyOf(f, 'POST')).toMatchObject({ title: '八上國文範本', campus_id: 1, subject: 'Chinese', grade: 'P1' });
    expect(bodyOf(f, 'PUT').units.map((u) => [u.title, u.exam_sequence])).toEqual([['甲課', null], ['乙課', 2]]);
    expect(w.find('.ctp-editor').exists()).toBe(false);
  });

  it('reorders units with up/down and sends the new order', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await btn(w, '編輯').trigger('click');
    await flushPromises();
    expect(w.findAll('input[placeholder="單元名稱"]').map((i) => i.element.value)).toEqual(['第一章', '第二章']);
    expect(w.find('button[aria-label="單元 1 上移"]').attributes('disabled')).toBeDefined();
    await w.find('button[aria-label="單元 1 下移"]').trigger('click');
    expect(w.findAll('input[placeholder="單元名稱"]').map((i) => i.element.value)).toEqual(['第二章', '第一章']);
    await btn(w, '儲存').trigger('click');
    await flushPromises();
    expect(bodyOf(f, 'PUT').units.map((u) => u.title)).toEqual(['第二章', '第一章']);
    expect(f.mock.calls.some(([, o]) => o?.method === 'PATCH')).toBe(true);
  });

  it('blocks save with a message when a name is empty', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await btn(w, '新增範本').trigger('click');
    await btn(w, '儲存').trigger('click');
    expect(w.text()).toContain('請填範本名稱');
    await w.find('input[placeholder^="例如"]').setValue('某範本');
    await btn(w, '加單元').trigger('click');
    await btn(w, '儲存').trigger('click');
    expect(w.text()).toContain('單元名稱不能空白');
    expect(f.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
  });

  it('teacher can view but not edit or archive', async () => {
    stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: false } });
    await flushPromises();
    expect(btn(w, '新增範本')).toBeUndefined();
    expect(btn(w, '封存')).toBeUndefined();
    await btn(w, '查看').trigger('click');
    await flushPromises();
    expect(w.find('input[placeholder="單元名稱"]').attributes('disabled')).toBeDefined();
    expect(btn(w, '儲存')).toBeUndefined();
  });

  it('shows an error and the empty state when loading fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({ message: '伺服器忙碌' }) })));
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    expect(w.find('[role="alert"]').text()).toContain('伺服器忙碌');
  });

  it('archives only after the confirm dialog', async () => {
    const f = stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true }, attachTo: document.body });
    await flushPromises();
    await btn(w, '封存').trigger('click');
    await flushPromises();
    expect(f.mock.calls.some(([, o]) => o?.method === 'POST')).toBe(false);
    dialogBtn().click();
    await flushPromises();
    expect(f.mock.calls.some(([u, o]) => o?.method === 'POST' && String(u).endsWith('/curriculum-templates/5/archive'))).toBe(true);
    w.unmount();
  });

  it('removes a unit only after confirm', async () => {
    stub();
    const w = mount(Panel, { props: { branchId: 1, isDirector: true }, attachTo: document.body });
    await flushPromises();
    await btn(w, '編輯').trigger('click');
    await flushPromises();
    await w.find('button[aria-label="移除單元 1"]').trigger('click');
    await flushPromises();
    expect(w.findAll('input[placeholder="單元名稱"]')).toHaveLength(2);
    dialogBtn().click();
    await flushPromises();
    expect(w.findAll('input[placeholder="單元名稱"]')).toHaveLength(1);
    w.unmount();
  });

  it('keeps the new template id when the units PUT fails, so a retry does not create a second one', async () => {
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (input, options = {}) => {
      const method = options.method || 'GET';
      calls.push(method);
      if (method === 'PUT' && calls.filter((m) => m === 'PUT').length === 1) {
        return { ok: false, status: 422, json: async () => ({ message: '單元有誤' }) };
      }
      return { ok: true, json: async () => (method === 'POST' ? { data: { id: 9 } } : { data: [] }) };
    }));
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    await btn(w, '新增範本').trigger('click');
    await w.find('input[placeholder^="例如"]').setValue('某範本');
    await btn(w, '儲存').trigger('click');
    await flushPromises();
    expect(w.text()).toContain('單元有誤');
    await btn(w, '儲存').trigger('click');
    await flushPromises();
    expect(calls.filter((m) => m === 'POST')).toHaveLength(1);
    expect(calls.filter((m) => m === 'PATCH')).toHaveLength(1);
    expect(w.find('.ctp-editor').exists()).toBe(false);
  });

  it('shows a plain message on 403', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) })));
    const w = mount(Panel, { props: { branchId: 1, isDirector: true } });
    await flushPromises();
    expect(w.find('[role="alert"]').text()).toContain('沒有');
    expect(w.text()).not.toContain('Forbidden');
  });
});
