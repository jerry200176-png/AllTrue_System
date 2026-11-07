import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '../dialog';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogTitle } from '../alert-dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../dropdown-menu';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../table';
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

describe('components/ui overlays', () => {
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

  it('Select chooses a value with the keyboard', async () => {
    const value = ref('');
    const w = mount({
      components: { Select, SelectContent, SelectItem, SelectTrigger, SelectValue },
      setup: () => ({ value }),
      template: `<Select v-model="value"><SelectTrigger id="s"><SelectValue placeholder="選擇" /></SelectTrigger>
        <SelectContent><SelectItem value="a">甲</SelectItem><SelectItem value="b">乙</SelectItem></SelectContent></Select>`,
    }, { attachTo: document.body });
    const trig = w.get('#s').element;
    expect(trig.getAttribute('role')).toBe('combobox');
    trig.focus();
    key(trig, 'ArrowDown');
    await tick();
    const opts = body().querySelectorAll('[role="option"]');
    expect(opts.length).toBe(2);
    const listbox = body().querySelector('[role="listbox"]');
    key(listbox, 'ArrowDown');
    key(document.activeElement, 'Enter');
    await tick();
    expect(['a', 'b']).toContain(value.value);
    w.unmount();
  });

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

  it('Table renders semantic table, th and td', () => {
    const w = mount({
      components: { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow },
      template: `<Table><TableCaption>學生</TableCaption><TableHeader><TableRow><TableHead>姓名</TableHead></TableRow></TableHeader>
        <TableBody><TableRow><TableCell>小明</TableCell></TableRow></TableBody></Table>`,
    });
    expect(w.find('table caption').text()).toBe('學生');
    expect(w.find('thead tr th').text()).toBe('姓名');
    expect(w.find('tbody tr td').text()).toBe('小明');
  });
});
