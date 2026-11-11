import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import SubjectSettingsPage from '../../pages/SubjectSettingsPage.vue';
import { fetchSubjectOptions, createSubject, updateSubject, deleteSubject } from '../../lib/subjectsApi';

// #4989: 科目管理 on components/ui + app-overlay, mounted with the subjects API mocked.
vi.mock('../../lib/subjectsApi', () => ({
  fetchSubjectOptions: vi.fn(),
  createSubject: vi.fn(),
  updateSubject: vi.fn(),
  deleteSubject: vi.fn(),
}));

beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

const subjects = [
  { id: 1, label: '英文', campus_id: null },
  { id: 2, label: '分校數學', campus_id: 1 },
];
const tick = async () => { await flushPromises(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); await flushPromises(); };
const q = (s) => document.body.querySelector(s);
const dialogButton = (root, text) => [...document.body.querySelectorAll(`${root} button`)].find((b) => b.textContent.trim() === text);
const source = readFileSync(resolve(__dirname, '../../pages/SubjectSettingsPage.vue'), 'utf8');
const legacyList = JSON.parse(readFileSync(resolve(__dirname, '../../../../scripts/ui-legacy-pages.json'), 'utf8'));

let wrapper;
const mountPage = async (props = {}) => {
  wrapper = mount(SubjectSettingsPage, { props: { branchId: 1, userRole: 'director', ...props }, attachTo: document.body });
  await tick();
  return wrapper;
};

beforeEach(() => {
  vi.clearAllMocks();
  fetchSubjectOptions.mockResolvedValue(subjects);
});
afterEach(() => { wrapper?.unmount(); wrapper = null; });

