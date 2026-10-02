import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SearchableSelect from '../SearchableSelect.vue';

describe('SearchableSelect', () => {
  it('keeps options open on the first click into the input and selects a searched option', async () => {
    const wrapper = mount(SearchableSelect, {
      props: { options: [{ value: 1, label: '王老師' }, { value: 2, label: '林老師' }] },
    });
    const input = wrapper.get('input');
    await input.trigger('focus');
    await input.trigger('click');
    expect(wrapper.find('.ss-dropdown').exists()).toBe(true);
    await input.trigger('keydown.escape');
    expect(wrapper.find('.ss-dropdown').exists()).toBe(false);
    await input.trigger('click');
    expect(wrapper.find('.ss-dropdown').exists()).toBe(true);

    await input.setValue('林');
    expect(wrapper.findAll('.ss-option')).toHaveLength(1);
    await input.trigger('keydown.enter');
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([2]);
    expect(wrapper.find('.ss-dropdown').exists()).toBe(false);
  });
});
