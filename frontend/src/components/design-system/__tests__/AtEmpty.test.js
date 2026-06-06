import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtEmpty from '../AtEmpty.vue';

describe('AtEmpty', () => {
  it('renders required title and default inbox icon', () => {
    const wrapper = mount(AtEmpty, { props: { title: '尚無資料' } });
    expect(wrapper.find('.at-empty__title').text()).toBe('尚無資料');
    expect(wrapper.find('.at-empty__icon').text()).toBe('inbox');
  });

  it('renders custom icon', () => {
    const wrapper = mount(AtEmpty, { props: { title: 't', icon: 'event_busy' } });
    expect(wrapper.find('.at-empty__icon').text()).toBe('event_busy');
  });

  it('shows description only when provided', () => {
    const without = mount(AtEmpty, { props: { title: 't' } });
    expect(without.find('.at-empty__desc').exists()).toBe(false);
    const withDesc = mount(AtEmpty, { props: { title: 't', description: '請新增課程' } });
    expect(withDesc.find('.at-empty__desc').text()).toBe('請新增課程');
  });

  it('renders action slot when provided', () => {
    const wrapper = mount(AtEmpty, {
      props: { title: 't' },
      slots: { action: '<button class="cta">新增</button>' },
    });
    expect(wrapper.find('.at-empty__action .cta').exists()).toBe(true);
  });
});
