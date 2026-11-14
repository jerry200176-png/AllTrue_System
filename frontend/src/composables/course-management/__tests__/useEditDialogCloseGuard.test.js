import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { useEditDialogCloseGuard } from '../useEditDialogCloseGuard.js';

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
    expect(src).toContain('e.isComposing');
    expect(src).toContain('useEditDialogCloseGuard');
  });
});
