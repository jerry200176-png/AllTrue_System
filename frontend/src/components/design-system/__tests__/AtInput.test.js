import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtInput from '../AtInput.vue';

describe('AtInput', () => {
  it('renders value and emits update:modelValue on input', async () => {
    const wrapper = mount(AtInput, { props: { modelValue: 'hi' } });
    const el = wrapper.get('input');
    expect(el.element.value).toBe('hi');
    await el.setValue('world');
    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['world']);
  });

  it('applies error modifier and forwards disabled/type', () => {
    const wrapper = mount(AtInput, { props: { error: true, disabled: true, type: 'number' } });
    const el = wrapper.get('input');
    expect(wrapper.get('input').classes()).toContain('at-input--error');
    expect(el.attributes('disabled')).toBeDefined();
    expect(el.attributes('type')).toBe('number');
  });
});
