import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import AtLoadingBar from '../AtLoadingBar.vue';
import { pending, track, trackedFetch, trackLoader, SHOW_DELAY_MS } from '../../lib/loadingBar';

const tick = async () => { await Promise.resolve(); await Promise.resolve(); await nextTick(); };

describe('loadingBar', () => {
  beforeEach(() => { vi.useFakeTimers(); pending.value = 0; });
  afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); });

  it('counts a request up and down, including rejections', async () => {
    let resolve;
    track(new Promise((r) => { resolve = r; }));
    expect(pending.value).toBe(1);
    resolve();
    await tick();
    expect(pending.value).toBe(0);

    const failing = track(Promise.reject(new Error('boom')));
    expect(pending.value).toBe(1);
    await expect(failing).rejects.toThrow('boom');
    await tick();
    expect(pending.value).toBe(0);
  });

  it('trackedFetch counts a failed fetch and still rejects', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('net')));
    const p = trackedFetch('/x');
    expect(pending.value).toBe(1);
    await expect(p).rejects.toThrow('net');
    await tick();
    expect(pending.value).toBe(0);
  });

  it('tracks a lazy page load', async () => {
    let resolve;
    const load = trackLoader(() => new Promise((r) => { resolve = r; }));
    const p = load();
    expect(pending.value).toBe(1);
    resolve({ default: {} });
    expect(await p).toEqual({ default: {} });
    await tick();
    expect(pending.value).toBe(0);
  });

  it('stays hidden for fast work, shows for slow work, then finishes', async () => {
    const w = mount(AtLoadingBar);
    let resolve;
    track(new Promise((r) => { resolve = r; }));
    await nextTick();
    vi.advanceTimersByTime(SHOW_DELAY_MS - 50);
    resolve();
    await tick();
    vi.advanceTimersByTime(1000);
    await nextTick();
    expect(w.find('[role="progressbar"]').exists()).toBe(false);

    track(new Promise((r) => { resolve = r; }));
    await nextTick();
    vi.advanceTimersByTime(SHOW_DELAY_MS + 1);
    await nextTick();
    const bar = w.find('[role="progressbar"]');
    expect(bar.exists()).toBe(true);
    expect(bar.attributes('aria-busy')).toBe('true');
    expect(bar.attributes('aria-label')).toBe('載入中');
    vi.advanceTimersByTime(5000);
    await nextTick();
    expect(parseFloat(bar.element.style.width)).toBeLessThan(90);

    resolve();
    await tick();
    expect(w.find('[role="progressbar"]').element.style.width).toBe('100%');
    vi.advanceTimersByTime(300);
    await nextTick();
    expect(w.find('[role="progressbar"]').exists()).toBe(false);
    w.unmount();
  });

  it('shows work that started before the bar mounted (Codex P2)', async () => {
    track(new Promise(() => {}));
    const w = mount(AtLoadingBar);
    vi.advanceTimersByTime(SHOW_DELAY_MS + 1);
    await nextTick();
    expect(w.find('[role="progressbar"]').exists()).toBe(true);
    w.unmount();
  });

  it('drops back and keeps creeping when new work starts while finishing (Codex P2)', async () => {
    const w = mount(AtLoadingBar);
    let resolve;
    track(new Promise((r) => { resolve = r; }));
    await nextTick();
    vi.advanceTimersByTime(SHOW_DELAY_MS + 1);
    await nextTick();
    resolve();
    await tick();
    expect(w.find('[role="progressbar"]').element.style.width).toBe('100%');
    track(new Promise(() => {}));
    await nextTick();
    vi.advanceTimersByTime(5000);
    await nextTick();
    const bar = w.find('[role="progressbar"]');
    expect(bar.exists()).toBe(true);
    expect(parseFloat(bar.element.style.width)).toBeLessThan(90);
    w.unmount();
  });
});
