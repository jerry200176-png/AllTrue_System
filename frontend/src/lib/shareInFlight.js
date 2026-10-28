// Calls made while a run is still in flight join that run instead of starting another, so two
// buttons bound to the same action (e.g. desktop bar + mobile sticky bar) submit once.
export function shareInFlight(fn) {
  let running = null;
  return (...args) => {
    running ??= (async () => {
      try {
        return await fn(...args);
      } finally {
        running = null;
      }
    })();
    return running;
  };
}
