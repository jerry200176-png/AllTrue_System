/**
 * Run independent dashboard requests together while keeping one failed card
 * from preventing the rest of the dashboard from rendering.
 */
export function runDashboardLoaders(loaders = {}) {
  return Promise.allSettled(
    Object.values(loaders)
      .filter((loader) => typeof loader === 'function')
      .map((loader) => Promise.resolve().then(loader)),
  );
}
