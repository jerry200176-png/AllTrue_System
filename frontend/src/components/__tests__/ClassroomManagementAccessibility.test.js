import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import ClassroomManagement from '../../pages/ClassroomManagement.vue';
import { resolvePageGuideSteps } from '../../lib/pageGuideConfig';

// #4989: 教室管理 on components/ui + app-overlay, mounted with fetch and the session mocked.
vi.mock('../../supabase', () => ({
  supabase: { auth: { getSession: vi.fn(async () => ({ data: { session: { access_token: 'unit-test-token' } } })) } },
}));

beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

const rooms = () => [
  { id: 101, name: '201 教室', capacity: 12, memo: '靠窗', is_active: true },
  { id: 102, name: '多功能教室', capacity: 24, memo: '', is_active: false },
];
const json = (body, ok = true) => ({ ok, json: async () => body });
const tick = async () => { await flushPromises(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); await flushPromises(); };
const q = (s) => document.body.querySelector(s);
const source = readFileSync(resolve(__dirname, '../../pages/ClassroomManagement.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
let fetchMock;
let alertSpy;
const writes = () => fetchMock.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET');
const mountPage = async (props = {}) => {
  wrapper = mount(ClassroomManagement, { props: { branchId: 1, ...props }, attachTo: document.body });
  await tick();
  return wrapper;
};
const setValue = async (selector, value) => {
  const el = q(selector);
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
  await tick();
};
const submitForm = async () => {
  q('[role=dialog] form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
  await tick();
};
const openAdd = async (w) => { await w.find('[data-guide="classroom-header"] button').trigger('click'); await tick(); };

beforeEach(() => {
  fetchMock = vi.fn(async (url, init = {}) => ((init.method || 'GET') === 'GET' ? json(rooms()) : json({ ok: true })));
  vi.stubGlobal('fetch', fetchMock);
  alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
});
afterEach(() => { wrapper?.unmount(); wrapper = null; vi.unstubAllGlobals(); alertSpy.mockRestore(); });

describe('ClassroomManagement on the template', () => {
  it('loads the rooms of the branch with the session token; the title is the only h2 and no main is added', async () => {
    const w = await mountPage();
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/v1/rooms?branch_id=1');
    expect(init.credentials).toBe('include');
    expect(init.headers.Authorization).toBe('Bearer unit-test-token');
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['教室管理']);
    expect(w.find('h1').exists()).toBe(false);
    expect(w.text()).toContain('管理目前分校的教室，供排課時選擇地點。');
    // The page never had a main landmark; it must not add a second one inside a shell that may own it.
    expect(w.element.tagName).toBe('DIV');
    expect(w.findAll('main, [role="main"]')).toHaveLength(0);
  });

  it('every page-guide step of 教室管理 finds exactly one element', async () => {
    const w = await mountPage();
    const targets = resolvePageGuideSteps('classroom').map((step) => step.target);
    expect(targets).toEqual(['[data-guide="classroom-header"]', '[data-guide="classroom-table"]']);
    for (const target of targets) expect(w.findAll(target)).toHaveLength(1);
    expect(w.find('[data-guide="classroom-header"] h2').exists()).toBe(true);
    expect(w.find('[data-guide="classroom-table"] table').exists()).toBe(true);
  });

  it('keeps table semantics, the phone list, the status labels and the named row actions', async () => {
    const w = await mountPage();
    expect(w.find('caption').text()).toBe('目前分校教室清單');
    expect(w.findAll('thead th').map((th) => [th.text(), th.attributes('scope')])).toEqual([
      ['教室名稱', 'col'], ['容量', 'col'], ['備註', 'col'], ['狀態', 'col'], ['操作', 'col'],
    ]);
    const tableRows = w.findAll('tbody tr');
    expect(tableRows.map((tr) => tr.findAll('td').slice(0, 4).map((td) => td.text()))).toEqual([
      ['201 教室', '12', '靠窗', '啟用'], ['多功能教室', '24', '—', '停用'],
    ]);
    expect(tableRows.map((tr) => tr.findAll('button').map((b) => [b.text(), b.attributes('aria-label')]))).toEqual([
      [['編輯', '編輯教室：201 教室'], ['停用', '停用教室：201 教室'], ['刪除', '刪除教室：201 教室']],
      [['編輯', '編輯教室：多功能教室'], ['啟用', '啟用教室：多功能教室'], ['刪除', '刪除教室：多功能教室']],
    ]);
    expect(w.find('.room-mobile-list').attributes('aria-label')).toBe('教室清單');
    const cards = w.findAll('.room-mobile-card');
    expect(cards).toHaveLength(2);
    expect(cards[0].findAll('dd').map((d) => d.text())).toEqual(['12 人', '靠窗']);
    expect(cards[1].findAll('button').map((b) => b.attributes('aria-label'))).toEqual(['編輯教室：多功能教室', '啟用教室：多功能教室', '刪除教室：多功能教室']);
    expect(await axe.run(w.element, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);
  });

  it('announces loading, shows the empty state with its action, and the error alert refetches on 重試', async () => {
    // The page loads twice on mount (branch watcher + onMounted, as before); both answers are held back.
    const held = [];
    fetchMock.mockImplementation(() => new Promise((r) => { held.push(r); }));
    const w = await mountPage();
    const loading = w.find('[data-testid="classroom-loading"]');
    expect([loading.attributes('role'), loading.attributes('aria-live'), loading.text()]).toEqual(['status', 'polite', '載入中']);
    expect(w.find('[data-testid="classroom-content"]').attributes('aria-busy')).toBe('true');
    held.forEach((r) => r(json([])));
    await tick();
    expect(w.find('[data-testid="classroom-content"]').attributes('aria-busy')).toBe('false');
    const empty = w.find('[data-testid="classroom-empty"]');
    expect(empty.text()).toContain('目前此分校尚無教室');
    expect(empty.text()).toContain('新增教室後，排課時就能直接選擇上課地點。');
    await empty.find('button').trigger('click');
    await tick();
    expect(q('[role=dialog] [data-slot="dialog-title"]').textContent).toBe('新增教室');
    w.unmount();

    let down = true;
    fetchMock.mockReset();
    fetchMock.mockImplementation(async () => (down ? json({ message: 'temporary failure' }, false) : json(rooms())));
    const failed = await mountPage();
    const alert = failed.find('[role="alert"]');
    expect(alert.find('[data-slot="alert-title"]').text()).toBe('教室清單暫時無法載入，請重試。');
    expect(failed.find('table').exists()).toBe(false);
    down = false;
    const before = fetchMock.mock.calls.length;
    await alert.find('button').trigger('click');
    await tick();
    expect(fetchMock).toHaveBeenCalledTimes(before + 1);
    expect(failed.findAll('tbody tr')).toHaveLength(2);
  });

  it('a non-array answer is a load error, and no branch means no request', async () => {
    fetchMock.mockImplementation(async () => json({ data: [] }));
    const w = await mountPage();
    expect(w.find('[role="alert"]').text()).toContain('教室清單暫時無法載入，請重試。');
    w.unmount();
    fetchMock.mockClear();
    const none = await mountPage({ branchId: null });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(none.find('[data-testid="classroom-empty"]').exists()).toBe(true);
  });

  it('add dialog: labelled fields, existing wording and ids, first field focused, POST payload unchanged, one request', async () => {
    const w = await mountPage();
    await openAdd(w);
    const dialog = q('[role=dialog]');
    expect(dialog.querySelector('[data-slot="dialog-title"]').textContent).toBe('新增教室');
    expect(dialog.getAttribute('aria-describedby')).toBe(dialog.querySelector('[data-slot="dialog-description"]').id);
    expect(dialog.querySelector('label[for="classroom-name"]').textContent).toContain('教室名稱');
    expect(dialog.querySelector('label[for="classroom-capacity"]').textContent).toContain('容量（人）');
    expect(dialog.querySelector('label[for="classroom-memo"]').textContent).toContain('備註');
    const name = q('#classroom-name');
    expect([name.getAttribute('maxlength'), name.getAttribute('placeholder'), name.required]).toEqual(['64', '例如：教室1、201', true]);
    const capacity = q('#classroom-capacity');
    expect([capacity.type, capacity.min, capacity.value, capacity.required]).toEqual(['number', '1', '1', true]);
    expect([q('#classroom-memo').getAttribute('maxlength'), q('#classroom-memo').getAttribute('placeholder')]).toEqual(['512', '選填']);
    const active = q('#classroom-active-create');
    expect([active.getAttribute('role'), active.getAttribute('aria-checked')]).toEqual(['checkbox', 'true']);
    expect(dialog.querySelector('label[for="classroom-active-create"]').textContent.trim()).toBe('啟用（預設勾選）');
    expect(q('#classroom-active-edit')).toBeNull();
    expect(document.activeElement).toBe(name);
    expect(await axe.run(dialog, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);

    await setValue('#classroom-name', '302 教室');
    await setValue('#classroom-capacity', '8');
    await setValue('#classroom-memo', '');
    active.click();
    await tick();
    let release;
    fetchMock.mockImplementationOnce(() => new Promise((r) => { release = r; }));
    const form = q('[role=dialog] form');
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await tick();
    const save = q('[role=dialog] button[type="submit"]');
    expect([save.textContent, save.disabled, save.getAttribute('aria-busy')]).toEqual(['儲存中…', true, 'true']);
    expect(writes()).toHaveLength(1);
    const [url, init] = writes()[0];
    expect([url, init.method, init.credentials]).toEqual(['/api/v1/rooms', 'POST', 'include']);
    expect(JSON.parse(init.body)).toEqual({ name: '302 教室', capacity: 8, campus_id: 1, memo: null, is_active: false });
    release(json({ id: 103 }));
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    // Mount watcher + onMounted load twice (as before), then one reload after the save.
    expect(fetchMock.mock.calls.filter(([, i]) => !i?.method)).toHaveLength(3);
  });

  it('an empty or spaces-only name, or a capacity below 1, calls no API and shows the reason tied to the field', async () => {
    const w = await mountPage();
    await openAdd(w);
    const refused = (inputSelector, message) => {
      const input = q(inputSelector);
      const error = q(`#${input.id}-error`);
      expect(error.getAttribute('role')).toBe('alert');
      expect(error.textContent).toBe(message);
      expect(input.getAttribute('aria-describedby')).toBe(error.id);
      expect(input.getAttribute('aria-invalid')).toBe('true');
      expect(q('[role=dialog]')).not.toBeNull();
      expect(writes()).toHaveLength(0);
    };

    // Empty name: a scripted submit skips the native `required` check, so this covers the handler itself.
    await submitForm();
    refused('#classroom-name', '請輸入教室名稱');
    expect(document.activeElement).toBe(q('#classroom-name'));
    expect(q('#classroom-capacity-error')).toBeNull();

    // Spaces only (this passes native `required` in a browser). Focus is moved away first; editing clears the old error.
    await setValue('#classroom-name', '   ');
    expect(q('#classroom-name-error')).toBeNull();
    q('[role=dialog] button[type="button"]').focus();
    await submitForm();
    refused('#classroom-name', '請輸入教室名稱');
    expect(document.activeElement).toBe(q('#classroom-name'));

    // Capacity: empty, then 0. The name is fine, so focus goes to the capacity field.
    await setValue('#classroom-name', '302 教室');
    for (const value of ['', '0']) {
      await setValue('#classroom-capacity', value);
      expect(q('#classroom-capacity-error')).toBeNull();
      await submitForm();
      refused('#classroom-capacity', '請輸入至少 1 人的容量');
      expect(q('#classroom-name-error')).toBeNull();
      expect(document.activeElement).toBe(q('#classroom-capacity'));
    }

    // Both wrong: both reasons show and focus goes to the first one.
    await setValue('#classroom-name', '');
    await submitForm();
    refused('#classroom-name', '請輸入教室名稱');
    refused('#classroom-capacity', '請輸入至少 1 人的容量');
    expect(document.activeElement).toBe(q('#classroom-name'));

    // Reopening starts clean.
    q('[role=dialog]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    await w.findAll('tbody tr')[0].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[role=dialog] [role="alert"]')).toBeNull();
  });

  it('edit dialog: prefilled, PUT payload unchanged; a refused save reports the server message and stays open', async () => {
    const w = await mountPage();
    await w.findAll('tbody tr')[1].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[role=dialog] [data-slot="dialog-title"]').textContent).toBe('編輯教室');
    expect([q('#classroom-name').value, q('#classroom-capacity').value, q('#classroom-memo').value]).toEqual(['多功能教室', '24', '']);
    expect(q('#classroom-active-edit').getAttribute('aria-checked')).toBe('false');
    expect(q('label[for="classroom-active-edit"]').textContent.trim()).toBe('啟用');
    expect(q('#classroom-active-create')).toBeNull();

    await setValue('#classroom-memo', '後方有櫃子');
    fetchMock.mockResolvedValueOnce(json({ message: '教室名稱已存在' }, false));
    await submitForm();
    const [url, init] = writes()[0];
    expect([url, init.method]).toEqual(['/api/v1/rooms/102', 'PUT']);
    expect(JSON.parse(init.body)).toEqual({ name: '多功能教室', capacity: 24, memo: '後方有櫃子', is_active: false });
    expect(alertSpy).toHaveBeenCalledWith('教室名稱已存在');
    expect(q('[role=dialog]')).not.toBeNull();

    fetchMock.mockRejectedValueOnce(new Error('連線中斷'));
    await submitForm();
    expect(alertSpy).toHaveBeenLastCalledWith('儲存失敗：連線中斷');
    expect(q('[role=dialog] button[type="submit"]').disabled).toBe(false);
  });

  it('啟用／停用 sends only is_active and flips the row; a failure reports 更新失敗 and changes nothing', async () => {
    const w = await mountPage();
    await w.findAll('tbody tr')[0].findAll('button')[1].trigger('click');
    await tick();
    const [url, init] = writes()[0];
    expect([url, init.method, init.body]).toEqual(['/api/v1/rooms/101', 'PUT', JSON.stringify({ is_active: false })]);
    expect(w.findAll('tbody tr')[0].findAll('button')[1].attributes('aria-label')).toBe('啟用教室：201 教室');
    expect(w.findAll('tbody tr')[0].find('[data-slot="badge"]').text()).toBe('停用');

    fetchMock.mockResolvedValueOnce(json({}, false));
    await w.findAll('tbody tr')[1].findAll('button')[1].trigger('click');
    await tick();
    expect(alertSpy).toHaveBeenCalledWith('更新失敗');
    expect(w.findAll('tbody tr')[1].find('[data-slot="badge"]').text()).toBe('停用');
  });

  it('delete confirm: same question, 取消 deletes nothing, 刪除 sends one DELETE and reloads; a failure keeps it open', async () => {
    const w = await mountPage();
    const open = async () => { await w.findAll('tbody tr')[1].findAll('button')[2].trigger('click'); await tick(); };
    await open();
    expect(q('[role=alertdialog]').textContent).toContain('確認刪除');
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('確定要刪除教室「多功能教室」嗎？此操作無法復原。');
    q('[data-testid="confirm-dialog-cancel"]').click();
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    expect(writes()).toHaveLength(0);

    fetchMock.mockResolvedValueOnce(json({}, false));
    await open();
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(alertSpy).toHaveBeenCalledWith('刪除失敗');
    expect(q('[role=alertdialog]')).not.toBeNull();

    const loadsBefore = fetchMock.mock.calls.filter(([, i]) => !i?.method).length;
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(writes().map(([url, init]) => [url, init.method])).toEqual([['/api/v1/rooms/102', 'DELETE'], ['/api/v1/rooms/102', 'DELETE']]);
    expect(q('[role=alertdialog]')).toBeNull();
    expect(fetchMock.mock.calls.filter(([, i]) => !i?.method).length).toBe(loadsBefore + 1);
  });

  it('has no legacy parts or scoped style left and is off the legacy list', () => {
    expect(source).not.toMatch(/design-system\/At|<style|material-symbols|style="/);
    expect(legacyList).not.toContain('src/pages/ClassroomManagement.vue');
  });
});
