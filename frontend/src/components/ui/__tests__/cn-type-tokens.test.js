import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { cn } from '@/lib/utils';
import StatusBadge from '../page/StatusBadge.vue';

// tailwind-merge used to read `text-tpl-caption` as a colour and drop it when a colour class came after it,
// so a StatusBadge rendered at the surrounding text size (「含暫停課程」 at 20px bold on 課程查找).
describe('template type tokens survive a colour class', () => {
  it.each(['caption', 'body', 'list', 'unit', 'page', 'num-sm', 'num'])('keeps tw:text-tpl-%s next to a text colour', (t) => {
    const out = cn(`tw:text-tpl-${t}`, 'tw:text-[var(--ds-warning-text)]', 'tw:text-foreground');
    expect(out).toContain(`tw:text-tpl-${t}`);
    expect(out).toContain('tw:text-foreground');
  });

  it('still lets a later size replace an earlier size', () => {
    expect(cn('tw:text-tpl-caption', 'tw:text-tpl-body')).toBe('tw:text-tpl-body');
  });

  it('a later built-in size replaces the template token (fails without the extended merge config)', () => {
    expect(cn('tw:text-tpl-caption', 'tw:text-sm')).toBe('tw:text-sm');
  });

  it.each(['success', 'warning', 'danger', 'neutral'])('StatusBadge %s keeps its caption size and its tone colour', (tone) => {
    const w = mount(StatusBadge, { props: { tone }, slots: { default: '含暫停課程' } });
    expect(w.classes()).toContain('tw:text-tpl-caption');
    expect(w.attributes('data-tone')).toBe(tone);
    expect(w.classes().some((c) => /^tw:(bg|text)-/.test(c) && c !== 'tw:text-tpl-caption')).toBe(true);
  });
});
