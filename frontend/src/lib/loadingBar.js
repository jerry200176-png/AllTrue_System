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

// Background refreshes (interval pollers) run inside quietly(): the bar is for what the user is waiting on.
// ponytail: a user action that fetches while a quiet refresh is in flight is not counted either; fine for idle polls.
let quiet = 0;
export function quietly(...runs) {
  quiet += 1;
  // new Promise(...) also catches a refresh that throws before its first await, so quiet always comes back down.
  return Promise.allSettled(runs.filter(Boolean).map((run) => new Promise((ok) => ok(run())))).finally(() => { quiet -= 1; });
}

// fetch that counts toward the bar (unless inside quietly); same signature and result as fetch.
export const trackedFetch = (...args) => (quiet ? fetch(...args) : track(fetch(...args)));

// Wraps a lazy page loader (() => import(...)) so chunk loads count too.
export const trackLoader = (loader) => () => track(loader());
