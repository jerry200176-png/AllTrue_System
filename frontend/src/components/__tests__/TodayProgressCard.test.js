import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TodayProgressCard from '../TodayProgressCard.vue';

describe('TodayProgressCard', () => {
  it('shows honest completed/total progress and the next action', () => {
    const nextTask = { title: '今日點名尚未完成', actionLabel: '前往點名' };
    const wrapper = mount(TodayProgressCard, { props: { completed: 2, total: 5, nextTask } });

    expect(wrapper.find('[data-testid="today-progress-card"]').exists()).toBe(true);
    expect(wrapper.find('.today-progress-card__summary').text()).toContain('2 / 5');
    expect(wrapper.find('.today-progress-card__summary').text()).toContain('還有 3 堂待完成');
    expect(wrapper.find('.today-progress-card__fill').attributes('style')).toContain('width: 40%');
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('2');
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuemax')).toBe('5');
    expect(wrapper.find('.today-progress-card__next-action').attributes('aria-label')).toContain('前往點名');
  });

  it('emits the selected task and exposes an honest empty state', async () => {
    const nextTask = { title: '繳費提醒', actionLabel: '前往催繳' };
    const wrapper = mount(TodayProgressCard, { props: { nextTask } });

    expect(wrapper.find('.today-progress-card__empty').text()).toBe('今天沒有已排定的課務。');
    await wrapper.find('.today-progress-card__next-action').trigger('click');
    expect(wrapper.emitted('next')[0]).toEqual([nextTask]);
  });

  it('keeps loading state separate from an empty zero-progress state', () => {
    const wrapper = mount(TodayProgressCard, { props: { loading: true } });

    expect(wrapper.find('[role="status"]').text()).toContain('載入今日課務進度');
    expect(wrapper.find('.today-progress-card__empty').exists()).toBe(false);
  });
});
