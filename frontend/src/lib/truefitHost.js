/**
 * Host detection for truefit.<existing-domain> without changing auth cookies.
 * Production DNS/Apache activation is documented separately; this only detects intent.
 */

export function isTrueFitHost(hostname = null) {
  const host = String(hostname ?? (typeof window !== 'undefined' ? window.location.hostname : '')).toLowerCase();
  if (!host) return false;
  return host === 'truefit' || host.startsWith('truefit.');
}
