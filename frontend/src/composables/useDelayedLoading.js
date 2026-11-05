import { ref, watch, computed, onScopeDispose } from 'vue';

export const LOADING_DELAY_MS = 300;

// True only after `loading` has stayed true for `delay` ms; once true it stays
// true for at least `minVisible` ms, so fast loads show nothing and slow ones never blink.
export function useDelayedLoading(loading, { delay = LOADING_DELAY_MS, minVisible = LOADING_DELAY_MS } = {}) {
  const shown = ref(false);
  let showTimer = null;
  let hideTimer = null;
  let shownAt = 0;
  const clear = () => { clearTimeout(showTimer); clearTimeout(hideTimer); showTimer = hideTimer = null; };
  watch(loading, (on) => {
    if (on) {
      if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; return; }
      if (!shown.value && !showTimer) {
        showTimer = setTimeout(() => { showTimer = null; shown.value = true; shownAt = Date.now(); }, delay);
      }
      return;
    }
    clearTimeout(showTimer);
    showTimer = null;
    if (!shown.value) return;
    const wait = Math.max(0, minVisible - (Date.now() - shownAt));
    hideTimer = setTimeout(() => { hideTimer = null; shown.value = false; }, wait);
  }, { immediate: true });
  onScopeDispose(clear);
  return shown;
}

// Refetch rule: with data already on screen keep it (dimmed, aria-busy) instead of swapping in a skeleton.
export function useRefetchView(loading, hasData) {
  return {
    showSkeleton: computed(() => loading.value && !hasData.value),
    busy: computed(() => loading.value && hasData.value),
  };
}
