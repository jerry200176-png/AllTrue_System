import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import AtPageHeader from '../AtPageHeader.vue';
import AtBadge from '../AtBadge.vue';
import AtInlineAlert from '../AtInlineAlert.vue';
import AtSkeleton from '../AtSkeleton.vue';
import AtIconButton from '../AtIconButton.vue';
import AtButton from '../AtButton.vue';
import AtFilterBar from '../AtFilterBar.vue';
import AtToolbar from '../AtToolbar.vue';
import AtDataTableShell from '../AtDataTableShell.vue';
import AtModal from '../AtModal.vue';

describe('At foundation primitives', () => {
  afterEach(() => {
    document.body.style.position = '';
    document.body.style.overflow = '';
  });

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

  it('AtButton supports rect shape and loading disabled state', () => {
    const wrapper = mount(AtButton, {
      props: { shape: 'rect', loading: true },
      slots: { default: '儲存' },
    });
    expect(wrapper.classes()).toContain('at-btn--rect');
    expect(wrapper.attributes('disabled')).toBeDefined();
    expect(wrapper.attributes('aria-busy')).toBe('true');
  });

  it('AtFilterBar and AtToolbar expose landmarks', () => {
    const filter = mount(AtFilterBar, { props: { label: '學生篩選' }, slots: { default: '<input />' } });
    expect(filter.attributes('role')).toBe('search');
    expect(filter.attributes('aria-label')).toBe('學生篩選');
    const toolbar = mount(AtToolbar, { props: { label: '工具列' }, slots: { end: '<button>Go</button>' } });
    expect(toolbar.attributes('role')).toBe('toolbar');
  });

  it('AtDataTableShell wraps dense table markup', () => {
    const wrapper = mount(AtDataTableShell, {
      props: { caption: '學生列表', dense: true },
      slots: { default: '<thead><tr><th>姓名</th></tr></thead><tbody><tr><td>Amy</td></tr></tbody>' },
    });
    expect(wrapper.find('table').exists()).toBe(true);
    expect(wrapper.classes()).toContain('at-data-table-shell--dense');
    expect(wrapper.find('caption').text()).toBe('學生列表');
  });

  it('AtModal traps dialog semantics and closes on Escape', async () => {
    const wrapper = mount(AtModal, {
      props: { open: true, title: '核帳登記', description: '請確認' },
      attachTo: document.body,
    });
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(wrapper.emitted('close')).toBeTruthy();
    wrapper.unmount();
  });
});
