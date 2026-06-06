import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtCard from '../AtCard.vue';

describe('AtCard', () => {
  it('renders default variant and body slot', () => {
    const wrapper = mount(AtCard, { slots: { default: '內容' } });
    expect(wrapper.classes()).toContain('at-card');
    expect(wrapper.classes()).toContain('at-card--default');
    expect(wrapper.find('.at-card__body').text()).toContain('內容');
  });

  it('renders inset variant', () => {
    const wrapper = mount(AtCard, { props: { variant: 'inset' } });
    expect(wrapper.classes()).toContain('at-card--inset');
  });

  it('renders title in header when title prop given', () => {
    const wrapper = mount(AtCard, { props: { title: '本週課表' } });
    expect(wrapper.find('.at-card__header').exists()).toBe(true);
    expect(wrapper.find('.at-card__title').text()).toBe('本週課表');
  });

  it('omits header when no title and no header slot', () => {
    const wrapper = mount(AtCard, { slots: { default: 'x' } });
    expect(wrapper.find('.at-card__header').exists()).toBe(false);
  });

  it('renders actions slot inside header', () => {
    const wrapper = mount(AtCard, {
      props: { title: 't' },
      slots: { actions: '<button class="act">act</button>' },
    });
    expect(wrapper.find('.at-card__actions .act').exists()).toBe(true);
  });
});
