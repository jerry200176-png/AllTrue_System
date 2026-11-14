import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ignoreComposingEscape, useEditDialogCloseGuard } from '../useEditDialogCloseGuard.js';

const make = ({ dirty, answer = true, open = true }) => {
  const state = { open, form: { memo: 'a' } };
  const ask = vi.fn(async () => answer);
  const { requestClose } = useEditDialogCloseGuard({
    isOpen: () => state.open,
    isDirty: () => dirty,
    ask,
    close: () => { state.open = false; },
  });
  return { state, ask, requestClose };
};

describe('編輯課程 close guard (Esc and 取消)', () => {
  it('clean form: closes without asking', async () => {
    const { state, ask, requestClose } = make({ dirty: false });
    expect(await requestClose()).toBe(true);
    expect(ask).not.toHaveBeenCalled();
    expect(state.open).toBe(false);
  });

  it('dirty form, user keeps editing: asks, stays open, edits untouched', async () => {
    const { state, ask, requestClose } = make({ dirty: true, answer: false });
    expect(await requestClose()).toBe(false);
    expect(ask).toHaveBeenCalledTimes(1);
    expect(state.open).toBe(true);
    expect(state.form.memo).toBe('a');
  });

  it('dirty form, user discards: closes', async () => {
    const { state, requestClose } = make({ dirty: true, answer: true });
    expect(await requestClose()).toBe(true);
    expect(state.open).toBe(false);
  });

  it('a second Esc while the question shows does not ask twice', async () => {
    let release;
    const ask = vi.fn(() => new Promise((r) => { release = r; }));
    const { requestClose } = useEditDialogCloseGuard({ isOpen: () => true, isDirty: () => true, ask, close: () => {} });
    const first = requestClose();
    expect(await requestClose()).toBe(false);
    release(false);
    await first;
    expect(ask).toHaveBeenCalledTimes(1);
  });

  it('does nothing when the dialog is already closed', async () => {
    const { ask, requestClose } = make({ dirty: true, open: false });
    expect(await requestClose()).toBe(false);
    expect(ask).not.toHaveBeenCalled();
  });

  it('the page routes Esc / overlay update and 取消 through the guard and ignores Esc during IME composition', () => {
    const src = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../pages/CourseManagement.vue'), 'utf8');
    expect(src).toContain('if (!v) requestCloseEditDialog();');
    expect(src).toContain('@click="requestCloseEditDialog">取消</Button>');
    expect(src).toContain('ignoreComposingEscape');
    // no private copy of the check is left in the page
    expect(src).not.toContain('e.isComposing');
    expect(src).toContain('useEditDialogCloseGuard');
  });
});

describe('Esc during IME composition', () => {
  const press = (init) => ({ ...init, preventDefault: vi.fn() });

  it('keeps the dialog open while a composition is running (isComposing or keyCode 229)', () => {
    for (const init of [{ isComposing: true, keyCode: 27 }, { isComposing: false, keyCode: 229 }]) {
      const e = press(init);
      ignoreComposingEscape(e);
      expect(e.preventDefault).toHaveBeenCalledTimes(1);
    }
  });

  it('a plain Esc is left alone so the dialog still closes', () => {
    const e = press({ isComposing: false, keyCode: 27 });
    ignoreComposingEscape(e);
    expect(e.preventDefault).not.toHaveBeenCalled();
  });

  it('every dialog with a text field (edit, billing correction, package, pause) uses the shared handler on its DialogContent', () => {
    const src = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../pages/CourseManagement.vue'), 'utf8');
    for (const testid of ['course-edit-dialog', 'billing-correction-dialog', 'package-conversion-dialog', 'pause-confirm-dialog']) {
      const at = src.indexOf(`data-testid="${testid}"`);
      expect(at, `${testid} exists`).toBeGreaterThan(-1);
      expect(src.slice(at, at + 400), `${testid} ignores the composing Esc`).toContain('@escape-key-down="ignoreComposingEscape"');
    }
  });
});
