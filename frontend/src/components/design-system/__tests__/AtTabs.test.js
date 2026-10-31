import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount } from '@vue/test-utils';
import AtTabs from '../AtTabs.vue';

const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../AtTabs.vue'), 'utf8');
const tabs = [
  { value: 'a', label: 'A' },
  { value: 'b', label: 'B', disabled: true },
  { value: 'c', label: 'C', badge: 3 },
];
const make = (props = {}) => mount(AtTabs, {
  attachTo: document.body,
  props: { modelValue: 'a', tabs, label: '測試', idPrefix: 'x', 'onUpdate:modelValue': (v) => make.w.setProps({ modelValue: v }), ...props },
});

describe('AtTabs', () => {
  it('has tablist/tab semantics with roving tabindex and aria-controls', () => {
    const w = (make.w = make());
    expect(w.get('[role="tablist"]').attributes('aria-label')).toBe('測試');
    const t = w.findAll('[role="tab"]');
    expect(t).toHaveLength(3);
    expect(t[0].attributes('aria-selected')).toBe('true');
    expect(t[0].attributes('tabindex')).toBe('0');
    expect(t[2].attributes('tabindex')).toBe('-1');
    expect(t[2].attributes('aria-controls')).toBe('x-panel-c');
    expect(t[2].attributes('id')).toBe('x-tab-c');
    expect(t[2].text()).toContain('3');
    w.unmount();
  });

  it('click selects; disabled tab does not', async () => {
    const w = (make.w = make());
    await w.findAll('[role="tab"]')[1].trigger('click');
    expect(w.emitted('update:modelValue')).toBeUndefined();
    await w.findAll('[role="tab"]')[2].trigger('click');
    expect(w.emitted('update:modelValue')[0]).toEqual(['c']);
    w.unmount();
  });

  it('arrow keys skip disabled tabs, wrap, and Home/End jump', async () => {
    const w = (make.w = make());
    await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowRight' });
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['c']);
    await w.get('[role="tablist"]').trigger('keydown', { key: 'ArrowRight' });
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['a']);
    await w.get('[role="tablist"]').trigger('keydown', { key: 'End' });
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['c']);
    await w.get('[role="tablist"]').trigger('keydown', { key: 'Home' });
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['a']);
    w.unmount();
  });

  it('renders one indicator and turns the slide off for reduced motion', () => {
    const w = (make.w = make());
    expect(w.findAll('.at-tabs-slide__indicator')).toHaveLength(1);
    expect(source).toMatch(/prefers-reduced-motion: reduce[\s\S]*transition: none/);
    expect(source).toContain('var(--ds-motion-base)');
    w.unmount();
  });
});
