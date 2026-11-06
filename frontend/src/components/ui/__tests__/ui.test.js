import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { Button } from '../button';
import { Card, CardContent, CardTitle } from '../card';
import { cn } from '@/lib/utils';

describe('components/ui (shadcn-vue)', () => {
  it('Button renders slot, ink-primary pill classes and merges caller classes', () => {
    const w = mount(Button, { props: { class: 'w-full' }, slots: { default: '儲存' } });
    expect(w.element.tagName).toBe('BUTTON');
    expect(w.text()).toBe('儲存');
    expect(w.classes()).toEqual(expect.arrayContaining(['bg-primary', 'text-primary-foreground', 'rounded-full', 'w-full']));
  });

  it('Card renders slots with card tokens', () => {
    const w = mount(Card, { slots: { default: { components: { CardTitle, CardContent }, template: '<CardTitle>標題</CardTitle><CardContent>內容</CardContent>' } } });
    expect(w.attributes('data-slot')).toBe('card');
    expect(w.classes()).toEqual(expect.arrayContaining(['bg-card', 'text-card-foreground']));
    expect(w.text()).toContain('標題');
    expect(w.find('[data-slot="card-content"]').text()).toBe('內容');
  });

  it('cn() resolves conflicting utilities', () => {
    expect(cn('p-2', 'p-4')).toBe('p-4');
  });
});
