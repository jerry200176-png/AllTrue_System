import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AtIcon from '../AtIcon.vue';
import { AT_ICON_NAMES, AT_ICON_PATHS, AT_ICON_SIZES } from '../atIcons.js';

describe('AtIcon', () => {
  it.each(AT_ICON_NAMES)('renders "%s" as inline currentColor SVG paths from the registry', (name) => {
    const wrapper = mount(AtIcon, { props: { name } });
    const svg = wrapper.find('svg');
    expect(svg.attributes('data-icon')).toBe(name);
    expect(svg.attributes('stroke')).toBe('currentColor');
    expect(svg.attributes('fill')).toBe('none');
    expect(wrapper.findAll('path').map((p) => p.attributes('d'))).toEqual(AT_ICON_PATHS[name]);
  });

  it('ships the U1 icon set', () => {
    for (const name of ['close', 'check', 'warning', 'info', 'search', 'user', 'bell', 'calendar',
      'clipboard', 'edit', 'package', 'chevron-left', 'chevron-right', 'plus', 'more']) {
      expect(AT_ICON_NAMES).toContain(name);
    }
  });

  it('is decorative (aria-hidden) by default', () => {
    const svg = mount(AtIcon, { props: { name: 'bell' } }).find('svg');
    expect(svg.attributes('aria-hidden')).toBe('true');
    expect(svg.attributes('role')).toBeUndefined();
  });

  it('exposes role="img" with an accessible name when label is given', () => {
    const svg = mount(AtIcon, { props: { name: 'warning', label: '注意' } }).find('svg');
    expect(svg.attributes('role')).toBe('img');
    expect(svg.attributes('aria-label')).toBe('注意');
    expect(svg.attributes('aria-hidden')).toBeUndefined();
  });

  it.each(AT_ICON_SIZES)('maps size "%s" to a modifier class', (size) => {
    const svg = mount(AtIcon, { props: { name: 'plus', size } }).find('svg');
    expect(svg.classes()).toContain(`at-icon--${size}`);
  });

  it('defaults to md size', () => {
    expect(mount(AtIcon, { props: { name: 'plus' } }).find('svg').classes()).toContain('at-icon--md');
  });

  it('rejects unknown names and sizes via prop validators', () => {
    const { name, size } = AtIcon.props;
    expect(name.validator('rocket')).toBe(false);
    expect(name.validator('close')).toBe(true);
    expect(size.validator('xl')).toBe(false);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    mount(AtIcon, { props: { name: 'rocket' } });
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });
});