describe('SubjectSettingsPage on the template', () => {
  it('loads the same list for the branch and shows the title as the only h2, with the guide anchor kept', async () => {
    const w = await mountPage();
    expect(fetchSubjectOptions).toHaveBeenCalledWith({ branchId: 1 });
    expect(w.findAll('h2').map((h) => h.text())).toEqual(['科目管理']);
    expect(w.find('h1').exists()).toBe(false);
    expect(w.find('[data-guide="subject-settings-header"] h2').exists()).toBe(true);
    expect(w.text()).toContain('管理目前分校可選用的科目；共用科目由系統預設。');
    // The page never had a main landmark; it must not add a second one inside a shell that may own it.
    expect(w.element.tagName).toBe('DIV');
    expect(w.findAll('main, [role="main"]')).toHaveLength(0);
  });

  it('keeps table semantics, the phone list, the type labels and the super_admin-only lock on shared subjects', async () => {
    const w = await mountPage();
    expect(w.findAll('thead th').map((th) => th.text())).toEqual(['科目名稱', '類型', '操作']);
    expect(w.findAll('tbody tr')).toHaveLength(2);
    expect(w.find('.subject-mobile-list').attributes('aria-label')).toBe('科目清單');
    expect(w.findAll('.subject-mobile-card')).toHaveLength(2);
    expect(w.findAll('tbody tr').map((tr) => tr.find('[data-slot="badge"]').text())).toEqual(['共用', '分校專屬']);
    const rowButtons = w.findAll('tbody tr').map((tr) => tr.findAll('button').map((b) => [b.text(), b.element.disabled, b.attributes('title')]));
    expect(rowButtons[0]).toEqual([['更名', true, '共用科目僅超級管理員可更名'], ['刪除', true, '共用科目僅超級管理員可刪除']]);
    expect(rowButtons[1]).toEqual([['更名', false, ''], ['刪除', false, '']]);
  });

  it('lets super_admin rename and delete shared subjects', async () => {
    const w = await mountPage({ userRole: 'super_admin' });
    expect(w.findAll('tbody tr')[0].findAll('button').every((b) => !b.element.disabled)).toBe(true);
  });

  it('announces loading politely, shows the empty state, and the error alert refetches on 重試', async () => {
    let release;
    fetchSubjectOptions.mockReturnValueOnce(new Promise((r) => { release = r; }));
    const w = await mountPage();
    const loading = w.find('[data-testid="subject-settings-loading"]');
    expect([loading.attributes('role'), loading.attributes('aria-live'), loading.text()]).toEqual(['status', 'polite', '載入中...']);
    release([]);
    await tick();
    expect(w.find('[data-testid="subject-settings-empty"]').text()).toContain('目前沒有科目資料');
    expect(w.text()).toContain('新增第一個分校科目後，課程建立時就能選用。');
    w.unmount();

    fetchSubjectOptions.mockReset();
    fetchSubjectOptions.mockRejectedValueOnce(new Error('科目服務暫時無法載入')).mockResolvedValueOnce(subjects);
    const failed = await mountPage();
    expect(failed.find('[role="alert"]').text()).toContain('無法載入科目列表');
    expect(failed.find('[role="alert"]').text()).toContain('科目服務暫時無法載入');
    await failed.find('[role="alert"] button').trigger('click');
    await tick();
    expect(fetchSubjectOptions).toHaveBeenCalledTimes(2);
    expect(failed.findAll('tbody tr')).toHaveLength(2);
  });

  it('add dialog: labelled field, existing wording, first field focused, and Enter plus the button share one request (#4488)', async () => {
    let release;
    createSubject.mockReturnValueOnce(new Promise((r) => { release = r; }));
    const w = await mountPage();
    await w.find('[data-guide="subject-settings-header"] button').trigger('click');
    await tick();
    const dialog = q('[role=dialog]');
    expect(dialog.querySelector('[data-slot="dialog-title"]').textContent).toBe('新增科目');
    expect(dialog.querySelector('[data-slot="dialog-description"]').textContent).toBe('最多 16 個字。');
    expect(dialog.getAttribute('aria-describedby')).toBe(dialog.querySelector('[data-slot="dialog-description"]').id);
    const input = dialog.querySelector('#subject-name');
    expect(dialog.querySelector('label[for="subject-name"]').textContent).toContain('科目名稱');
    expect([input.getAttribute('maxlength'), input.getAttribute('placeholder'), input.required]).toEqual(['16', '例如：程式設計', true]);
    expect(document.activeElement).toBe(input);
    expect(await axe.run(dialog, { rules: { 'color-contrast': { enabled: false } } }).then((r) => r.violations.map((v) => v.id))).toEqual([]);

    input.value = '  程式設計 ';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await tick();
    const submitForm = () => dialog.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    submitForm();
    submitForm();
    await nextTick();
    const save = dialogButton('[role=dialog]', '儲存中…');
    expect([save.disabled, save.getAttribute('aria-busy')]).toEqual([true, 'true']);
    expect(createSubject).toHaveBeenCalledTimes(1);
    expect(createSubject).toHaveBeenCalledWith('程式設計', 1);
    release({ id: 3 });
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    expect(fetchSubjectOptions).toHaveBeenCalledTimes(2);
  });

  it('a spaces-only or empty name calls no API, shows the error tied to the field, and keeps focus there', async () => {
    const w = await mountPage();
    const submitForm = () => q('[role=dialog] form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    const assertRefused = async () => {
      const input = q('#subject-name');
      const error = q('[role=dialog] [role="alert"]');
      expect(error.textContent).toBe('請輸入科目名稱');
      expect(input.getAttribute('aria-describedby')).toBe(error.id);
      expect(input.getAttribute('aria-invalid')).toBe('true');
      expect(document.activeElement).toBe(input);
      expect(q('[role=dialog]')).not.toBeNull();
      expect(createSubject).not.toHaveBeenCalled();
      expect(updateSubject).not.toHaveBeenCalled();
      expect(fetchSubjectOptions).toHaveBeenCalledTimes(1);
    };

    // Add, empty: a scripted submit skips native `required`, so this covers the handler itself.
    await w.find('[data-guide="subject-settings-header"] button').trigger('click');
    await tick();
    submitForm();
    await tick();
    await assertRefused();

    // Add, spaces only (this passes native `required` in a browser). Focus is moved away first.
    const input = q('#subject-name');
    input.value = '   ';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await tick();
    q('[role=dialog] button[type="button"]').focus();
    submitForm();
    await tick();
    await assertRefused();
    q('[role=dialog]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await tick();
    expect(q('[role=dialog]')).toBeNull();

    // Rename, cleared to spaces: the earlier error is gone on open, then shown again.
    await w.findAll('tbody tr')[1].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[role=dialog] [role="alert"]')).toBeNull();
    const rename = q('#subject-name');
    rename.value = '  ';
    rename.dispatchEvent(new Event('input', { bubbles: true }));
    await tick();
    submitForm();
    await tick();
    await assertRefused();
  });

  it('rename dialog: prefilled, calls updateSubject, and a failure stays inline in the open dialog', async () => {
    updateSubject.mockRejectedValueOnce(new Error('名稱重複'));
    const w = await mountPage();
    await w.findAll('tbody tr')[1].findAll('button')[0].trigger('click');
    await tick();
    expect(q('[role=dialog] [data-slot="dialog-title"]').textContent).toBe('科目更名');
    expect(q('#subject-name').value).toBe('分校數學');
    q('[role=dialog] form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    await tick();
    expect(updateSubject).toHaveBeenCalledWith(2, '分校數學');
    expect(q('[role=dialog] [role="alert"]').textContent).toBe('名稱重複');
    expect(fetchSubjectOptions).toHaveBeenCalledTimes(1);
  });

  it('delete confirm: same question, [取消] [刪除], deletes once and reloads; 取消 deletes nothing', async () => {
    const w = await mountPage();
    await w.findAll('tbody tr')[1].findAll('button')[1].trigger('click');
    await tick();
    expect(q('[role=alertdialog]').textContent).toContain('確認刪除');
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('確定要刪除科目「分校數學」嗎？若有課程使用此科目則無法刪除。');
    q('[data-testid="confirm-dialog-cancel"]').click();
    await tick();
    expect(q('[role=alertdialog]')).toBeNull();
    expect(deleteSubject).not.toHaveBeenCalled();

    deleteSubject.mockResolvedValueOnce({});
    await w.findAll('tbody tr')[1].findAll('button')[1].trigger('click');
    await tick();
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(deleteSubject).toHaveBeenCalledTimes(1);
    expect(deleteSubject).toHaveBeenCalledWith(2);
    expect(q('[role=alertdialog]')).toBeNull();
    expect(fetchSubjectOptions).toHaveBeenCalledTimes(2);
  });

  it('a failed delete reports the message and keeps the confirm open', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    deleteSubject.mockRejectedValueOnce(new Error('仍有課程使用'));
    const w = await mountPage();
    await w.findAll('tbody tr')[1].findAll('button')[1].trigger('click');
    await tick();
    q('[data-testid="confirm-dialog-confirm"]').click();
    await tick();
    expect(alertSpy).toHaveBeenCalledWith('仍有課程使用');
    expect(q('[role=alertdialog]')).not.toBeNull();
    alertSpy.mockRestore();
  });

  it('uses 48px controls, the 6px phone gutter, an explicit th override, and no legacy parts or scoped style', async () => {
    const w = await mountPage();
    expect(w.findAll('button[data-slot="button"]').every((b) => b.classes().includes('tw:h-12'))).toBe(true);
    expect(w.classes().join(' ')).toContain('tw:px-[calc(var(--ds-space-4)-10px)]');
    // The global `th` rule (12px, uppercase) is overridden on the table and on each head cell.
    expect(w.find('table').classes()).toContain('tw:[&_th]:text-[length:var(--tpl-text-caption)]');
    expect(w.find('thead th').classes()).toContain('tw:normal-case');
    expect(source).not.toMatch(/design-system\/At|<style|material-symbols/);
    expect(legacyList).not.toContain('src/pages/SubjectSettingsPage.vue');
  });
});
