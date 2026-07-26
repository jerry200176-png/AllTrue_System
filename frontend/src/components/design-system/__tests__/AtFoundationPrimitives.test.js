import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AtPageHeader from '../AtPageHeader.vue';
import AtBadge from '../AtBadge.vue';
import AtInlineAlert from '../AtInlineAlert.vue';
import AtSkeleton from '../AtSkeleton.vue';
import AtIconButton from '../AtIconButton.vue';
import AtButton from '../AtButton.vue';
import AtFilterBar from '../AtFilterBar.vue';
import AtToolbar from '../AtToolbar.vue';
import AtSection from '../AtSection.vue';
import AtEmpty from '../AtEmpty.vue';

describe('At foundation primitives (pilot-used)', () => {
  it('AtPageHeader renders title, description, meta and actions', () => {
    const wrapper = mount(AtPageHeader, {
      props: { title: '主任收件匣', description: '說明', icon: 'inbox' },
      slots: {
        meta: '<span>已逾期 2</span>',
        actions: '<button class="act">動作</button>',
      },
    });
    expect(wrapper.find('.at-page-header__title').text()).toContain('主任收件匣');
    expect(wrapper.find('.at-page-header__desc').text()).toBe('說明');
    expect(wrapper.find('.at-page-header__meta').text()).toContain('已逾期 2');
    expect(wrapper.find('.act').exists()).toBe(true);
  });

  it('AtBadge exposes text label and tone class', () => {
    const wrapper = mount(AtBadge, { props: { label: '已逾期', tone: 'danger' } });
    expect(wrapper.text()).toContain('已逾期');
    expect(wrapper.classes()).toContain('at-badge--danger');
  });

  it('AtInlineAlert is role=alert and shows title', () => {
    const wrapper = mount(AtInlineAlert, {
      props: { tone: 'warning', title: '暫時無法更新' },
      slots: { default: '仍顯示快取' },
    });
    expect(wrapper.attributes('role')).toBe('alert');
    expect(wrapper.text()).toContain('暫時無法更新');
    expect(wrapper.text()).toContain('仍顯示快取');
  });

  it('AtSkeleton announces loading for assistive tech', () => {
    const wrapper = mount(AtSkeleton, { props: { rows: 2 } });
    expect(wrapper.attributes('role')).toBe('status');
    expect(wrapper.attributes('aria-busy')).toBe('true');
    expect(wrapper.text()).toContain('載入中');
    expect(wrapper.findAll('.at-skeleton__row')).toHaveLength(2);
  });

  it('AtIconButton requires accessible name', () => {
    const wrapper = mount(AtIconButton, { props: { icon: 'edit', label: '編輯' } });
    expect(wrapper.attributes('aria-label')).toBe('編輯');
    expect(wrapper.attributes('title')).toBe('編輯');
  });

  it('AtButton supports rect shape and loading disabled state', async () => {
    const wrapper = mount(AtButton, {
      props: { shape: 'rect', loading: true },
      slots: { default: '儲存' },
    });
    expect(wrapper.classes()).toContain('at-btn--rect');
    expect(wrapper.attributes('disabled')).toBeDefined();
    expect(wrapper.attributes('aria-busy')).toBe('true');
  });

  it('AtButton defaults to pill for legacy compatibility', () => {
    const wrapper = mount(AtButton, { slots: { default: 'OK' } });
    expect(wrapper.classes()).toContain('at-btn--pill');
  });

  it('AtFilterBar and AtToolbar expose landmarks', () => {
    const filter = mount(AtFilterBar, { props: { label: '學生篩選' }, slots: { default: '<input />' } });
    expect(filter.attributes('role')).toBe('search');
    expect(filter.attributes('aria-label')).toBe('學生篩選');
    const toolbar = mount(AtToolbar, { props: { label: '工具列' }, slots: { end: '<button>Go</button>' } });
    expect(toolbar.attributes('role')).toBe('toolbar');
  });

  it('AtSection and AtEmpty render pilot states', () => {
    const section = mount(AtSection, { props: { title: '區塊' }, slots: { default: '內容' } });
    expect(section.text()).toContain('區塊');
    expect(section.text()).toContain('內容');
    const empty = mount(AtEmpty, { props: { title: '目前沒有待辦案件', description: '下一步' } });
    expect(empty.text()).toContain('目前沒有待辦案件');
  });
});
