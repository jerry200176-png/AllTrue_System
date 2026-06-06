import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DayTabsBar from '../DayTabsBar.vue';

const sampleTabs = [
  { name: '週一', dateLabel: '6/2', count: 3 },
  { name: '週二', dateLabel: '6/3', count: 0 },
  { name: '週三', dateLabel: '6/4', count: 12 },
];

describe('DayTabsBar', () => {
  // 正常
  it('renders one tab per item with name/date, badge only when count > 0, and marks active', () => {
    const wrapper = mount(DayTabsBar, { props: { tabs: sampleTabs, activeIdx: 2 } });
    const tabs = wrapper.findAll('.day-tab');
    expect(tabs).toHaveLength(3);
    expect(tabs[0].find('.day-tab-name').text()).toBe('週一');
    expect(tabs[0].find('.day-tab-date').text()).toBe('6/2');
    // badge：count>0 顯示，count===0 不顯示
    expect(tabs[0].find('.day-tab-badge').exists()).toBe(true);
    expect(tabs[0].find('.day-tab-badge').text()).toBe('3');
    expect(tabs[1].find('.day-tab-badge').exists()).toBe(false);
    // active 只標在 activeIdx
    expect(tabs[2].classes()).toContain('active');
    expect(tabs[0].classes()).not.toContain('active');
  });

  // 邊界：點擊 emit select 帶正確索引
  it('emits select with the clicked index', async () => {
    const wrapper = mount(DayTabsBar, { props: { tabs: sampleTabs, activeIdx: 0 } });
    await wrapper.findAll('.day-tab')[1].trigger('click');
    expect(wrapper.emitted('select')).toBeTruthy();
    expect(wrapper.emitted('select')[0]).toEqual([1]);
  });

  // 空值：無 tabs / activeIdx 預設 -1 時不崩潰、無 active
  it('renders empty when tabs is empty and has no active tab by default', () => {
    const wrapper = mount(DayTabsBar);
    expect(wrapper.findAll('.day-tab')).toHaveLength(0);
    expect(wrapper.find('.day-tabs-bar').exists()).toBe(true);
    const withTabs = mount(DayTabsBar, { props: { tabs: sampleTabs } }); // activeIdx 預設 -1
    expect(withTabs.findAll('.active')).toHaveLength(0);
  });
});
