import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtButton from '../AtButton.vue';

describe('AtButton', () => {
  it('renders slot label and defaults to primary md', () => {
    const wrapper = mount(AtButton, { slots: { default: '儲存' } });
    expect(wrapper.text()).toContain('儲存');
    expect(wrapper.classes()).toContain('at-btn');
    expect(wrapper.classes()).toContain('at-btn--primary');
    expect(wrapper.classes()).toContain('at-btn--md');
  });

  it('applies variant and size modifiers', () => {
    const wrapper = mount(AtButton, {
      props: { variant: 'danger', size: 'sm' },
      slots: { default: '刪除' },
    });
    expect(wrapper.classes()).toContain('at-btn--danger');
    expect(wrapper.classes()).toContain('at-btn--sm');
  });

  it('adds block modifier only when block is true', () => {
    const block = mount(AtButton, { props: { block: true } });
    expect(block.classes()).toContain('at-btn--block');
    const normal = mount(AtButton);
    expect(normal.classes()).not.toContain('at-btn--block');
  });

  it('forwards disabled attribute and type', () => {
    const wrapper = mount(AtButton, { props: { disabled: true, type: 'submit' } });
    expect(wrapper.attributes('disabled')).toBeDefined();
    expect(wrapper.attributes('type')).toBe('submit');
  });

  it('renders an icon span only when icon prop is provided', () => {
    const withIcon = mount(AtButton, { props: { icon: 'check' } });
    expect(withIcon.find('.at-btn__icon').exists()).toBe(true);
    expect(withIcon.find('.at-btn__icon').text()).toBe('check');
    const without = mount(AtButton);
    expect(without.find('.at-btn__icon').exists()).toBe(false);
  });
});
