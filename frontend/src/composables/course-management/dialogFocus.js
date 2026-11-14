/**
 * Initial focus of a form dialog (reka `open-auto-focus` handler).
 * Desktop: the first field, caret at the end (a plain focus() would select a pre-filled value and one keystroke would replace it).
 * Touch (coarse pointer): the keyboard must not pop up over the content, so focus the dialog itself; screen readers still land inside.
 * @param {Event} event the open-auto-focus event (target = the dialog content element)
 * @param {(query: string) => { matches: boolean }} [media] matchMedia, injectable for tests
 */
export function focusFirstDialogField(event, media = (q) => window.matchMedia(q)) {
  const root = event.target?.closest?.('[data-slot="dialog-content"]') ?? event.target;
  if (!root) return;
  if (media('(pointer: coarse)').matches) {
    event.preventDefault();
    root.focus?.();
    return;
  }
  const field = root.querySelector?.('input:not([type="checkbox"]), select, textarea');
  if (!field) return;
  event.preventDefault();
  field.focus();
  try { const end = String(field.value ?? '').length; field.setSelectionRange(end, end); } catch { /* number and select fields have no caret */ }
}

/** After the chip question closes into the 補課／補登 form, focus goes into that form instead of back to the chip behind it. */
export function focusOpenLegacyForm(event, formOpen) {
  if (!formOpen) return;
  event.preventDefault();
  requestAnimationFrame(() => {
    document.querySelector('.modal-overlay .modal input, .modal-overlay .modal select, .modal-overlay .modal textarea')?.focus();
  });
}
