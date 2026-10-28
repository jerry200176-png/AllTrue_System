import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import AtSelectAll from '../AtSelectAll.vue';
import { useSelection } from '../../../composables/useSelection';

const ROWS = [
  { id: 1, locked: false },
  { id: 2, locked: false },
  { id: 3, locked: true },
  { id: 4, locked: false },
];

function setup(rows = ROWS) {
  const list = ref(rows);
  const selection = useSelection(list, { canSelect: (r) => !r.locked });
  const w = mount(AtSelectAll, { props: { selection, unit: '堂' }, attachTo: document.body });
  return { list, selection, w, box: () => w.find('input[type="checkbox"]').element };
}

describe('AtSelectAll + useSelection', () => {
  it('shows none / mixed / all and the selected count', async () => {
    const { selection, w, box } = setup();
    expect(box().checked).toBe(false);
    expect(box().indeterminate).toBe(false);
    expect(w.find('[role="status"]').text()).toBe('');

    selection.toggle(1);
    await nextTick();
    expect(box().checked).toBe(false);
    expect(box().indeterminate).toBe(true);
    expect(w.text()).toContain('已選 1 堂');

    selection.toggle(2);
    selection.toggle(4);
    await nextTick();
    expect(box().checked).toBe(true);
    expect(box().indeterminate).toBe(false);
    expect(w.text()).toContain('已選 3 堂');
  });

  it('activating a mixed box (click / Space on the native checkbox) selects every selectable row', async () => {
    const { selection, box } = setup();
    selection.toggle(2);
    await nextTick();
    box().click();
    await nextTick();
    expect(selection.keys).toEqual([1, 2, 4]);
    expect(box().checked).toBe(true);

    box().click();
    await nextTick();
    expect(selection.count).toBe(0);
  });

  it('never selects disabled rows', () => {
    const { selection } = setup();
    selection.toggle(3);
    expect(selection.has(3)).toBe(false);
    selection.toggleAll(true);
    expect(selection.keys).toEqual([1, 2, 4]);
  });

  it('prunes keys that leave the visible list and does not restore them', async () => {
    const { list, selection } = setup();
    selection.toggleAll(true);
    list.value = ROWS.filter((r) => r.id !== 2);
    await nextTick();
    expect(selection.keys).toEqual([1, 4]);
    expect(selection.all).toBe(true);

    list.value = ROWS;
    await nextTick();
    expect(selection.keys).toEqual([1, 4]);
    expect(selection.mixed).toBe(true);
  });

  it('is disabled when nothing in view can be selected', () => {
    const { box } = setup([{ id: 9, locked: true }]);
    expect(box().disabled).toBe(true);
  });
});
