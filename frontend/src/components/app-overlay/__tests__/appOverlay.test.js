import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick, ref } from 'vue';
import axe from 'axe-core';
import { ConfirmDialog, FormDialog, DetailSheet, ActionMenu } from '..';

beforeAll(() => {
  globalThis.ResizeObserver ||= class { observe() {} unobserve() {} disconnect() {} };
  Element.prototype.hasPointerCapture ||= () => false;
  Element.prototype.setPointerCapture ||= () => {};
  Element.prototype.releasePointerCapture ||= () => {};
  Element.prototype.scrollIntoView ||= () => {};
});

const tick = async () => { await nextTick(); await nextTick(); await new Promise((r) => setTimeout(r, 0)); };
const q = (s) => document.body.querySelector(s);
const buttons = (root = '[role=dialog]') => [...document.body.querySelectorAll(`${root} button`)].map((b) => b.textContent.trim());
const key = (el, k) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
// color-contrast needs layout; jsdom has none.
const axeClean = async (node) => (await axe.run(node, { rules: { 'color-contrast': { enabled: false } } })).violations.map((v) => v.id);

describe('ConfirmDialog', () => {
  it('orders [取消] [verb], marks destructive, and has no axe violations', async () => {
    const w = mount(ConfirmDialog, { props: { open: true, title: '刪除這門課？', description: '刪除後無法復原。', confirmLabel: '刪除', destructive: true }, attachTo: document.body });
    await tick();
    expect(buttons('[role=alertdialog]').filter((t) => t !== '關閉')).toEqual(['取消', '刪除']);
    expect(q('[role=alertdialog] button:last-of-type').className).toContain('tw:bg-destructive');
    expect(await axeClean(q('[role=alertdialog]'))).toEqual([]);
    w.unmount();
  });
});

describe('FormDialog', () => {
  const mk = (props = {}) => mount({
    components: { FormDialog },
    setup: () => ({ open: ref(true), props, onSubmit: props.onSubmit || (() => {}) }),
    template: '<FormDialog v-model:open="open" v-bind="props" title="新增分校" description="填寫分校名稱。" @submit="onSubmit"><label for="n">名稱</label><input id="n" /></FormDialog>',
  }, { attachTo: document.body });

  it('focuses the first field, submits, and has no axe violations', async () => {
    const onSubmit = vi.fn();
    const w = mk({ onSubmit });
    await tick();
    expect(document.activeElement?.id).toBe('n');
    expect(buttons().filter((t) => t !== '關閉')).toEqual(['取消', '儲存']);
    expect(await axeClean(q('[role=dialog]'))).toEqual([]);
    q('form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    expect(onSubmit).toHaveBeenCalled();
    w.unmount();
  });

  it('closes on Esc when clean; asks to discard when dirty', async () => {
    let w = mk();
    await tick();
    key(q('[role=dialog]'), 'Escape');
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    w.unmount();

    w = mk({ dirty: true });
    await tick();
    key(q('[role=dialog]'), 'Escape');
    await tick();
    expect(q('[role=dialog]')).toBeTruthy();
    expect(q('[role=alertdialog]').textContent).toContain('放棄未儲存的變更？');
    w.unmount();
  });

  it('disables both buttons while loading', async () => {
    const w = mk({ loading: true });
    await tick();
    expect([...document.body.querySelectorAll('[role=dialog] form button')].every((b) => b.disabled)).toBe(true);
    w.unmount();
  });
});

describe('DetailSheet', () => {
  it('shows title, status, tabs, sticky footer and has no axe violations', async () => {
    const w = mount(DetailSheet, {
      props: { open: true, title: '學生資料', status: '在學', tabs: [{ id: 'a', label: '基本' }, { id: 'b', label: '帳務' }], menuGroups: [{ id: 'g', label: '', items: [{ id: 'x', label: '封存' }] }] },
      slots: { 'tab-a': () => h('p', '內容A'), footer: () => h('button', '儲存') },
      attachTo: document.body,
    });
    await tick();
    const dlg = q('[role=dialog]');
    expect(dlg.textContent).toContain('學生資料');
    expect(dlg.textContent).toContain('在學');
    expect(dlg.textContent).toContain('內容A');
    expect(dlg.querySelectorAll('[role=tab]').length).toBe(2);
    expect(dlg.querySelector('[aria-label="更多操作"]')).toBeTruthy();
    expect(await axeClean(dlg)).toEqual([]);
    w.unmount();
  });
});

describe('ActionMenu', () => {
  it('emits select with the item id and keeps the legacy props', async () => {
    const w = mount(ActionMenu, { props: { label: '更多', groups: [{ id: 'g', label: '', items: [{ id: 'edit', label: '編輯' }, { id: 'del', label: '刪除', danger: true, confirm: true }] }] }, attachTo: document.body });
    const trigger = w.get('button[aria-label="更多"]');
    trigger.element.focus();
    key(trigger.element, 'Enter');
    await tick();
    const items = [...document.body.querySelectorAll('[role=menuitem]')];
    expect(items.map((i) => i.textContent.trim())).toEqual(['編輯', '刪除…']);
    expect(items[1].dataset.variant).toBe('destructive');
    items[0].click();
    await tick();
    expect(w.emitted('select')[0]).toEqual(['edit']);
    w.unmount();
  });

  const groups = [{ id: 'g', label: '', items: [{ id: 'edit', label: '編輯' }, { id: 'pay', label: '收款', disabled: true, reason: '已無剩餘堂數' }] }];
  const phone = (matches) => { window.matchMedia = () => ({ matches, addEventListener() {}, removeEventListener() {} }); };

  it('disabled items stay focusable with their reason, and selecting one does nothing', async () => {
    phone(false);
    const w = mount(ActionMenu, { props: { groups }, attachTo: document.body });
    const trigger = w.get('button[aria-label="更多操作"]');
    trigger.element.focus();
    key(trigger.element, 'Enter');
    await tick();
    const pay = q('[data-action=pay]');
    expect(pay.getAttribute('aria-disabled')).toBe('true');
    expect(pay.hasAttribute('data-disabled')).toBe(false);
    expect(document.getElementById(pay.getAttribute('aria-describedby')).textContent).toBe('已無剩餘堂數');
    pay.focus();
    expect(document.activeElement).toBe(pay);
    pay.click();
    await tick();
    expect(w.emitted('select')).toBeUndefined();
    w.unmount();
  });

  it('renders a bottom sheet with 48px menu items at phone width; same disabled behavior', async () => {
    phone(true);
    const w = mount(ActionMenu, { props: { groups }, attachTo: document.body });
    await tick();
    w.get('button[aria-label="更多操作"]').element.click();
    await tick();
    const menu = q('[role=dialog] [role=menu]');
    expect(menu).toBeTruthy();
    const items = [...menu.querySelectorAll('[role=menuitem]')];
    expect(items.every((i) => i.className.includes('tw:min-h-12'))).toBe(true);
    const pay = items[1];
    expect(pay.getAttribute('aria-disabled')).toBe('true');
    expect(document.getElementById(pay.getAttribute('aria-describedby')).textContent).toBe('已無剩餘堂數');
    items[0].focus();
    key(menu, 'ArrowDown');
    expect(document.activeElement).toBe(pay);
    pay.click();
    expect(w.emitted('select')).toBeUndefined();
    items[0].click();
    expect(w.emitted('select')[0]).toEqual(['edit']);
    await tick();
    expect(q('[role=dialog]')).toBeNull();
    w.unmount();
    phone(false);
  });
});
