import { describe, it, expect, beforeEach } from 'vitest';
import { useTextSize, initTextSize } from '../useTextSize.js';

describe('useTextSize', () => {
  beforeEach(() => { localStorage.clear(); document.documentElement.removeAttribute('data-text-size'); });
  it('defaults to 標準, sets the root attribute and remembers 大', () => {
    initTextSize();
    const { current, setSize } = useTextSize();
    expect(current.value).toBe('standard');
    expect(document.documentElement.getAttribute('data-text-size')).toBe('standard');
    setSize('large');
    expect(document.documentElement.getAttribute('data-text-size')).toBe('large');
    expect(localStorage.getItem('alltrue_text_size')).toBe('large');
    setSize('bogus');
    expect(current.value).toBe('large');
    initTextSize();
    expect(current.value).toBe('large');
  });
  it('offers 稍大 between 標準 and 大 and remembers it (in-app #544)', () => {
    initTextSize();
    const { sizes, current, setSize } = useTextSize();
    expect(sizes.map((s) => s.key)).toEqual(['standard', 'medium', 'large']);
    expect(sizes.map((s) => s.label)).toEqual(['標準', '稍大', '大']);
    setSize('medium');
    expect(document.documentElement.getAttribute('data-text-size')).toBe('medium');
    expect(localStorage.getItem('alltrue_text_size')).toBe('medium');
    initTextSize();
    expect(current.value).toBe('medium');
  });
  it('ui.css steps every --tpl-text token up from 標準 to 稍大 to 大', async () => {
    const { readFileSync } = await import('node:fs');
    const { resolve } = await import('node:path');
    const css = readFileSync(resolve(__dirname, '../../ui.css'), 'utf8');
    const block = (sel) => Object.fromEntries([...css.slice(css.indexOf(sel)).split('}')[0].matchAll(/(--tpl-text-[\w-]+):\s*([\d.]+)rem/g)].map((m) => [m[1], Number(m[2])]));
    const standard = block(':root {'); const medium = block(':root[data-text-size="medium"]'); const large = block(':root[data-text-size="large"]');
    expect(Object.keys(medium).sort()).toEqual(Object.keys(large).sort());
    for (const token of Object.keys(large)) {
      expect(medium[token], token).toBeGreaterThan(standard[token]);
      expect(medium[token], token).toBeLessThan(large[token]);
    }
  });
  it('maps the old A+ / A++ choice to 大', () => {
    localStorage.setItem('alltrue_text_scale', 'A++');
    initTextSize();
    expect(document.documentElement.getAttribute('data-text-size')).toBe('large');
  });
  it('survives blocked storage (read and write)', () => {
    const g = Storage.prototype.getItem; const st = Storage.prototype.setItem;
    Storage.prototype.getItem = () => { throw new Error('blocked'); };
    Storage.prototype.setItem = () => { throw new Error('blocked'); };
    try {
      expect(() => initTextSize()).not.toThrow();
      const { current, setSize } = useTextSize();
      expect(current.value).toBe('standard');
      expect(() => setSize('large')).not.toThrow();
      expect(current.value).toBe('large');
    } finally { Storage.prototype.getItem = g; Storage.prototype.setItem = st; }
  });
});
