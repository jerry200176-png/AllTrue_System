/**
 * Close guard of the 編輯課程 dialog: Esc and 取消 both call `requestClose`.
 * A clean form closes at once; a dirty one asks first and stays open (edits kept) when the user says no.
 * A second request while the question is showing is ignored, so Esc cannot stack questions.
 * @param {{ isOpen: () => boolean, isDirty: () => boolean, ask: () => Promise<boolean>, close: () => void }} deps
 */
export function useEditDialogCloseGuard({ isOpen, isDirty, ask, close }) {
  let asking = false;
  async function requestClose() {
    if (asking || !isOpen()) return false;
    if (isDirty()) {
      asking = true;
      try {
        if (!await ask()) return false;
      } finally {
        asking = false;
      }
    }
    close();
    return true;
  }
  return { requestClose };
}
// Esc that ends IME composition (isComposing, or keyCode 229) must not close a dialog that has a text field.
export const ignoreComposingEscape = (e) => { if (e.isComposing || e.keyCode === 229) e.preventDefault(); };
