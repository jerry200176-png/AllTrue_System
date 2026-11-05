import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { readFileSync } from 'node:fs';
import { useDelayedLoading, useRefetchView } from '../useDelayedLoading';

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('useDelayedLoading', () => {
  it('stays hidden before 300ms and shows after', async () => {
    const loading = ref(true);
    const shown = useDelayedLoading(loading);
    vi.advanceTimersByTime(299);
    expect(shown.value).toBe(false);
    vi.advanceTimersByTime(1);
    expect(shown.value).toBe(true);
  });

  it('never shows when the load finishes fast', async () => {
    const loading = ref(true);
    const shown = useDelayedLoading(loading);
    vi.advanceTimersByTime(200);
    loading.value = false;
    await nextTick();
    vi.advanceTimersByTime(1000);
    expect(shown.value).toBe(false);
  });

  it('stays visible at least 300ms once shown', async () => {
    const loading = ref(true);
    const shown = useDelayedLoading(loading);
    vi.advanceTimersByTime(300);
    loading.value = false;
    await nextTick();
    vi.advanceTimersByTime(299);
    expect(shown.value).toBe(true);
    vi.advanceTimersByTime(1);
    expect(shown.value).toBe(false);
  });

  it('a new load during the minimum window keeps it shown', async () => {
    const loading = ref(true);
    const shown = useDelayedLoading(loading);
    vi.advanceTimersByTime(300);
    loading.value = false;
    await nextTick();
    vi.advanceTimersByTime(100);
    loading.value = true;
    await nextTick();
    vi.advanceTimersByTime(1000);
    expect(shown.value).toBe(true);
  });
});

describe('useRefetchView', () => {
  it('first load shows skeleton, refetch keeps content and marks busy', () => {
    const loading = ref(true);
    const hasData = ref(false);
    const v = useRefetchView(loading, hasData);
    expect(v.showSkeleton.value).toBe(true);
    expect(v.busy.value).toBe(false);
    loading.value = false; hasData.value = true;
    expect(v.showSkeleton.value).toBe(false);
    loading.value = true; // refetch
    expect(v.showSkeleton.value).toBe(false);
    expect(v.busy.value).toBe(true);
  });
});

describe('App boot screen', () => {
  it('stays mounted while the spinner is in its minimum-visible window, not only while loading', () => {
    const app = readFileSync(`${process.cwd()}/src/App.vue`, 'utf8');
    const gates = app.match(/<div v-(?:else-)?if="[^"]*" class="loading-screen"/g) || [];
    expect(gates).toHaveLength(2); // main boot + standalone TrueFit
    for (const g of gates) expect(g).toContain('loading || showBootSpinner');
  });
});
