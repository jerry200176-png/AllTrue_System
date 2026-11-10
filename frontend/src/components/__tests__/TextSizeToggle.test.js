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
    const [standard, large] = wrapper.findAll('[role="radio"]');
    try {
      expect(standard.attributes('tabindex')).toBe('0');
      expect(large.attributes('tabindex')).toBe('-1');
      for (const key of ['ArrowRight', 'ArrowDown']) {
        await standard.trigger('keydown', { key });
        expect(document.activeElement).toBe(large.element);
        expect(large.attributes('aria-checked')).toBe('true');
        await large.trigger('keydown', { key });
        expect(document.activeElement).toBe(standard.element);
      }
      for (const key of ['ArrowLeft', 'ArrowUp']) {
        await standard.trigger('keydown', { key });
        expect(document.activeElement).toBe(large.element);
        await large.trigger('keydown', { key });
        expect(document.activeElement).toBe(standard.element);
      }
      await large.trigger('click');
      expect(localStorage.getItem('alltrue_text_size')).toBe('large');
      expect(document.documentElement.dataset.textSize).toBe('large');
      expect(large.attributes('tabindex')).toBe('0');
      expect(standard.attributes('tabindex')).toBe('-1');
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
