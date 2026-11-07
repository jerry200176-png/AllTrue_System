// PR preview entry. Fake data only. Never imported by the production build.
// Fail-closed: every request is answered in-browser by the shared Playwright mocks
// or by a stub error; nothing here ever reaches a real API host.
import { installApiMocks } from '../e2e/fixtures/ui-foundation/apiMocks.js';

window.__ALLTRUE_UI_PREVIEW__ = true;
const params = new URLSearchParams(location.search);
const screen = params.get('page') || 'director';
const handlers = [];
const stubError = (why) => new Response(JSON.stringify({ message: `preview: ${why}` }), {
  status: 503,
  headers: { 'Content-Type': 'application/json' },
});

await installApiMocks({ route: (_glob, h) => handlers.push(h) }, screen === 'director' ? 'dashboard' : 'normal', screen);

window.fetch = async (input, init = {}) => {
  const req = new Request(input, init);
  const url = new URL(req.url, location.href);
  if (url.origin !== location.origin) return stubError('external host blocked');
  if (!url.pathname.includes('/api/v1/')) {
    if (url.pathname.includes('/api/')) return stubError('no mock');
    return stubError('static fetch blocked'); // ponytail: allow same-origin assets if a screen ever needs them
  }
  let out = null;
  const route = {
    request: () => ({ url: () => url.href, method: () => req.method, headers: () => Object.fromEntries(req.headers) }),
    fulfill: ({ status = 200, body = '' }) => { out = new Response(body, { status, headers: { 'Content-Type': 'application/json' } }); },
    unmocked: () => { out = stubError('no mock'); },
  };
  for (const h of handlers) await h(route);
  return out || stubError('no mock');
};
window.XMLHttpRequest = class { open() { throw new Error('preview: XHR blocked'); } };
navigator.sendBeacon = () => false;
window.WebSocket = class { constructor() { throw new Error('preview: WebSocket blocked'); } };
window.EventSource = class { constructor() { throw new Error('preview: EventSource blocked'); } };

if (screen === 'login') {
  localStorage.removeItem('alltrue_session');
  const [{ createApp }] = await Promise.all([import('vue'), import('../src/styles.css'), import('../src/ui.css')]);
  const Login = (await import('../src/pages/Login.vue')).default;
  createApp(Login).mount('#app');
} else {
  if (screen === 'parent') localStorage.setItem('parent_portal_token', 'preview-parent-token');
  await import('../e2e/fixtures/ui-foundation/pilot-mount.js');
}
document.documentElement.dataset.previewReady = '1';
