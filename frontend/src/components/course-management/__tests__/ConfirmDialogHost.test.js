import { afterEach, describe, expect, it } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import ConfirmDialogHost from '../ConfirmDialogHost.vue';
import { askConfirm, confirmState } from '../../../composables/useConfirmDialog';

const q = (sel) => document.body.querySelector(sel);
let w;
const open = async (opts) => {
  w = mount(ConfirmDialogHost, { attachTo: document.body });
  const answer = askConfirm(opts);
  await flushPromises();
  await flushPromises();
  return answer;
};
afterEach(() => { w?.unmount(); confirmState.value = null; document.body.innerHTML = ''; });

const btn = (label) => [...document.body.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent.trim() === label);

describe('ConfirmDialogHost / askConfirm', () => {
  it('is a labelled alert dialog; focus starts on 取消, a destructive confirm is red, cancel resolves false', async () => {
    const answer = open({ title: '刪除數學？', message: '不能復原。', confirmLabel: '刪除課程', danger: true });
    await flushPromises();
    const dlg = q('[role="alertdialog"]');
    expect(dlg.getAttribute('aria-labelledby')).toBeTruthy();
    expect(dlg.textContent).toContain('刪除數學？');
    expect(dlg.textContent).toContain('不能復原。');
    expect(document.activeElement).toBe(btn('取消'));
    expect(btn('刪除課程').className).toContain('tw:bg-destructive');
    btn('取消').click();
    expect(await answer).toBe(false);
    await flushPromises();
    expect(q('[role="alertdialog"]')).toBeNull();
  });

  it('confirming resolves true (not overwritten by the close that follows)', async () => {
    const answer = open({ title: '建立共用方案？', confirmLabel: '建立共用方案' });
    await flushPromises();
    expect(btn('建立共用方案').className).not.toContain('tw:bg-destructive');
    btn('建立共用方案').click();
    expect(await answer).toBe(true);
  });

  it('Esc resolves false; asking again cancels the one still open', async () => {
    const first = open({ title: 'A' });
    await flushPromises();
    q('[role="alertdialog"]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(await first).toBe(false);
    const a = askConfirm({ title: 'B' });
    const b = askConfirm({ title: 'C' });
    expect(await a).toBe(false);
    expect(confirmState.value.title).toBe('C');
    confirmState.value.resolve(true);
    expect(await b).toBe(true);
  });
});

describe('course management has no native confirm()', () => {
  const dir = dirname(fileURLToPath(import.meta.url));
  const files = [
    '../../../pages/CourseManagement.vue', '../SessionEditModal.vue', '../QuickAddSessionModal.vue',
    '../../../composables/course-management/useSessionEditFlow.js', '../../../composables/course-management/useRescheduleAndMakeup.js',
  ];
  it.each(files)('%s', (f) => {
    const src = readFileSync(resolve(dir, f), 'utf8');
    expect(src).not.toMatch(/(^|[^.\w])confirm\(/m);
    expect(src).not.toMatch(/window\.confirm|window\.prompt|[^.\w]prompt\(/);
  });
  it('the shared close-course flow is given the in-app dialog by the page', () => {
    expect(readFileSync(resolve(dir, '../../../pages/CourseManagement.vue'), 'utf8')).toContain('confirmImpl: (message, opts) => askConfirm({ message, ...opts })');
  });
});
