import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount } from '@vue/test-utils';
import AtNumberStepper from '../AtNumberStepper.vue';

const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../AtNumberStepper.vue'), 'utf8');
const make = (props = {}, attrs = {}) => {
  let w;
  w = mount(AtNumberStepper, {
    props: { modelValue: 5, min: 1, max: 6, step: 1, 'onUpdate:modelValue': (v) => w.setProps({ modelValue: v }), ...props },
    attrs,
  });
  return w;
};

describe('AtNumberStepper', () => {
  it('keeps a native number input with min/max/step and forwards attrs', () => {
    const w = make({}, { id: 'n', 'aria-label': '總堂數' });
    const i = w.get('input');
    expect(i.attributes()).toMatchObject({ type: 'number', min: '1', max: '6', step: '1', id: 'n', 'aria-label': '總堂數' });
    expect(i.element.value).toBe('5');
  });

  it('+/- change the value by step, clamp, and disable at the limits', async () => {
    const w = make();
    const [minus, plus] = w.findAll('button');
    await plus.trigger('click');
    expect(w.emitted('update:modelValue').at(-1)).toEqual([6]);
    expect(plus.attributes('disabled')).toBeDefined();
    await minus.trigger('click');
    expect(w.emitted('update:modelValue').at(-1)).toEqual([5]);
    await w.setProps({ modelValue: 1 });
    expect(minus.attributes('disabled')).toBeDefined();
  });

  it('typing emits a number, empty stays empty; empty + starts at min', async () => {
    const w = make({ modelValue: '' });
    await w.get('input').setValue('3');
    expect(w.emitted('update:modelValue').at(-1)).toEqual([3]);
    await w.get('input').setValue('');
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['']);
    await w.setProps({ modelValue: '' });
    await w.findAll('button')[1].trigger('click');
    expect(w.emitted('update:modelValue').at(-1)).toEqual([1]);
  });

  it('rolls only on a button press, and the roll is off for reduced motion', async () => {
    const w = make();
    expect(w.get('input').classes()).not.toContain('is-roll-a');
    await w.findAll('button')[0].trigger('click');
    expect(w.get('input').classes().join(' ')).toMatch(/is-roll-[ab]/);
    expect(source).toMatch(/prefers-reduced-motion: reduce[\s\S]*animation: none/);
  });
});
