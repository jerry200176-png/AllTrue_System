import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import WeekNavBar from '../WeekNavBar.vue';

const weekOptions = [
  { value: 1, label: '第1週' },
  { value: 2, label: '第2週' },
];

describe('WeekNavBar', () => {
  // 正常：渲染「全部」+ 各週選項，並反映 modelValue 為選中值
  it('renders all-option plus week options and reflects modelValue', () => {
    const wrapper = mount(WeekNavBar, { props: { modelValue: 2, weekOptions } });
    const options = wrapper.findAll('option');
    expect(options).toHaveLength(3); // 全部 + 2 週
    expect(options[0].text()).toBe('全部');
    expect(wrapper.find('select').element.value).toBe('2');
  });

  // 邊界：上週/下週按鈕 emit prev/next
  it('emits prev and next on nav buttons', async () => {
    const wrapper = mount(WeekNavBar, { props: { modelValue: 0, weekOptions } });
    const btns = wrapper.findAll('.week-nav-btn');
    await btns[0].trigger('click');
    await btns[1].trigger('click');
    expect(wrapper.emitted('prev')).toBeTruthy();
    expect(wrapper.emitted('next')).toBeTruthy();
  });

  // 邊界：選擇 emit update:modelValue 為「數字」（非字串）
  it('emits numeric update:modelValue on change', async () => {
    const wrapper = mount(WeekNavBar, { props: { modelValue: 0, weekOptions } });
    const select = wrapper.find('select');
    select.element.value = '1';
    await select.trigger('change');
    expect(wrapper.emitted('update:modelValue')[0]).toEqual([1]);
    expect(typeof wrapper.emitted('update:modelValue')[0][0]).toBe('number');
  });

  // 空值：無 weekOptions 仍渲染「全部」選項、不崩潰
  it('renders safely with no week options', () => {
    const wrapper = mount(WeekNavBar);
    expect(wrapper.findAll('option')).toHaveLength(1);
    expect(wrapper.find('option').text()).toBe('全部');
  });
});
