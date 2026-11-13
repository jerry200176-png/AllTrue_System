import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import TextSizeToggle from '../TextSizeToggle.vue';
import { initTextSize } from '../../composables/useTextSize';
import { cn } from '../../lib/utils';
import { buttonVariants } from '../ui/button';

beforeEach(() => { localStorage.clear(); initTextSize(); });

describe('shared text size control', () => {
  it('moves focus and selection together with wrapping arrow keys and persists clicks', async () => {
    const wrapper = mount(TextSizeToggle, { attachTo: document.body });
    const radios = wrapper.findAll('[role="radio"]');
    const [standard, medium, large] = radios;
    try {
      // in-app #544: 稍大 sits between 標準 and 大.
      expect(radios.map((r) => r.text())).toEqual(['標準', '稍大', '大']);
      expect(radios.map((r) => r.attributes('tabindex'))).toEqual(['0', '-1', '-1']);
      for (const key of ['ArrowRight', 'ArrowDown']) {
        await standard.trigger('keydown', { key });
        expect(document.activeElement).toBe(medium.element);
        expect(medium.attributes('aria-checked')).toBe('true');
        await medium.trigger('keydown', { key });
        expect(document.activeElement).toBe(large.element);
        await large.trigger('keydown', { key }); // wraps
        expect(document.activeElement).toBe(standard.element);
      }
      for (const key of ['ArrowLeft', 'ArrowUp']) {
        await standard.trigger('keydown', { key }); // wraps backwards
        expect(document.activeElement).toBe(large.element);
        await large.trigger('keydown', { key });
        expect(document.activeElement).toBe(medium.element);
        await medium.trigger('keydown', { key });
        expect(document.activeElement).toBe(standard.element);
      }
      await medium.trigger('click');
      expect(localStorage.getItem('alltrue_text_size')).toBe('medium');
      expect(document.documentElement.dataset.textSize).toBe('medium');
      expect(radios.map((r) => r.attributes('tabindex'))).toEqual(['-1', '0', '-1']);
      await large.trigger('click');
      expect(localStorage.getItem('alltrue_text_size')).toBe('large');
      expect(document.documentElement.dataset.textSize).toBe('large');
    } finally { wrapper.unmount(); }
  });

  it('keeps explicitly typed token size and color through the real Button cn merge', () => {
    const size = 'tw:text-[length:var(--tpl-text-body)]';
    const color = 'tw:text-[color:var(--ds-brand-glyph)]';
    for (const overrides of [[size, color], [color, size]]) {
      const classes = cn(buttonVariants(), ...overrides).split(' ');
      expect(classes).toContain(size);
      expect(classes).toContain(color);
      expect(classes).not.toContain('tw:text-sm');
      expect(classes).not.toContain('tw:text-primary-foreground');
    }
    const secondary = cn(buttonVariants({ variant: 'secondary' }), size).split(' ');
    expect(secondary).toContain(size);
    expect(secondary).toContain('tw:text-secondary-foreground');
    expect(secondary).not.toContain('tw:text-sm');
  });
});
