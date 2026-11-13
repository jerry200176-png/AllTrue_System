// in-app #540: a page may register a provider that returns a small, non-PII snapshot of what it is showing.
// The bug-report form asks the provider for the current page key only, best-effort: a throwing or oversized
// provider never blocks or breaks a report.
const providers = new Map();
export const SNAPSHOT_MAX_CHARS = 2600;

export function registerPageDiagnostics(pageKey, provider) {
  if (pageKey && typeof provider === 'function') providers.set(pageKey, provider);
  return () => { if (providers.get(pageKey) === provider) providers.delete(pageKey); };
}

export function getPageSnapshot(pageKey) {
  try {
    const provider = providers.get(pageKey);
    if (!provider) return null;
    const snapshot = provider();
    if (!snapshot || JSON.stringify(snapshot).length > SNAPSHOT_MAX_CHARS) return null;
    return snapshot;
  } catch {
    return null;
  }
}
