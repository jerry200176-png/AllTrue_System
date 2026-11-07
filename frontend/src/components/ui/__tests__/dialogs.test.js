import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '../dialog';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogTitle } from '../alert-dialog';

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

describe('components/ui dialogs', () => {
  it('Dialog opens, closes on Esc and returns focus to the trigger', async () => {
    const w = mount({
      components: { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger },
      template: `<Dialog><DialogTrigger as="button" id="t">開啟</DialogTrigger>
        <DialogContent><DialogTitle>標題</DialogTitle><DialogDescription>說明</DialogDescription><input id="i" /></DialogContent></Dialog>`,
    }, { attachTo: document.body });
    const trigger = w.get('#t').element;
    trigger.focus();
    await w.get('#t').trigger('click');
    await tick();
    const dlg = body().querySelector('[role="dialog"]');
    expect(dlg).toBeTruthy();
    expect(dlg.getAttribute('aria-labelledby')).toBeTruthy();
    expect(body().textContent).toContain('標題');
    key(dlg, 'Escape');
    await tick();
    expect(body().querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(trigger);
    w.unmount();
  });

  it('AlertDialog fires confirm and cancel callbacks', async () => {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    const open = ref(true);
    const w = mount({
      components: { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogTitle },
      setup: () => ({ open, onConfirm, onCancel }),
      template: `<AlertDialog v-model:open="open"><AlertDialogContent>
        <AlertDialogTitle>刪除？</AlertDialogTitle><AlertDialogDescription>無法復原</AlertDialogDescription>
        <AlertDialogCancel @click="onCancel">取消</AlertDialogCancel>
        <AlertDialogAction variant="destructive" @click="onConfirm">刪除</AlertDialogAction>
      </AlertDialogContent></AlertDialog>`,
    }, { attachTo: document.body });
    await tick();
    expect(body().querySelector('[role="alertdialog"]')).toBeTruthy();
    [...body().querySelectorAll('button')].find((b) => b.textContent === '刪除').click();
    await tick();
    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(open.value).toBe(false);
    open.value = true;
    await tick();
    [...body().querySelectorAll('button')].find((b) => b.textContent === '取消').click();
    await tick();
    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledTimes(1);
    w.unmount();
  });
});
