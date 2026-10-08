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

describe('ConfirmDialogHost / askConfirm', () => {
  it('is a labelled modal dialog; a destructive confirm starts on 取消 and cancel resolves false', async () => {
    const answer = open({ title: '刪除數學？', message: '不能復原。', confirmLabel: '刪除課程', danger: true });
    await flushPromises();
    const dlg = q('[role="dialog"]');
    expect(dlg.getAttribute('aria-modal')).toBe('true');
    expect(dlg.getAttribute('aria-labelledby')).toBe('confirm-dialog-title');
    expect(q('#confirm-dialog-title').textContent).toBe('刪除數學？');
    expect(q('[data-testid="confirm-dialog-message"]').textContent).toBe('不能復原。');
    expect(document.activeElement).toBe(q('[data-testid="confirm-dialog-cancel"]'));
    expect(q('[data-testid="confirm-dialog-confirm"]').className).toContain('danger');
    q('[data-testid="confirm-dialog-cancel"]').click();
    expect(await answer).toBe(false);
    await flushPromises();
    expect(q('[role="dialog"]')).toBeNull();
  });

  it('a non-destructive confirm starts on the action button; confirming resolves true', async () => {
    const answer = open({ title: '建立共用方案？', confirmLabel: '建立共用方案' });
    await flushPromises();
    expect(document.activeElement).toBe(q('[data-testid="confirm-dialog-confirm"]'));
    expect(q('[data-testid="confirm-dialog-confirm"]').textContent).toBe('建立共用方案');
    q('[data-testid="confirm-dialog-confirm"]').click();
    expect(await answer).toBe(true);
  });

  it('Esc resolves false; asking again cancels the one still open', async () => {
    const first = open({ title: 'A' });
    await flushPromises();
    q('[role="dialog"]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
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
