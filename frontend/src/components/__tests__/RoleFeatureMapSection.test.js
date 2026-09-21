import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RoleFeatureMapSection from '../RoleFeatureMapSection.vue';

describe('RoleFeatureMapSection component', () => {
  it('renders director feature map with all 24 current items and no super_admin leaks', () => {
    const wrapper = mount(RoleFeatureMapSection, { props: { role: 'director', admissionsEnabled: true } });
    expect(wrapper.text()).toContain('主任功能指南');
    expect(wrapper.text()).toContain('24');
    expect(wrapper.text()).toContain('常用高頻 (11)');
    expect(wrapper.text()).toContain('進階工具 (13)');

    const pages = wrapper.findAll('.rfm-card').map(c => c.attributes('data-page'));
    expect(pages).toHaveLength(24);

    for (const p of ['director', 'calendar', 'attendance', 'students', 'tuition-collect', 'teachers', 'classroom']) {
      expect(pages).toContain(p);
    }
    expect(pages).not.toContain('director-accounts');
    expect(pages).not.toContain('branch-management');
    expect(pages).not.toContain('leave');
  });

  it('renders teacher feature map with exactly 7 items and no director leaks', () => {
    const wrapper = mount(RoleFeatureMapSection, { props: { role: 'teacher' } });
    expect(wrapper.text()).toContain('老師功能指南');
    expect(wrapper.text()).toContain('7');
    expect(wrapper.text()).toContain('常用高頻 (4)');
    expect(wrapper.text()).toContain('進階工具 (3)');

    const pages = wrapper.findAll('.rfm-card').map(c => c.attributes('data-page'));
    expect(pages).toEqual(['teacher-home', 'calendar', 'attendance', 'learning', 'subject-units', 'chat', 'bugs']);
    expect(pages).not.toContain('director');
    expect(pages).not.toContain('students');
  });

  it('renders super_admin features only for super_admin', () => {
    const wrapper = mount(RoleFeatureMapSection, { props: { role: 'super_admin' } });
    const pages = wrapper.findAll('.rfm-card').map(c => c.attributes('data-page'));
    expect(pages).toContain('director-accounts');
    expect(pages).toContain('branch-management');
  });

  it('filters features and emits select-page on click', async () => {
    const wrapper = mount(RoleFeatureMapSection, { props: { role: 'director' } });
    expect(wrapper.findAll('.rfm-card')).toHaveLength(24);

    await wrapper.find('[data-testid="filter-high"]').trigger('click');
    expect(wrapper.findAll('.rfm-card')).toHaveLength(11);

    await wrapper.find('[data-testid="filter-advanced"]').trigger('click');
    expect(wrapper.findAll('.rfm-card')).toHaveLength(13);

    const input = wrapper.find('.search-input');
    await input.setValue('兼職');
    const cards = wrapper.findAll('.rfm-card');
    expect(cards.length).toBe(1);
    expect(cards[0].attributes('data-page')).toBe('parttime-payroll');

    await cards[0].trigger('click');
    expect(wrapper.emitted('select-page')[0]).toEqual(['parttime-payroll']);
  });
});
