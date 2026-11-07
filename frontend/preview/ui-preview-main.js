// PR preview entry: boots the real app (src/main.js) exactly as production would.
// Data source: staging via the same-origin Pages Function when configured, otherwise
// in-browser fail-closed mocks. Either way nothing leaves for a third-party host.
import { previewResponse, sessionFor } from './previewApi.js';

window.__ALLTRUE_UI_PREVIEW__ = true;
const nativeFetch = window.fetch.bind(window);
const stub = (why) => new Response(JSON.stringify({ message: `preview: ${why}` }), { status: 503, headers: { 'Content-Type': 'application/json' } });

const cfg = await nativeFetch('/api/preview-config').then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
const proxied = cfg.proxy === true;

window.fetch = async (input, init = {}) => {
  const req = new Request(input, init);
  const url = new URL(req.url, location.href);
  if (url.origin !== location.origin) return stub('external host blocked');
  if (!url.pathname.startsWith('/api/v1/')) return nativeFetch(req);
  if (proxied) return nativeFetch(req); // Pages Function -> staging (secrets stay server-side)
  const hit = await previewResponse(url, req);
  return hit ? new Response(JSON.stringify(hit.body), { status: hit.status, headers: { 'Content-Type': 'application/json' } }) : stub('no mock');
};
window.XMLHttpRequest = class { open() { throw new Error('preview: XHR blocked'); } };
navigator.sendBeacon = () => false;
for (const n of ['WebSocket', 'EventSource']) window[n] = class { constructor() { throw new Error(`preview: ${n} blocked`); } };

// ?demo=director|teacher|parent: one-tap login, then clean URL so logout returns to Login.
const demo = new URLSearchParams(location.search).get('demo');
if (demo) {
  localStorage.removeItem('alltrue_session');
  localStorage.removeItem('parent_portal_token');
  let ok = false;
  if (proxied) {
    const r = await nativeFetch('/api/preview-demo-login', { method: 'POST', body: JSON.stringify({ role: demo }) }).catch(() => null);
    const j = r && r.ok ? await r.json().catch(() => null) : null;
    if (j?.session) { localStorage.setItem('alltrue_session', JSON.stringify(j.session)); ok = true; }
    if (j?.token) { localStorage.setItem('parent_portal_token', j.token); ok = true; }
  } else if (demo === 'parent') {
    localStorage.setItem('parent_portal_token', 'preview-parent-token'); ok = true;
  } else if (sessionFor(demo)?.user) {
    localStorage.setItem('alltrue_session', JSON.stringify(sessionFor(demo))); ok = true;
  }
  history.replaceState(null, '', ok && demo === 'parent' ? '/?parent=1' : '/');
}

// Small fixed pill so nobody mistakes this for production.
const pill = document.createElement('div');
pill.textContent = '預覽・假資料';
pill.style.cssText = 'position:fixed;left:8px;bottom:8px;z-index:2147483647;padding:2px 8px;border-radius:999px;background:#E65100;color:#fff;font:12px/1.6 system-ui,sans-serif;opacity:.9;pointer-events:none';
document.body.appendChild(pill);

// Three one-tap demo buttons under the real login form.
new MutationObserver(() => {
  const card = document.querySelector('.login-card');
  if (!card || card.querySelector('.pv-demo')) return;
  const box = document.createElement('div');
  box.className = 'pv-demo';
  box.style.cssText = 'display:flex;gap:8px;margin-top:16px';
  for (const [key, label] of [['director', '主任'], ['teacher', '老師'], ['parent', '家長']]) {
    const a = document.createElement('a');
    a.href = `/?demo=${key}`;
    a.textContent = label;
    a.style.cssText = 'flex:1;min-height:44px;display:flex;align-items:center;justify-content:center;border:1px solid #ccc;border-radius:10px;text-decoration:none;color:inherit;font-weight:600';
    box.appendChild(a);
  }
  card.appendChild(box);
}).observe(document.body, { childList: true, subtree: true });

await import('../src/main.js');
