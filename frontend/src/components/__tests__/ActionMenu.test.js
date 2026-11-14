import { afterEach, describe, expect, it, vi } from 'vitest';
import { DOMWrapper, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

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

  it('a scroll inside the menu does not close it; item focus never scrolls the page', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    document.body.querySelector('[role="menu"]').dispatchEvent(new Event('scroll'));
    expect(has('[role="menu"]')).toBe(true);
    // a late scroll event that moved nothing keeps it open (jsdom rects are all 0, so the trigger has not moved)
    window.dispatchEvent(new Event('scroll'));
    await w.vm.$nextTick();
    expect(has('[role="menu"]')).toBe(true);
    // A page scroll caused by focusing an item used to close the menu the moment it opened (below-the-fold rows).
    const src = readFileSync(resolve(__dirname, '../ActionMenu.vue'), 'utf8');
    expect(src).not.toMatch(/list\[[^\]]*\]\?\.focus\(\)/);
    expect(src).toContain('focus({ preventScroll: true })');
  });

  it('a scroll that moved the trigger sideways closes the menu', async () => {
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    w.get('.am__trigger').element.getBoundingClientRect = () => ({ top: 0, bottom: 0, left: 40, right: 40, width: 0, height: 0 });
    window.dispatchEvent(new Event('scroll'));
    await w.vm.$nextTick();
    expect(has('[role="menu"]')).toBe(false);
  });

  // jsdom has no layout: the viewport, the trigger box and the menu's box are stubbed, the menu's rect follows its inline top / bottom / max-height like a browser would.
  const stubLayout = ({ vh, triggerTop, natural, border = 2 }) => {
    vi.stubGlobal('innerHeight', vh);
    vi.stubGlobal('innerWidth', 768);
    const isMenu = (el) => el.getAttribute?.('role') === 'menu';
    vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(function h() { return isMenu(this) ? natural - border : 0; });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function h() { return isMenu(this) ? Math.min(natural, parseFloat(this.style.maxHeight) || vh * 0.7) : 40; });
    vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function h() { return isMenu(this) ? Math.min(natural, parseFloat(this.style.maxHeight) || vh * 0.7) - border : 40; });
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function rect() {
      if (!isMenu(this)) return this.classList.contains('am__trigger') ? { top: triggerTop, bottom: triggerTop + 40, left: 700, right: 740, width: 40, height: 40 } : { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 };
      const height = Math.min(natural, parseFloat(this.style.maxHeight) || vh * 0.7);
      const top = this.style.bottom ? vh - parseFloat(this.style.bottom) - height : parseFloat(this.style.top);
      return { top, bottom: top + height, left: 500, right: 720, width: 220, height };
    });
  };
  const openMenu = async (layout) => {
    stubLayout(layout);
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    const style = document.body.querySelector('[role="menu"]').style;
    return { top: style.top, bottom: style.bottom, maxHeight: style.maxHeight };
  };

  it('a menu that fits where it was placed is left alone, including a short one that opens above near the bottom', async () => {
    const below = await openMenu({ vh: 1024, triggerTop: 100, natural: 400 });
    expect(below).toEqual({ top: '144px', bottom: '', maxHeight: '' });
    wrapper.unmount();
    vi.restoreAllMocks();
    const above = await openMenu({ vh: 1024, triggerTop: 800, natural: 200 });
    expect(above).toEqual({ top: '', bottom: `${1024 - 800 + 4}px`, maxHeight: '' });
  });

  it('a long menu that runs past the viewport goes to the side with room, capped to it (real border read, 8px margin)', async () => {
    // 768x1024, trigger at y=660, 722px menu: placed below it would end at y=1400; above there is 660 - 4 - 8 = 648px
    const r = await openMenu({ vh: 1024, triggerTop: 660, natural: 722, border: 2 });
    expect(r.top).toBe('');
    expect(r.bottom).toBe(`${1024 - 660 + 4}px`);
    expect(r.maxHeight).toBe(`${660 - 4 - 8}px`);
    wrapper.unmount();
    vi.restoreAllMocks();
    // a 420px-capped menu placed above a trigger at y=300 would start at y=-124: it is capped to the 288px above instead
    const high = await openMenu({ vh: 600, triggerTop: 300, natural: 900 });
    expect(high.bottom).toBe(`${600 - 300 + 4}px`);
    expect(high.maxHeight).toBe(`${300 - 4 - 8}px`);
  });

  it('uses the room it has even when that is under 120px', async () => {
    const r = await openMenu({ vh: 200, triggerTop: 60, natural: 900 });
    expect(r.top).toBe('104px');
    expect(r.maxHeight).toBe(`${200 - 100 - 4 - 8}px`);
  });

  it('keyboard focus scrolls the capped menu so the focused item is visible', async () => {
    stubLayout({ vh: 300, triggerTop: 120, natural: 900 });
    vi.spyOn(HTMLElement.prototype, 'offsetTop', 'get').mockImplementation(function t() { return this.getAttribute('role') === 'menuitem' ? [...this.parentElement.querySelectorAll('[role="menuitem"]')].indexOf(this) * 40 : 0; });
    const w = mountMenu();
    await w.get('.am__trigger').trigger('click');
    await w.vm.$nextTick();
    const menu = document.body.querySelector('[role="menu"]');
    const box = menu.clientHeight; // 128 - 2
    expect(menu.scrollTop).toBe(0);
    await $('[role="menu"]').trigger('keydown', { key: 'End' });
    expect(focusedAction()).toBe('delete');
    expect(menu.scrollTop).toBe(4 * 40 + 40 - box); // the last item's bottom edge is the menu's bottom edge
    await $('[role="menu"]').trigger('keydown', { key: 'Home' });
    expect(menu.scrollTop).toBe(0);
  });
});
