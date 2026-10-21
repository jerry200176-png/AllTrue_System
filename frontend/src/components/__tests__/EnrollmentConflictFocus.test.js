import { expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Modal from '../EnrollmentConflictDecisionModal.vue';
it('contains keyboard focus and restores the opener after the active-course decision', async () => {
  vi.stubGlobal('scrollTo', vi.fn());
  const opener = document.createElement('button');
  document.body.append(opener); opener.focus();
  const w = mount(Modal, { attachTo: document.body, props: { show: false } });
  try {
    await w.setProps({ show: true }); await flushPromises();
    const panel = document.querySelector('.at-dialog__panel');
    expect(panel.contains(document.activeElement)).toBe(true);
    const buttons = [...panel.querySelectorAll('button:not([disabled]), textarea:not([disabled])')];
    buttons.at(-1).focus();
    buttons.at(-1).dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(buttons[0]);
    buttons[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(buttons.at(-1));
    await w.setProps({ submitting: true });
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(w.emitted('cancel')).toBeUndefined();
    await w.setProps({ submitting: false });
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(w.emitted('cancel')).toHaveLength(1);
    await w.setProps({ show: false });
    expect(document.activeElement).toBe(opener);
  } finally { w.unmount(); opener.remove(); vi.unstubAllGlobals(); }
});
