import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import AtDialog from '../AtDialog.vue';

let wrapper;
afterEach(() => { wrapper?.unmount(); document.body.innerHTML = ''; });
const mountSheet = () => {
  wrapper = mount(AtDialog, {
    attachTo: document.body,
    props: { open: true, title: '登記收款', placement: 'right' },
    slots: { default: '<button id="first">a</button><button id="last">b</button>' },
  });
};

describe('AtDialog placement="right" (side sheet)', () => {
  it('renders the right-side sheet classes and keeps the dialog role', async () => {
    mountSheet();
    const overlay = document.body.querySelector('.at-dialog-overlay');
    const panel = document.body.querySelector('.at-dialog__panel');
    expect(overlay.classList).toContain('at-dialog-overlay--right');
    expect(panel.classList).toContain('at-dialog__panel--sheet');
    expect(panel.getAttribute('role')).toBe('dialog');
    expect(panel.getAttribute('aria-modal')).toBe('true');
  });

  it('keeps the center layout by default', () => {
    wrapper = mount(AtDialog, { attachTo: document.body, props: { open: true, title: 't' } });
    expect(document.body.querySelector('.at-dialog-overlay--right')).toBeNull();
    expect(document.body.querySelector('.at-dialog__panel--sheet')).toBeNull();
  });

  it('still locks scroll, traps Tab and closes on Esc', async () => {
    mountSheet();
    await wrapper.vm.$nextTick();
    expect(document.body.style.overflow).toBe('hidden');
    const panel = document.body.querySelector('.at-dialog__panel');
    document.body.querySelector('#last').focus();
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    expect(document.activeElement.className).toContain('at-dialog__close');
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    expect(wrapper.emitted('close')).toHaveLength(1);
  });
});
