import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { Button } from '../button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../card';
import { cn } from '@/lib/utils';

describe('components/ui (shadcn-vue)', () => {
  it('Button renders slot, ink-primary pill classes and merges caller classes', () => {
    const w = mount(Button, { props: { class: 'tw:w-full' }, slots: { default: '儲存' } });
    expect(w.element.tagName).toBe('BUTTON');
    expect(w.text()).toBe('儲存');
    expect(w.classes()).toEqual(expect.arrayContaining(['tw:bg-primary', 'tw:text-primary-foreground', 'tw:rounded-full', 'tw:w-full']));
  });

  it('Card renders slots with card tokens', () => {
    const w = mount(Card, { slots: { default: { components: { CardTitle, CardContent }, template: '<CardTitle>標題</CardTitle><CardContent>內容</CardContent>' } } });
    expect(w.attributes('data-slot')).toBe('card');
    expect(w.classes()).toEqual(expect.arrayContaining(['tw:bg-card', 'tw:text-card-foreground']));
    expect(w.text()).toContain('標題');
    expect(w.find('[data-slot="card-content"]').text()).toBe('內容');
  });

  it('CardHeader is a prefixed single-column grid (no legacy .grid auto-fit clash)', () => {
    const w = mount(CardHeader, { slots: { default: { components: { CardTitle, CardDescription }, template: '<CardTitle>t</CardTitle><CardDescription>d</CardDescription>' } } });
    expect(w.classes()).toContain('tw:grid');
    expect(w.classes()).not.toContain('grid');
    expect(w.classes().some((c) => /^tw:(?:\S*:)?grid-cols-/.test(c) && !c.includes('has-data'))).toBe(false);
    expect(w.text()).toContain('d');
  });

  it('cn() resolves conflicting utilities', () => {
    expect(cn('tw:p-2', 'tw:p-4')).toBe('tw:p-4');
  });

  it('every utility in ui/ sources carries the tw: prefix (unprefixed ones are never generated)', () => {
    const sources = import.meta.glob('../**/*.{vue,js}', { query: '?raw', import: 'default', eager: true });
    const bad = [];
    for (const [file, src] of Object.entries(sources)) {
      for (const [, lit] of src.matchAll(/cn\(\s*[`'"]([^`'"]+)[`'"]/g)) {
        for (const c of lit.split(/\s+/).filter(Boolean)) if (!c.startsWith('tw:')) bad.push(`${file}: ${c}`);
      }
    }
    expect(bad).toEqual([]);
  });
});
