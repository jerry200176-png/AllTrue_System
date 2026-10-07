import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ActionMenu from '../ActionMenu.vue';

const groups = [
  { id: 'move', label: '調動', items: [{ id: 'reschedule', label: '調課' }, { id: 'quick-add', label: '補課', disabled: true, title: '已無剩餘堂數' }, { id: 'transfer', label: '轉課' }] },
  { id: 'end', label: '結束', items: [{ id: 'delete', label: '刪除課程', danger: true, confirm: true }] },
];
let wrapper;
const mountMenu = () => { wrapper = mount(ActionMenu, { props: { groups, label: '數學 的更多操作' }, attachTo: document.body }); return wrapper; };
const focusedAction = () => document.activeElement?.getAttribute('data-action');
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); });

describe('ActionMenu', () => {
  it('is a labelled menu button that opens on the first enabled item', async () => {
    const w = mountMenu();
    const t = w.get('.am__trigger');
    expect(t.attributes('aria-haspopup')).toBe('menu');
    expect(t.attributes('aria-expanded')).toBe('false');
    await t.trigger('click');
    await w.vm.$nextTick();
    expect(t.attributes('aria-expanded')).toBe('true');
    expect(w.get('[role="menu"]').attributes('aria-label')).toBe('數學 的更多操作');
    expect(w.findAll('[role="menuitem"]')).toHaveLength(4);
    expect(focusedAction()).toBe('reschedule');
  });

  it('arrow keys reach disabled items (reason readable) and wrap; End lands on delete; Esc returns focus', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('keydown', { key: 'ArrowDown' });
    await w.vm.$nextTick();
    const menu = w.get('[role="menu"]');
    await menu.trigger('keydown', { key: 'ArrowDown' });
    expect(focusedAction()).toBe('quick-add');
    await menu.trigger('keydown', { key: 'Home' });
    expect(focusedAction()).toBe('reschedule');
    await menu.trigger('keydown', { key: 'End' });
    expect(focusedAction()).toBe('delete');
    await menu.trigger('keydown', { key: 'ArrowDown' });
    expect(focusedAction()).toBe('reschedule');
    await menu.trigger('keydown', { key: 'Escape' });
    expect(w.find('[role="menu"]').exists()).toBe(false);
    expect(document.activeElement).toBe(w.get('.am__trigger').element);
  });

  it('↑ on the trigger opens on the last item; type-ahead jumps', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('keydown', { key: 'ArrowUp' });
    await w.vm.$nextTick();
    expect(focusedAction()).toBe('delete');
    await w.get('[role="menu"]').trigger('keydown', { key: '轉' });
    expect(focusedAction()).toBe('transfer');
  });

  it('emits select for enabled items only and marks danger / confirm items', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    await w.get('[data-action="quick-add"]').trigger('click');
    expect(w.emitted('select')).toBeUndefined();
    expect(w.find('[role="menu"]').exists()).toBe(true);
    expect(w.get('[data-action="quick-add"]').attributes('title')).toBe('已無剩餘堂數');
    const del = w.get('[data-action="delete"]');
    expect(del.classes()).toContain('am__item--danger');
    expect(del.text()).toBe('刪除課程…');
    await del.trigger('click');
    expect(w.emitted('select')).toEqual([['delete']]);
  });

  it('closes on a second trigger click, outside press, outside focus and Tab; clicks never bubble to the row', async () => {
    const rowClick = vi.fn();
    document.body.addEventListener('click', rowClick);
    const w = mountMenu();
    const t = w.get('.am__trigger');
    await t.trigger('click');
    expect(rowClick).not.toHaveBeenCalled();
    await t.trigger('click');
    expect(w.find('[role="menu"]').exists()).toBe(false);
    expect(t.attributes('aria-expanded')).toBe('false');
    await t.trigger('click');
    document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    await w.vm.$nextTick();
    expect(w.find('[role="menu"]').exists()).toBe(false);
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    await t.trigger('click');
    outside.focus();
    await w.vm.$nextTick();
    expect(w.find('[role="menu"]').exists()).toBe(false);
    await t.trigger('click');
    await w.vm.$nextTick();
    await w.get('[role="menu"]').trigger('keydown', { key: 'Tab' });
    expect(w.find('[role="menu"]').exists()).toBe(false);
    outside.remove();
    document.body.removeEventListener('click', rowClick);
  });

  it('renders as a bottom sheet at phone width', async () => {
    vi.stubGlobal('matchMedia', (q) => ({ matches: q.includes('640'), addEventListener() {}, removeEventListener() {} }));
    const w = mountMenu();
    await w.vm.$nextTick();
    expect(w.classes()).toContain('am--sheet');
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    expect(w.find('.am__scrim').exists()).toBe(true);
  });
});
