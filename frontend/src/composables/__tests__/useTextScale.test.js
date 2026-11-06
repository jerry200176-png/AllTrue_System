import { describe, it, expect, beforeEach } from 'vitest';
import { useTextScale } from '../useTextScale.js';

describe('useTextScale', () => {
  beforeEach(() => localStorage.clear());
  it('defaults to A (1), sets the root token and remembers the choice', () => {
    const { current, setScale } = useTextScale();
    expect(current.value).toBe('A');
    expect(document.documentElement.style.getPropertyValue('--tpl-scale')).toBe('1');
    setScale('A++');
    expect(document.documentElement.style.getPropertyValue('--tpl-scale')).toBe('1.3');
    expect(localStorage.getItem('alltrue_text_scale')).toBe('A++');
    setScale('bogus');
    expect(current.value).toBe('A++');
  });
  it('survives blocked storage', () => {
    const orig = Storage.prototype.setItem;
    Storage.prototype.setItem = () => { throw new Error('blocked'); };
    const { current, setScale } = useTextScale();
    expect(() => setScale('A+')).not.toThrow();
    expect(current.value).toBe('A+');
    Storage.prototype.setItem = orig;
  });
});
