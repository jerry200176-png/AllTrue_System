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
