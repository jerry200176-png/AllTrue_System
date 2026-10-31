import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mount } from '@vue/test-utils';
import AtField from '../AtField.vue';
import AtSelect from '../AtSelect.vue';
import AtTextarea from '../AtTextarea.vue';

describe('AtField', () => {
  it('shows label + required + error caption (error wins over hint)', () => {
    const w = mount(AtField, { props: { label: '姓名', required: true, hint: '請輸入', error: '必填' } });
    expect(w.text()).toContain('姓名');
    expect(w.find('.at-field__req').exists()).toBe(true);
    expect(w.find('.at-field__msg--error').text()).toBe('必填');
    expect(w.text()).not.toContain('請輸入'); // error 取代 hint
    expect(w.classes()).toContain('at-field--error');
  });

  it('shows hint when no error', () => {
    const w = mount(AtField, { props: { label: 'x', hint: '提示' } });
    expect(w.find('.at-field__msg').text()).toBe('提示');
    expect(w.find('.at-field__msg--error').exists()).toBe(false);
  });
});

describe('AtField error nudge (#4697 item 3)', () => {
  it('nudges on first error, replays when the text changes, not on re-render or hint change', async () => {
    const w = mount(AtField, { props: { label: 'x', hint: '提示' } });
    expect(w.classes()).not.toContain('at-field--nudge-a');
    await w.setProps({ error: '太大' });
    expect(w.classes()).toContain('at-field--nudge-a');
    const msg = w.get('.at-field__msg--error').element;
    await w.setProps({ label: 'y' }); // unrelated update
    expect(w.classes()).toContain('at-field--nudge-a');
    expect(w.get('.at-field__msg--error').element).toBe(msg);
    await w.setProps({ error: '太小' });
    expect(w.classes()).toContain('at-field--nudge-b');
    expect(w.classes()).not.toContain('at-field--nudge-a');
    expect(w.get('.at-field__msg--error').element).not.toBe(msg);
    await w.setProps({ error: '' });
    expect(w.classes()).not.toContain('at-field--error');
  });

  it('turns the motion off for reduced motion', async () => {
    const src = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../AtField.vue'), 'utf8');
    expect(src).toMatch(/prefers-reduced-motion: reduce[\s\S]*animation: none/);
    expect(src).not.toMatch(/translate[a-wyz]\(/); // valid CSS transform functions only
    expect(src.match(/translateX\(/g).length).toBeGreaterThanOrEqual(8);
  });
});

describe('AtSelect', () => {
  it('renders options and emits on change', async () => {
    const w = mount(AtSelect, { props: { modelValue: 'a', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] } });
    expect(w.findAll('option').length).toBe(2);
    await w.get('select').setValue('b');
    expect(w.emitted('update:modelValue')[0]).toEqual(['b']);
  });
});

describe('AtTextarea', () => {
  it('emits update and applies error', async () => {
    const w = mount(AtTextarea, { props: { modelValue: '', error: true } });
    expect(w.get('textarea').classes()).toContain('at-textarea--error');
    await w.get('textarea').setValue('多行');
    expect(w.emitted('update:modelValue')[0]).toEqual(['多行']);
  });
});
