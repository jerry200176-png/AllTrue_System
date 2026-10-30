// Only http(s) or site-relative URLs may become links; javascript:, data:, vbscript: etc. return ''.
export function safeHttpHref(raw, base = 'https://example.invalid') {
  if (!raw) return '';
  try {
    const u = new URL(String(raw || ''), typeof window !== 'undefined' ? window.location.origin : base);
    return u.protocol === 'http:' || u.protocol === 'https:' ? String(raw) : '';
  } catch {
    return '';
  }
}
