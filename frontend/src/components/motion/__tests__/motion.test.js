import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { BlurFade, NumberTicker, ShineBorder } from '..';

const setReduced = (on) => {
  window.matchMedia = vi.fn().mockReturnValue({ matches: on, addEventListener() {}, removeEventListener() {} });
};

// Manual frame clock so the count-up is deterministic.
function installFrames() {
  let now = 0;
  let queue = [];
  vi.spyOn(performance, 'now').mockImplementation(() => now);
  vi.stubGlobal('requestAnimationFrame', (cb) => { queue.push(cb); return queue.length; });
  vi.stubGlobal('cancelAnimationFrame', () => { queue = []; });
  return (ms) => { now += ms; const q = queue; queue = []; q.forEach((cb) => cb(now)); };
}

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('motion kit', () => {
  it('NumberTicker under reduced motion shows the final value at once', () => {
    setReduced(true);
    const w = mount(NumberTicker, { props: { value: 7200 } });
    expect(w.text()).toBe('7,200');
  });

  it('NumberTicker counts up and ends on the exact value', async () => {
    setReduced(false);
    const frame = installFrames();
    const w = mount(NumberTicker, { props: { value: 1234, duration: 800 } });
    await nextTick(); // onMounted resets to 0 to start the count
    expect(w.text()).toBe('0');
    frame(400);
    await nextTick();
    const mid = Number(w.text().replace(/,/g, ''));
    expect(mid).toBeGreaterThan(0);
    expect(mid).toBeLessThan(1234);
    frame(500);
    await nextTick();
    expect(w.text()).toBe('1,234');
    expect(w.attributes('data-value')).toBe('1234');
  });

  it('NumberTicker follows a new value and treats NaN as 0', async () => {
    setReduced(true);
    const w = mount(NumberTicker, { props: { value: 3 } });
    await w.setProps({ value: 9 });
    expect(w.text()).toBe('9');
    await w.setProps({ value: Number.NaN });
    expect(w.text()).toBe('0');
  });

  it('BlurFade is static (already in) under reduced motion, and enters after mount otherwise', async () => {
    setReduced(true);
    expect(mount(BlurFade, { slots: { default: 'x' } }).classes()).toContain('blur-fade--in');

    setReduced(false);
    const frame = installFrames();
    const w = mount(BlurFade, { props: { delay: 80 }, slots: { default: 'x' } });
    expect(w.classes()).not.toContain('blur-fade--in');
    expect(w.attributes('style')).toContain('--blur-fade-delay: 80ms');
    frame(16); frame(16);
    await nextTick();
    expect(w.classes()).toContain('blur-fade--in');
    expect(w.text()).toBe('x'); // content is in the DOM and interactive from the first frame
  });

  it('ShineBorder renders the child plus a decorative, non-interactive ring', () => {
    const w = mount(ShineBorder, { slots: { default: '<button>go</button>' } });
    expect(w.find('button').exists()).toBe(true);
    expect(w.find('.shine-border__ring').attributes('aria-hidden')).toBe('true');
  });
});
