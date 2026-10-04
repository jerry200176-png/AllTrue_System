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

// F15 (Sentry/Bugsnag-style breadcrumbs): the last buttons the user pressed and the last messages they saw,
// so a report answers "which button / what did the screen say" without asking the reporter.
// Labels only (no input values); runs of 4+ digits are masked; clicks inside the report dialog are skipped.
const MAX_CLICKS = 15;
const MAX_MESSAGES = 5;
const clicks = [];
const messages = [];
let uiInstalled = false;

const cleanText = (text, max) => String(text || '').replace(/\s+/g, ' ').trim().replace(/\d{4,}/g, '#').slice(0, max);

export function recordUserMessage(kind, text) {
  const t = cleanText(text, 120);
  if (!t) return;
  messages.push({ kind, text: t, at: new Date().toISOString() });
  if (messages.length > MAX_MESSAGES) messages.shift();
}

export function installUiRecorder() {
  if (uiInstalled || typeof document === 'undefined') return;
  uiInstalled = true;
  document.addEventListener('click', (event) => {
    try {
      const el = event.target?.closest?.('button, a, [role="button"], [role="tab"], [role="menuitem"], [role="option"], summary');
      if (!el || el.closest('.bug-launcher, .bug-report-dialog')) return;
      const label = cleanText(el.getAttribute('aria-label') || el.innerText || el.textContent || el.getAttribute('title'), 30);
      if (!label) return;
      clicks.push({ label, at: new Date().toISOString() });
      if (clicks.length > MAX_CLICKS) clicks.shift();
    } catch { /* recording must never break a click */ }
  }, true);
  for (const name of ['alert', 'confirm']) {
    const orig = window[name];
    if (typeof orig !== 'function') continue;
    window[name] = (msg, ...rest) => {
      recordUserMessage(name, msg);
      return orig.call(window, msg, ...rest);
    };
  }
}

export function getRecentClicks() {
  return clicks.map((c) => ({ ...c }));
}

export function getRecentMessages() {
  return messages.map((m) => ({ ...m }));
}

export function resetUiRecorderForTest() {
  clicks.length = 0;
  messages.length = 0;
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

/** JSON.stringify(info) that always parses and fits: shed clicks, messages, failures oldest-first, then relatedReference. */
export function fitClientInfo(info, max = CLIENT_INFO_MAX) {
  const o = {
    ...info,
    recentApiFailures: [...(info.recentApiFailures || [])],
    recentClicks: [...(info.recentClicks || [])],
    recentMessages: [...(info.recentMessages || [])],
  };
  let s = JSON.stringify(o);
  for (const key of ['recentClicks', 'recentMessages', 'recentApiFailures']) {
    while (s.length > max && o[key].length) {
      o[key].shift();
      s = JSON.stringify(o);
    }
  }
  if (s.length > max) {
    o.relatedReference = null;
    s = JSON.stringify(o);
  }
  return s;
}
