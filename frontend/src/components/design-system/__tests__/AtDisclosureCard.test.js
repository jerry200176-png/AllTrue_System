import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AtDisclosureCard from '../AtDisclosureCard.vue';

function mountCard(props = {}) {
  const w = mount(AtDisclosureCard, {
    props: { open: false, 'onUpdate:open': (v) => w.setProps({ open: v }), ...props },
    slots: {
      actions: '<button type="button" class="name">王同學</button>',
      summary: '<span class="count">3 筆</span><span class="pending">1 待處理</span>',
      default: '<input class="note" />',
    },
    attachTo: document.body,
  });
  const toggle = () => w.find('button.at-disclosure__toggle');
  const body = () => w.find('.at-disclosure__body');
  return { w, toggle, body };
}

describe('AtDisclosureCard', () => {
  it('collapsed: real button with aria-expanded=false, body inert, count and status still shown', () => {
    const { w, toggle, body } = mountCard();
    expect(toggle().attributes('aria-expanded')).toBe('false');
    expect(toggle().attributes('aria-controls')).toBe(body().attributes('id'));
    expect(body().attributes('inert')).toBeDefined();
    expect(w.find('.count').isVisible()).toBe(true);
    expect(w.find('.pending').text()).toBe('1 待處理');
    w.unmount();
  });

  it('click expands and collapses through v-model:open', async () => {
    const { w, toggle, body } = mountCard();
    await toggle().trigger('click');
    expect(w.emitted('update:open')[0]).toEqual([true]);
    expect(toggle().attributes('aria-expanded')).toBe('true');
    expect(body().attributes('inert')).toBeUndefined();
    await toggle().trigger('click');
    expect(w.emitted('update:open')[1]).toEqual([false]);
    expect(toggle().attributes('aria-expanded')).toBe('false');
    w.unmount();
  });

  it('header actions sit beside the toggle (no nested buttons) and name it', () => {
    const { w, toggle } = mountCard();
    expect(toggle().find('button').exists()).toBe(false);
    const actionsId = w.find('.at-disclosure__actions').attributes('id');
    expect(toggle().attributes('aria-labelledby')).toBe(`${actionsId} ${toggle().attributes('id')}`);
    w.unmount();
  });

  it('collapsing with focus inside the body returns focus to the toggle; input value is kept', async () => {
    const { w, toggle } = mountCard({ open: true });
    const input = w.find('input.note');
    await input.setValue('寫到一半');
    input.element.focus();
    expect(document.activeElement).toBe(input.element);
    await w.setProps({ open: false });
    await nextTick();
    expect(document.activeElement).toBe(toggle().element);
    expect(w.find('input.note').element.value).toBe('寫到一半');
    w.unmount();
  });

  it('stacked edges only when asked', () => {
    const plain = mountCard();
    expect(plain.w.classes()).not.toContain('at-disclosure--stacked');
    plain.w.unmount();
    const stacked = mountCard({ stacked: true });
    expect(stacked.w.classes()).toContain('at-disclosure--stacked');
    stacked.w.unmount();
  });
});
