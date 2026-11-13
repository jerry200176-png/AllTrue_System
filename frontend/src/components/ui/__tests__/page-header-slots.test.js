import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PageHeader from '../page/PageHeader.vue';
import CollapsibleGroup from '../page/CollapsibleGroup.vue';

// PageHeader slots used by 課程查找 (#4989): `context`, `help` (a direct child of the header) and `actions`.
describe('PageHeader slots', () => {
  const mountHeader = (slots = {}) => mount(PageHeader, {
    props: { title: '課程查找', introClass: 'my-intro' },
    attrs: { 'data-guide': 'course-mgmt-header' },
    slots,
  });

  it('renders one h2 title, the context line, and puts the intro class on the title column only', () => {
    const w = mountHeader({ context: '<span>目前列表 6 位學生</span>' });
    expect(w.findAll('h2')).toHaveLength(1);
    expect(w.get('h2').text()).toBe('課程查找');
    expect(w.get('[data-slot="page-context"]').text()).toBe('目前列表 6 位學生');
    expect(w.get('.my-intro').element.contains(w.get('h2').element)).toBe(true);
    expect(w.classes()).not.toContain('my-intro');
  });

  it('keeps the help slot a direct child of the header (not inside the title column), between the title and the actions', () => {
    const w = mountHeader({ help: '<details data-testid="help"><summary>?</summary></details>', actions: '<button>新增課程</button>' });
    const help = w.get('[data-testid="help"]').element;
    expect(help.parentElement).toBe(w.element);
    expect(w.get('.my-intro').element.contains(help)).toBe(false);
    const order = [...w.element.children].map((c) => (c === help ? 'help' : c.getAttribute('data-slot') || 'intro'));
    expect(order).toEqual(['intro', 'help', 'page-actions']);
    expect(w.attributes('data-guide')).toBe('course-mgmt-header');
  });

  it('renders no context line and no action wrapper when those slots are empty', () => {
    const w = mountHeader();
    expect(w.find('[data-slot="page-context"]').exists()).toBe(false);
    expect(w.find('[data-slot="page-actions"]').exists()).toBe(false);
  });
});

describe('CollapsibleGroup', () => {
  it('toggles through one native button with aria-expanded / aria-controls and keeps actions outside it', async () => {
    const w = mount(CollapsibleGroup, {
      props: { expanded: false, toggleId: 'g-toggle', controls: 'g-panel', toggleClass: 'hook-toggle' },
      slots: { title: '<span>林宥辰</span>', meta: '2 筆進行中', actions: '<button data-testid="act">專注</button>', default: '<p data-testid="after">after</p>' },
    });
    const toggle = w.get('button.hook-toggle');
    expect(toggle.attributes('id')).toBe('g-toggle');
    expect(toggle.attributes('aria-expanded')).toBe('false');
    expect(toggle.attributes('aria-controls')).toBe('g-panel');
    expect(toggle.text()).toContain('林宥辰');
    expect(toggle.text()).toContain('2 筆進行中');
    expect(toggle.element.contains(w.get('[data-testid="act"]').element)).toBe(false);
    expect(w.get('[data-testid="after"]').exists()).toBe(true);
    await toggle.trigger('click');
    expect(w.emitted('toggle')).toHaveLength(1);
    await w.setProps({ expanded: true });
    expect(toggle.attributes('aria-expanded')).toBe('true');
  });
});
