// Tiny local "Sentry User Feedback" context: remember the last few failed
// /api/ calls so a bug report can say which request broke. Never reads bodies
// or auth headers; query strings are dropped (may carry PII).
const MAX = 5;
const failures = [];
let installed = false;

function record(input, init, status, headers) {
  try {
    const raw = typeof input === 'string' ? input : input?.url || String(input);
    const path = new URL(raw, window.location.href).pathname;
    if (!path.startsWith('/api/')) return;
    const method = String(init?.method || input?.method || 'GET').toUpperCase();
    failures.push({
      method,
      path,
      status,
      at: new Date().toISOString(),
      requestId: headers?.get?.('X-Request-Id') || null,
    });
    if (failures.length > MAX) failures.shift();
  } catch { /* recording must never break a request */ }
}

export function installFetchRecorder() {
  if (installed || typeof window === 'undefined' || typeof window.fetch !== 'function') return;
  installed = true;
  const orig = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    try {
      const res = await orig(input, init);
      if (!res.ok) record(input, init, res.status, res.headers);
      return res;
    } catch (err) {
      record(input, init, 0, null);
      throw err;
    }
  };
}

export function getRecentApiFailures() {
  return failures.map((f) => ({ ...f }));
}

export function resetRecentApiFailuresForTest() {
  failures.length = 0;
}

let buildShaPromise = null;
/** build_sha from /version.json, fetched once; null on any error. */
export function getBuildSha() {
  buildShaPromise ||= fetch('/version.json', { cache: 'no-store' })
    .then((r) => (r.ok ? r.json() : null))
    .then((j) => (typeof j?.build_sha === 'string' ? j.build_sha : null))
    .catch(() => null);
  return buildShaPromise;
}

export const CLIENT_INFO_MAX = 3800;

/** JSON.stringify(info) that always parses and fits: shed failures oldest-first, then relatedReference. */
export function fitClientInfo(info, max = CLIENT_INFO_MAX) {
  const o = { ...info, recentApiFailures: [...(info.recentApiFailures || [])] };
  let s = JSON.stringify(o);
  while (s.length > max && o.recentApiFailures.length) {
    o.recentApiFailures.shift();
    s = JSON.stringify(o);
  }
  if (s.length > max) {
    o.relatedReference = null;
    s = JSON.stringify(o);
  }
  return s;
}
