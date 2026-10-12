import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HubTabBar from '../HubTabBar.vue';

const tabs = [
  { page: 'a', label: '甲', badgeTypes: ['x'] },
  { page: 'b', label: '乙' },
  { page: 'c', label: '丙' },
];

describe('HubTabBar', () => {
  const make = (props = {}) => mount(HubTabBar, {
    attachTo: document.body,
    props: { title: '設定', tabs, active: 'b', badgeCount: (t) => (t.page === 'a' ? 3 : 0), ...props },
  });

  it('marks the active tab, shows badges and emits the tab page key', async () => {
    const w = make();
    const buttons = w.findAll('button.hub-tab');
    expect(w.find('nav').attributes('aria-label')).toBe('設定分頁');
    expect(buttons.map(b => b.attributes('aria-current'))).toEqual([undefined, 'page', undefined]);
    expect(buttons[0].text()).toContain('3');
    await buttons[2].trigger('click');
    expect(w.emitted('select')[0]).toEqual(['c']);
    w.unmount();
  });

  it('moves focus with arrow keys, wrapping, Home and End', async () => {
    const w = make();
    const buttons = w.findAll('button.hub-tab');
    buttons[0].element.focus();
    await buttons[0].trigger('keydown', { key: 'ArrowLeft' });
    expect(document.activeElement).toBe(buttons[2].element);
    await buttons[2].trigger('keydown', { key: 'ArrowRight' });
    expect(document.activeElement).toBe(buttons[0].element);
    await buttons[0].trigger('keydown', { key: 'End' });
    expect(document.activeElement).toBe(buttons[2].element);
    await buttons[2].trigger('keydown', { key: 'Home' });
    expect(document.activeElement).toBe(buttons[0].element);
    w.unmount();
  });
});
