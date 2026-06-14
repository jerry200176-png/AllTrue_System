import { describe, it, expect } from 'vitest';
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
