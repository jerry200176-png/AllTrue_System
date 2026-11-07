import { describe, it, expect, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '../table';

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

describe('components/ui select-table', () => {
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
