import { describe, it, expect, beforeEach } from 'vitest';
import { focusFirstDialogField } from '../dialogFocus';

function mount() {
  document.body.innerHTML = '<div data-slot="dialog-content" tabindex="-1"><label><input id="name" value="歐陽子晴多科共用方案" /></label><select id="pick"></select></div>';
  const root = document.querySelector('[data-slot="dialog-content"]');
  const event = { target: root, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
  return { root, event };
}
const media = (coarse) => (q) => ({ matches: q === '(pointer: coarse)' ? coarse : false });

describe('focusFirstDialogField', () => {
  beforeEach(() => { document.body.innerHTML = ''; });
  it('desktop: focuses the first field with the caret at the end', () => {
    const { event } = mount();
    focusFirstDialogField(event, media(false));
    const input = document.getElementById('name');
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(input.value.length);
    expect(input.selectionEnd).toBe(input.value.length);
    expect(event.defaultPrevented).toBe(true);
  });
  it('touch: focuses the dialog itself, not a field, so no keyboard opens', () => {
    const { root, event } = mount();
    focusFirstDialogField(event, media(true));
    expect(document.activeElement).toBe(root);
    expect(document.activeElement.tagName).not.toBe('INPUT');
    expect(event.defaultPrevented).toBe(true);
  });
});
