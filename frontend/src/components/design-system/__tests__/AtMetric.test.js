import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtMetric from '../AtMetric.vue';

describe('AtMetric', () => {
  it('renders label and value', () => {
    const wrapper = mount(AtMetric, { props: { label: '出席率', value: '92%' } });
    expect(wrapper.find('.at-metric__label').text()).toBe('出席率');
    expect(wrapper.find('.at-metric__value').text()).toBe('92%');
  });

  it('accepts numeric value', () => {
    const wrapper = mount(AtMetric, { props: { label: '學生數', value: 395 } });
    expect(wrapper.find('.at-metric__value').text()).toBe('395');
  });

  it('shows delta with tone class only when delta provided', () => {
    const without = mount(AtMetric, { props: { label: 'l', value: 1 } });
    expect(without.find('.at-metric__delta').exists()).toBe(false);
    const withDelta = mount(AtMetric, {
      props: { label: 'l', value: 1, delta: '+3', deltaTone: 'positive' },
    });
    const delta = withDelta.find('.at-metric__delta');
    expect(delta.exists()).toBe(true);
    expect(delta.classes()).toContain('at-metric__delta--positive');
  });

  it('applies accent border color and modifier when accent given', () => {
    const wrapper = mount(AtMetric, {
      props: { label: 'l', value: 1, accent: 'var(--ds-primary)' },
    });
    expect(wrapper.classes()).toContain('at-metric--accent');
    expect(wrapper.attributes('style')).toContain('border-bottom-color');
  });
});
