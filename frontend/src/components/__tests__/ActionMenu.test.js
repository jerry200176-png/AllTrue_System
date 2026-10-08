import { afterEach, describe, expect, it, vi } from 'vitest';
import { DOMWrapper, mount } from '@vue/test-utils';
import ActionMenu from '../ActionMenu.vue';

const groups = [
  { id: 'lead', label: '', items: [{ id: 'edit', label: '編輯' }] },
  { id: 'move', label: '調動', items: [{ id: 'reschedule', label: '調課' }, { id: 'quick-add', label: '補課', disabled: true, reason: '已無剩餘堂數' }, { id: 'transfer', label: '轉課' }] },
  { id: 'danger', label: '', items: [{ id: 'delete', label: '刪除課程', danger: true, confirm: true }] },
];
let wrapper;
const mountMenu = () => { wrapper = mount(ActionMenu, { props: { groups, label: '數學 的更多操作' }, attachTo: document.body }); return wrapper; };
// The menu is teleported to <body>, so query the document, not the wrapper.
const $ = (sel) => new DOMWrapper(document.body.querySelector(sel));
const has = (sel) => document.body.querySelector(sel) !== null;
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
    expect($('[role="menu"]').attributes('aria-label')).toBe('數學 的更多操作');
    expect(document.body.querySelectorAll('[role="menuitem"]')).toHaveLength(5);
    expect(focusedAction()).toBe('edit');
  });

  it('arrow keys reach disabled items (reason readable) and wrap; End lands on delete; Esc returns focus', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('keydown', { key: 'ArrowDown' });
    await w.vm.$nextTick();
    const menu = $('[role="menu"]');
    expect(focusedAction()).toBe('edit');
    await menu.trigger('keydown', { key: 'ArrowDown' });
    await menu.trigger('keydown', { key: 'ArrowDown' });
    expect(focusedAction()).toBe('quick-add');
    await menu.trigger('keydown', { key: 'Home' });
    expect(focusedAction()).toBe('edit');
    await menu.trigger('keydown', { key: 'End' });
    expect(focusedAction()).toBe('delete');
    await menu.trigger('keydown', { key: 'ArrowDown' });
    expect(focusedAction()).toBe('edit');
    await menu.trigger('keydown', { key: 'Escape' });
    expect(has('[role="menu"]')).toBe(false);
    expect(document.activeElement).toBe(w.get('.am__trigger').element);
  });

  it('↑ on the trigger opens on the last item; type-ahead jumps', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('keydown', { key: 'ArrowUp' });
    await w.vm.$nextTick();
    expect(focusedAction()).toBe('delete');
    await $('[role="menu"]').trigger('keydown', { key: '轉' });
    expect(focusedAction()).toBe('transfer');
  });

  it('emits select for enabled items only and marks danger / confirm items', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    await $('[data-action="quick-add"]').trigger('click');
    expect(w.emitted('select')).toBeUndefined();
    expect(has('[role="menu"]')).toBe(true);
    const qa = $('[data-action="quick-add"]');
    expect(qa.find('.am__reason').text()).toBe('已無剩餘堂數');
    expect(qa.attributes('aria-describedby')).toBe(qa.find('.am__reason').attributes('id'));
    const del = $('[data-action="delete"]');
    expect(del.classes()).toContain('am__item--danger');
    expect(del.text()).toBe('刪除課程…');
    await del.trigger('click');
    expect(w.emitted('select')).toEqual([['delete']]);
  });

  it('draws no label for unlabelled groups; a divider only above the danger group and after labelled ones', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    expect([...document.body.querySelectorAll('.am__group')].map((e) => e.textContent)).toEqual(['調動']);
    const menu = document.body.querySelector('[role="menu"]');
    const seq = [...menu.children].map((e) => (e.matches('hr') ? 'hr' : e.matches('p') ? 'p' : e.dataset.action));
    expect(seq).toEqual(['edit', 'p', 'reschedule', 'quick-add', 'transfer', 'hr', 'delete']);
  });

  it('closes on a second trigger click, outside press, outside focus and Tab; clicks never bubble to the row', async () => {
    const rowClick = vi.fn();
    document.body.addEventListener('click', rowClick);
    const w = mountMenu();
    const t = w.get('.am__trigger');
    await t.trigger('click');
    expect(rowClick).not.toHaveBeenCalled();
    await t.trigger('click');
    expect(has('[role="menu"]')).toBe(false);
    expect(t.attributes('aria-expanded')).toBe('false');
    await t.trigger('click');
    document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    await w.vm.$nextTick();
    expect(has('[role="menu"]')).toBe(false);
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    await t.trigger('click');
    outside.focus();
    await w.vm.$nextTick();
    expect(has('[role="menu"]')).toBe(false);
    await t.trigger('click');
    await w.vm.$nextTick();
    await $('[role="menu"]').trigger('keydown', { key: 'Tab' });
    expect(has('[role="menu"]')).toBe(false);
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
    expect(has('.am__scrim')).toBe(true);
  });
});
