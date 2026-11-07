import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '../sheet';

// jsdom lacks these; reka's popper/select call them.
beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

const body = () => document.body;
const tick = async () => { await nextTick(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); };
const key = (el, k) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));

describe('components/ui dropdown-sheet', () => {
  it('DropdownMenu item fires its handler', async () => {
    const onPick = vi.fn();
    const w = mount({
      components: { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger },
      setup: () => ({ onPick }),
      template: `<DropdownMenu><DropdownMenuTrigger id="m">更多</DropdownMenuTrigger>
        <DropdownMenuContent><DropdownMenuItem @select="onPick">編輯</DropdownMenuItem></DropdownMenuContent></DropdownMenu>`,
    }, { attachTo: document.body });
    const trig = w.get('[data-slot="dropdown-menu-trigger"]').element;
    trig.focus();
    key(trig, 'Enter');
    await tick();
    const item = body().querySelector('[role="menuitem"]');
    expect(item).toBeTruthy();
    item.click();
    await tick();
    expect(onPick).toHaveBeenCalledTimes(1);
    w.unmount();
  });

  it('Sheet renders a side panel dialog', async () => {
    const w = mount({
      components: { Sheet, SheetContent, SheetDescription, SheetTitle },
      template: `<Sheet :open="true"><SheetContent side="left"><SheetTitle>側欄</SheetTitle><SheetDescription>d</SheetDescription></SheetContent></Sheet>`,
    }, { attachTo: document.body });
    await tick();
    const el = body().querySelector('[data-slot="sheet-content"]');
    expect(el.getAttribute('role')).toBe('dialog');
    expect(el.className).toContain('tw:left-0');
    w.unmount();
  });
});
