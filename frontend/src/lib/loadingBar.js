import { ref } from 'vue';

// Number of requests / lazy chunk loads in flight. The top bar shows only after
// this stays above 0 for SHOW_DELAY_MS, so fast work never flashes it.
export const pending = ref(0);
export const SHOW_DELAY_MS = 300;

export function track(promise) {
  pending.value += 1;
  const done = () => { pending.value -= 1; };
  Promise.resolve(promise).then(done, done);
  return promise;
}

// fetch that counts toward the bar; same signature and result as fetch.
export const trackedFetch = (...args) => track(fetch(...args));

// Wraps a lazy page loader (() => import(...)) so chunk loads count too.
export const trackLoader = (loader) => () => track(loader());
